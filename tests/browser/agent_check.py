#!/usr/bin/env python3
"""Checks browser tracing end to end in headless Chromium.

Loads pages of a site that has browser tracing enabled, lets the browser
agent report, and verifies what it sent and, through the Jaeger query API,
what ended up in the trace backend.

    python agent_check.py https://example.ddev.site --insecure \\
        --edge https://example.ddev.site:8443 \\
        --loopback http://127.0.0.1:32768

See README.md in this directory.
"""

import argparse
import json
import secrets
import ssl
import sys
import time
import urllib.request

from playwright.sync_api import sync_playwright

# Keeps a copy of every beacon body in the page: the body of a Blob beacon
# is not available to the test otherwise.
INIT = """
window.__beacons = [];
const send = navigator.sendBeacon.bind(navigator);
navigator.sendBeacon = (url, data) => {
  data.text().then(text => window.__beacons.push(text));
  return send(url, data);
};
"""

# Beacons sent while a page unloads cannot be observed: hide the page.
HIDE = """() => {
  Object.defineProperty(document, 'visibilityState', {value: 'hidden', configurable: true});
  document.dispatchEvent(new Event('visibilitychange'));
}"""

NAVIGATION = """() => {
  const nav = performance.getEntriesByType('navigation')[0];
  const timing = {};
  nav.serverTiming.forEach(entry => { timing[entry.name] = entry.description; });
  return {type: nav.type, transferSize: nav.transferSize, timing};
}"""

XHR = """url => new Promise(resolve => {
  const xhr = new XMLHttpRequest();
  xhr.open('GET', url);
  xhr.onloadend = () => resolve(xhr.status);
  xhr.send();
})"""

results = []


def check(name, ok, detail=''):
    results.append(ok)
    print(('  ok    ' if ok else '  FAIL  ') + name + (f' ({detail})' if detail and not ok else ''))


def skip(name, reason):
    print(f'  skip  {name} ({reason})')


def attributes(span):
    return {a['key']: list(a['value'].values())[0] for a in span.get('attributes', [])}


def named(spans, name):
    return [s for s in spans if s['name'] == name]


class View:
    """One page view: what the server issued and what the agent sent."""

    def __init__(self, page, statuses):
        self.navigation = page.evaluate(NAVIGATION)
        page.evaluate(HIDE)
        page.wait_for_timeout(1000)
        self.spans = []
        for body in page.evaluate('window.__beacons'):
            for resource in json.loads(body)['resourceSpans']:
                for scope in resource['scopeSpans']:
                    self.spans.extend(scope['spans'])
        self.statuses = list(statuses)
        statuses.clear()
        issued = self.navigation['timing'].get('traceparent', '').split('-')
        self.issued_trace = issued[1] if len(issued) == 4 else None
        self.issued_span = issued[2] if len(issued) == 4 else None
        fetch = named(self.spans, 'documentFetch')
        self.fetch = fetch[0] if fetch else None
        self.trace = self.fetch['traceId'] if fetch else None
        self.transaction = attributes(self.fetch).get('browser.transaction') if fetch else None


class Backend:
    """Reads traces through the Jaeger query API."""

    def __init__(self, url, insecure):
        self.url = url.rstrip('/')
        self.context = ssl._create_unverified_context() if insecure else None

    def trace(self, trace_id, services=1, timeout=20):
        """Waits until the trace has spans of the given number of services."""
        deadline = time.time() + timeout
        trace = None
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(f'{self.url}/api/traces/{trace_id}', context=self.context) as response:
                    data = json.load(response).get('data') or []
            except Exception:
                data = []
            if data:
                trace = data[0]
                if len({p['serviceName'] for p in trace['processes'].values()}) >= services:
                    return trace
            time.sleep(1)
        return trace


def parent(span):
    for reference in span['references']:
        if reference['refType'] == 'CHILD_OF':
            return reference['spanID']
    return None


def check_joined_trace(backend, view, calls):
    trace = backend.trace(view.trace, services=2)
    if not trace:
        check('trace is in the backend', False, view.trace)
        return
    spans = {s['spanID']: s for s in trace['spans']}
    service = lambda s: trace['processes'][s['processID']]['serviceName']
    is_browser = lambda s: service(s).endswith('-browser')
    roots = [s for s in spans.values() if parent(s) not in spans]
    check('trace has one root, the page load', len(roots) == 1 and roots[0]['operationName'].startswith('pageload'),
          [s['operationName'] for s in roots])
    under = lambda name: [s for s in spans.values() if not is_browser(s) and parent(s) in spans
                          and is_browser(spans[parent(s)]) and spans[parent(s)]['operationName'] == name]
    server = under('documentFetch')
    check('server request span is the child of documentFetch', len(server) == 1, len(server))
    if server:
        fetch = spans[parent(server[0])]
        inside = fetch['startTime'] <= server[0]['startTime'] and \
            server[0]['startTime'] + server[0]['duration'] <= fetch['startTime'] + fetch['duration']
        check('server request span lies inside documentFetch in time', inside)
    for call in calls:
        check(f'server span below the browser span "{call}"', len(under(call)) >= 1)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('base', help='URL of the site, e.g. https://example.ddev.site')
    parser.add_argument('--path', default='/user/login', help='page to load (default: %(default)s)')
    parser.add_argument('--other-path', default='/user/password', help='second page, for back navigation')
    parser.add_argument('--call', default='/session/token', help='same-origin URL the page requests with fetch and XMLHttpRequest')
    parser.add_argument('--edge', help='URL of the site through an edge cache, e.g. https://example.ddev.site:8443')
    parser.add_argument('--loopback', help='http://127.0.0.1:<port> URL of the site, to test the browser cache')
    parser.add_argument('--jaeger', help='Jaeger query URL (default: <base>:16686)')
    parser.add_argument('--no-backend', action='store_true', help='only check what the browser sends')
    parser.add_argument('--insecure', action='store_true', help='accept untrusted certificates')
    args = parser.parse_args()

    base = args.base.rstrip('/')
    backend = None if args.no_backend else Backend(args.jaeger or f'{base}:16686', args.insecure)

    with sync_playwright() as playwright:
        browser = playwright.chromium.launch()

        def new_page():
            page = browser.new_context(ignore_https_errors=args.insecure).new_page()
            page.add_init_script(INIT)
            statuses, errors = [], []
            page.on('response', lambda r: statuses.append(r.status) if '/otel/v1/traces' in r.url else None)
            page.on('pageerror', lambda e: errors.append(str(e)))
            return page, statuses, errors

        print('Page view')
        page, statuses, errors = new_page()
        page.goto(base + args.path, wait_until='load')
        page.wait_for_timeout(1000)
        fetch_status = page.evaluate('url => fetch(url).then(r => r.status)', args.call)
        xhr_status = page.evaluate(XHR, args.call)
        first = View(page, statuses)
        check('server issued a transaction', first.issued_trace is not None, first.navigation['timing'])
        check('agent sent one beacon, accepted with 204', first.statuses == [204], first.statuses)
        check('transaction is fresh', first.transaction == 'fresh', first.transaction)
        check('documentFetch has the issued trace and span ID',
              first.fetch is not None and first.trace == first.issued_trace and first.fetch['spanId'] == first.issued_span)
        root = [s for s in first.spans if s['name'].startswith('pageload')]
        vitals = attributes(root[0]) if root else {}
        check('page load carries TTFB and FCP', 'browser.web_vital.ttfb' in vitals and 'browser.web_vital.fcp' in vitals, sorted(vitals))
        call = 'GET ' + args.call
        check('fetch and XMLHttpRequest are recorded', fetch_status == 200 and xhr_status == 200 and len(named(first.spans, call)) == 2,
              (fetch_status, xhr_status, len(named(first.spans, call))))
        check('no JavaScript errors', not errors, errors)
        if backend and first.trace:
            check_joined_trace(backend, first, [call])

        print('Reload')
        page.reload(wait_until='load')
        reload = View(page, statuses)
        check('reload gets a new transaction and completes it',
              reload.transaction == 'fresh' and reload.trace == reload.issued_trace and reload.trace != first.trace,
              (reload.transaction, reload.trace, reload.issued_trace))

        print('Edge cache')
        if not args.edge:
            skip('edge cache hit', 'no --edge URL')
        else:
            url = args.edge.rstrip('/') + args.path + '?otel-check=' + secrets.token_hex(4)
            page, statuses, errors = new_page()
            page.goto(url, wait_until='load')
            fill = View(page, statuses)
            check('edge miss: transaction is fresh', fill.navigation['timing'].get('edge') == 'MISS' and fill.transaction == 'fresh',
                  (fill.navigation['timing'].get('edge'), fill.transaction))
            page, statuses, errors = new_page()
            page.goto(url, wait_until='load')
            hit = View(page, statuses)
            if hit.navigation['timing'].get('edge') != 'HIT':
                skip('edge hit', 'the edge did not cache the page; anonymous pages need a page cache maximum age')
            else:
                links = (hit.fetch or {}).get('links') or []
                check('edge hit replays the transaction of the miss', hit.issued_trace == fill.issued_trace)
                check('edge hit: transaction is stale, new trace ID', hit.transaction == 'stale' and hit.trace not in (None, hit.issued_trace),
                      (hit.transaction, hit.trace))
                check('edge hit: link to the trace that rendered the page', len(links) == 1 and links[0]['traceId'] == hit.issued_trace)
                check('edge hit: beacon accepted with 204', hit.statuses == [204], hit.statuses)
                if backend and hit.trace:
                    trace = backend.trace(hit.trace)
                    services = {p['serviceName'] for p in trace['processes'].values()} if trace else set()
                    check('edge hit: trace has browser spans only', bool(services) and all(s.endswith('-browser') for s in services), services)

        print('Browser cache')
        if not args.loopback:
            skip('back from the browser cache', 'no --loopback URL')
        else:
            loopback = args.loopback.rstrip('/')
            page, statuses, errors = new_page()
            page.goto(loopback + args.path, wait_until='load')
            before = View(page, statuses)
            page.goto(loopback + args.other_path, wait_until='load')
            page.go_back(wait_until='load')
            back = View(page, statuses)
            if back.navigation['transferSize'] != 0:
                skip('back from the browser cache', 'the browser requested the page again')
                check('back with a new request: fresh transaction', back.transaction == 'fresh' and back.trace == back.issued_trace)
            else:
                links = (back.fetch or {}).get('links') or []
                check('back from the cache replays the earlier transaction', back.issued_trace == before.issued_trace)
                check('back from the cache: transaction is stale, new trace ID',
                      back.transaction == 'stale' and back.trace not in (None, back.issued_trace), (back.transaction, back.trace))
                check('back from the cache: link to the earlier trace', len(links) == 1 and links[0]['traceId'] == back.issued_trace)

        browser.close()

    failed = results.count(False)
    print(f'\n{len(results) - failed} passed, {failed} failed')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
