# Browser check

`agent_check.py` loads pages of a site in headless Chromium and verifies
browser tracing end to end: what the server issues, what the browser agent
sends to the callback endpoint, and what the trace looks like in the
backend. It exits with status 1 when a check fails.

## Setup

Python 3.8 or later:

```bash
python3 -m venv venv
venv/bin/pip install playwright
venv/bin/playwright install --with-deps chromium
```

The site needs browser tracing enabled and the callback endpoint and the
agent routed, see [INSTALL.md](../../INSTALL.md). The ddev example does all
of that.

## Running

```bash
venv/bin/python agent_check.py https://example.ddev.site --insecure
```

| Option | Purpose |
|---|---|
| `--insecure` | Accept untrusted certificates, as ddev's are for a headless browser. |
| `--edge URL` | The site through an edge cache (`https://example.ddev.site:8443` in the ddev example). Adds the edge cache checks. The page must be cacheable there: `drush config:set system.performance cache.page.max_age 300`. |
| `--loopback URL` | The site as `http://127.0.0.1:<port>`. Adds the browser cache check: Chromium does not use its HTTP cache for sites with an untrusted certificate, but it does for loopback addresses. With ddev the port is shown by `ddev describe` (web, port 80). |
| `--jaeger URL` | Jaeger query URL, default `<base>:16686`. |
| `--no-backend` | Skip the trace checks and only verify what the browser sends. |
| `--path`, `--other-path`, `--call` | The page to load, a second page for back navigation, and a same-origin URL requested with `fetch` and `XMLHttpRequest`. The defaults exist on a standard Drupal installation. |

## What is checked

- **Page view:** the server issues a transaction; the agent sends one beacon
  that is accepted; `documentFetch` carries the issued IDs; Web Vitals are
  present; `fetch` and `XMLHttpRequest` calls are recorded; the trace in the
  backend has the page load as its only root, the server request below
  `documentFetch` and inside it in time, and a server span below each call.
- **Reload:** a new transaction, completed.
- **Edge cache:** a miss completes its transaction; a hit replays it and is
  reported under a new trace ID with a link, with browser spans only.
- **Browser cache:** going back to a page served from the browser's cache is
  reported under a new trace ID with a link.

Only Chromium is used. Firefox and Safari are not covered.
