# Browser-level tracing

How a trace that starts in the browser is tied to the Drupal server trace:
how the server issues a tracing transaction, how the browser completes it
through a callback endpoint, how this behaves behind a CDN and the page
cache, and how the result reads in Grafana.

Status: design, with the load-bearing mechanisms tested on the ddev stack
(Drupal 11.4, PHP 8.3, ext-opentelemetry 1.2.1, Jaeger 1.76, Grafana 11.6,
Varnish 7.6). Each section says what was tested and what is still to build.
The server side is implemented: issuing the transaction
(`OTEL_PHP_DRUPAL_BROWSER_TRACING=true`) and the callback endpoint
(`callback/traces.php`) and the browser agent (`browser/agent.js`). The
agent has been run in headless Chromium only.

## The problem

A page navigation cannot carry a `traceparent` header: the browser has no
JavaScript running before the request leaves. So the server trace starts
without a parent, and everything the user actually waits for (network time,
the edge cache, asset loading, rendering, Web Vitals) is invisible.

## The design in one paragraph

On a navigation request the server allocates the IDs of a browser span that
does not exist yet and makes its own root span a child of it. It hands those
IDs to the browser in a response header. The browser later sends the missing
span, with exactly that ID, plus its own timing spans, to a same-origin
callback endpoint. When the page came out of a cache in front of Drupal,
there is no server work for this request, and the browser starts the trace
itself and links it to the trace that rendered the page.

```mermaid
sequenceDiagram
    participant B as Browser
    participant E as Edge (CDN / Varnish)
    participant D as Drupal (PHP + ext-opentelemetry)
    participant C as Callback /otel/v1/traces
    participant T as Trace backend (Jaeger / Tempo)

    B->>E: GET /node/1 (no traceparent)
    alt edge miss
        E->>D: GET /node/1
        Note over D: allocate trace_id + fetch_span_id<br/>server root span = child of fetch_span_id
        D-->>E: 200 + Server-Timing: traceparent (transaction)
        D->>T: server spans (OTLP)
        E-->>B: 200 + Server-Timing: …, edge;desc="MISS"
        Note over B: transaction is fresh: complete it
    else edge hit
        E-->>B: 200 + Server-Timing: replayed traceparent, edge;desc="HIT"
        Note over B: transaction is stale: new trace,<br/>link to the trace that rendered the page
    end
    B->>C: sendBeacon OTLP/JSON
    C->>T: browser spans (OTLP)
```

## 1. The timeline of one page view

In time, the trace reads in the order the request travels: edge, page cache,
Drupal, and then the browser's own work once the first byte has arrived.
The browser part after time to first byte (TTFB) is appended at the end.

Two browser spans start *before* that, because only the browser can measure
them: `pageload`, which spans the whole page view, and `documentFetch`,
which spans request start to the end of the response. The time between
`documentFetch` and the server span inside it is network and edge time,
which no server-side trace can show.

```
time ─────────────────────────────────────────────────────────────────▶
pageload /node/1          ├──────────────────────────────────────────────┤
  documentFetch (CLIENT)  ├──────────────┤          edge=MISS
    GET /node/1 (SERVER)      ├────────┤            drupal page_cache=MISS
      route, SQL, cache, IO    ├──────┤
                                         ▲ TTFB / response end
  resourceFetch drupal.js                 ├──┤
  resourceFetch style.css                 ├───┤
  fetch /history/1/read (CLIENT)               ├─┤   → own Drupal SERVER span
  Web Vitals (LCP, CLS, INP)                           attributes on pageload
```

Which parts exist depends on where the response came from:

| Served by | Server spans for this request | Transaction | Trace ID comes from |
|---|---|---|---|
| Edge cache hit (CDN / Varnish) | none | stale, replayed from the cached object | browser, with a link to the rendering trace |
| Browser cache, back/forward cache | none | stale | browser, with a link |
| Drupal page cache hit | short: root span and kernel span, marked as a page cache hit | fresh | server |
| Drupal, full render | complete | fresh | server |

In every case the browser part is the same; only what sits inside
`documentFetch` changes. `documentFetch` carries the cache outcome as
attributes (`http.cache.edge`, `drupal.page_cache`), so "slow page, edge
miss, page cache miss" can be queried directly.

BigPipe is not covered yet. The response keeps streaming after the first
byte while Drupal renders placeholders, so the server span runs past TTFB.
It still ends inside `documentFetch`, so the trace stays well formed, but
showing each placeholder as its own unit on both sides needs extra support.
TODO.

## 2. The server issues the transaction

The transaction is the pair `(trace_id, fetch_span_id)`: 16 and 8 random
bytes, the same shape as a W3C `traceparent`. `fetch_span_id` is the ID the
browser must give its `documentFetch` span.

**When.** Only when the request has no `traceparent` of its own and looks
like a browser navigation (`Sec-Fetch-Dest: document`, or `Sec-Fetch-Mode:
navigate`). API clients, curl, bots without those headers and Drush keep
today's behaviour and get an ordinary root span. This matters because a
transaction that nobody completes leaves the server root pointing at a
parent that never arrives.

**How.** `BrowserTransaction::issue()` runs before the root span is created.
It generates the two IDs and adds them to the request the root span is
built from, as if the browser had sent
`traceparent: 00-<trace_id>-<fetch_span_id>-01`. The SDK's root span then
treats the not-yet-existing browser span as its remote parent. No SDK
changes are needed. It is opt-in: `OTEL_PHP_DRUPAL_BROWSER_TRACING=true`.

**Sampling.** The server decides. A parent that claims to be sampled would
make a parent-based sampler record every page view, so the configured
sampler is asked what it decides for the new trace ID and the flag of the
issued parent carries that answer. An unsampled request gets no
`Server-Timing` transaction, and the browser stays silent.

**Redirects.** A redirect response issues a transaction that nobody
completes; the browser only sees the header of the final response.

**Handing it to the browser.** A response header, set from the
`DrupalKernel::handle` post hook:

```
Server-Timing: traceparent;desc="00-<trace_id>-<fetch_span_id>-01",
               drupal-cache;desc="HIT|MISS",
               srv;desc="<root_start_unix_ms>";dur=<server_ms>
```

- `traceparent` is the transaction.
- `drupal-cache` repeats `X-Drupal-Cache`, which JavaScript cannot read on a
  navigation response. `Server-Timing` is the only response header it can.
- `srv` carries the server clock for the skew correction in section 5.

The browser reads all of it without any markup in the page:

```js
const nav = performance.getEntriesByType('navigation')[0];
const st = Object.fromEntries(nav.serverTiming.map(e => [e.name, e]));
const transaction = st.traceparent?.description;
```

Tested:

- A request with `Sec-Fetch-Dest: document` gets a transaction in
  `Server-Timing`; the server trace is stored under that trace ID with the
  issued span as parent of the root span, and the route span below the root.
- A request without `Sec-Fetch-Dest: document`, or one that carries its own
  `traceparent`, gets no transaction.
- The transaction is fresh on every response, also on Drupal page cache
  hits (`drupal-cache;desc="HIT"`, a new trace ID each time). The header is
  set outside the page cache middleware, so the page cache never stores it.
  The kernel span carries `drupal.page_cache`.
- Sampling: at a ratio of 0.3, 40 navigations issued 14 transactions, and
  exactly those 14 traces were stored.

- In headless Chromium, navigations get a transaction (the browser sends
  `Sec-Fetch-Dest: document` itself) and the page reads all metrics from
  `serverTiming`.

Not tested: Firefox and Safari. `serverTiming` needs a secure context
(HTTPS or localhost); Safari supports it from 16.4.

## 3. Caches in front of Drupal

Drupal's page cache needs nothing: Drupal still runs, issues a fresh
transaction, and the short server trace shows the hit.

An edge cache is different. It stores the response *including* the
`Server-Timing` header and replays it. Every visitor served from the cache
receives the transaction of the one request that rendered the page. If they
all completed it, one server trace would collect many browser roots with the
same span ID.

So the edge has to say what it did, in the one header the browser can read.
For Varnish:

```vcl
import std;

sub vcl_deliver {
    # Drupal or a module may send more than one Server-Timing line.
    std.collect(resp.http.Server-Timing, ", ");
    if (obj.hits > 0) {
        set resp.http.Server-Timing = resp.http.Server-Timing + {", edge;desc="HIT""};
    } else {
        set resp.http.Server-Timing = resp.http.Server-Timing + {", edge;desc="MISS""};
    }
}
```

The agent then applies one rule. The transaction is **fresh** only if none
of these is true:

- the edge reports a hit (`edge;desc="HIT"`),
- the document came from the browser's own cache (`deliveryType ===
  'cache'`, or `transferSize === 0`),
- as a fallback for an edge that cannot be configured: the server start time
  in `srv` is more than a minute older than the request.

The navigation type is deliberately not part of the rule. Going back or
reloading can mean a new request to the server, which then issues a new
transaction that must be completed, or a copy from the cache. What counts
is whether the response came over the network.

Fresh: complete the transaction (section 4). Stale: generate a new trace ID
in the browser, send the same spans under it, and attach a span link from
`documentFetch` to the replayed `traceparent`. The link answers "which
request rendered the page this visitor got, and how long did that take"
with one click, without pretending that work happened for this visitor.

The first visitor after a miss gets a fresh transaction and completes it;
everyone after them gets the link. Nothing has to be stripped at the edge,
and cached pages stay cacheable: the header does not vary the cache.

Commercial CDNs differ in how a cache status can be added to
`Server-Timing`; several can do it natively or in an edge rule. That is
configuration per CDN and has to be checked for the one in use. The
fallback rule covers a CDN where it is not possible.

The edge itself contributes no spans here, only its outcome. Real edge
spans would need the edge to export traces on its own, which is outside
this design.

Tested, with Varnish 7.6 in front of the ddev site:

- An edge hit replays the transaction: three requests returned the same
  trace ID, with `Age` rising, while Drupal was not reached.
- The VCL above marks them `edge;desc="MISS"`, then `HIT`, `HIT`, appended
  to Drupal's header.
- A browser-only trace with a span link to the rendering trace is accepted
  and stored with the link (shown as a reference to the other trace), and
  the rendering trace stays retrievable by its ID.

Tested in headless Chromium:

- A page served by Varnish from its cache (`edge;desc="HIT"`) is reported
  under a new trace ID with a link to the replayed transaction.
- Reload, and going back when the browser asks the server again: each gets
  a fresh transaction and completes it.
- Going back when the browser serves the page from its own cache
  (`transferSize` 0, `deliveryType` `cache`): the replayed transaction is
  recognised as stale and reported under a new trace ID with a link.

Not tested: any commercial CDN, and the timestamp fallback.

## 4. The browser completes it through the callback endpoint

**Endpoint.** `POST /otel/v1/traces` on the site's own origin, accepting
OTLP/HTTP with a JSON body. Same origin means no CORS, no third-party
host to allow in a content security policy, and no collector exposed under
its own public name. It must bypass the edge cache (it is a POST, which
caches do by default).

The endpoint is `callback/traces.php` in this package: one standalone file
that does not bootstrap Drupal and does not load the Composer autoloader, so
a beacon costs a few milliseconds and is never traced itself. It

- accepts only same-origin `POST` requests up to 256 KB,
- rebuilds every span from the known OTLP fields and drops what is invalid
  (IDs, timestamps older than a day or in the future, more than 1000 spans),
- bounds names, attribute counts and value sizes,
- sets the resource itself: `service.name` is `<OTEL_SERVICE_NAME>-browser`
  (or `OTEL_BROWSER_SERVICE_NAME`), whatever the page sent, so a visitor
  cannot write spans under the name of another service,
- answers `204` first and then forwards the cleaned payload to the OTLP
  endpoint the PHP SDK already uses (`OTEL_EXPORTER_OTLP_ENDPOINT`,
  `OTEL_EXPORTER_OTLP_HEADERS`).

It needs no configuration of its own and no change to the collector. The
web server only has to route the path to the file:

```nginx
location = /otel/v1/traces {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/vendor/mladenrtl/opentelemetry-auto-drupal/callback/traces.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    client_max_body_size 256k;
}
```

Where the web server cannot be configured, the file can be copied or
symlinked into the document root and the agent pointed at that URL. A plain
reverse proxy from the path to the collector also works, without the
checks above.

**Payload.** Standard OTLP/JSON, one resource with `service.name =
drupal-browser`:

| Span | Kind | ID | Parent |
|---|---|---|---|
| `pageload <path>` | INTERNAL | random, browser | none (trace root) |
| `documentFetch` | CLIENT | fresh: **`fetch_span_id` from the server**; stale: random | `pageload` |
| `resourceFetch <url>` | CLIENT | random | `pageload` |
| `fetch`/`XHR <path>` | CLIENT | random, sent as `traceparent` | `pageload` |

Timings come from Navigation Timing and Resource Timing. `documentFetch`
carries the connection phases (DNS, connect, TLS, wait, download) and the
cache outcome. Web Vitals (LCP, CLS, INP) go on the `pageload` span as
attributes, so they can be queried next to the server data.

**When to send.** Once, with `navigator.sendBeacon`, when the page is
hidden (`visibilitychange` → `hidden`, with `pagehide` as fallback). That is
the only point where LCP, CLS and INP are final. The cost is that the trace
stays incomplete while the tab is open; if that is too slow for debugging, a
first beacon after `load` plus a second one for the vitals is the variant.

**Later requests from the page.** The agent wraps `fetch` and
`XMLHttpRequest` and adds `traceparent` with the page's trace ID and a new
span ID. The server side needs nothing new: the package already extracts the
propagated context. The agent records each call as a CLIENT span and sends
it with the next beacon. Long-lived pages should start a new trace per
interaction after the initial load, or one trace grows without bound; the
agent does not do that yet.

**The agent.** `browser/agent.js`, one dependency-free file of about 13 KB
unminified. It needs no Drupal module:

- The file is served at `/otel/v1/agent.js`, next to the callback endpoint.
  Its URL carries a hash of its content, so it can be cached for a year.
- `BrowserAgent` hooks Drupal's attachment processing and adds one
  `<script async>` element to the head of every HTML page when
  `OTEL_PHP_DRUPAL_BROWSER_TRACING=true`.

The element is the same for every visitor, so it is safe inside page-cached
and edge-cached HTML; what differs per request stays in `Server-Timing`.
As an external same-origin script it passes a `script-src 'self'` content
security policy. The agent does nothing when the page has no sampled
transaction.

The OpenTelemetry JS SDK or Grafana Faro can take its place later; the
contract with the server (header in, OTLP out) does not change. A Drupal
module becomes useful when decisions have to be made inside Drupal: only
some roles or routes, or tying BigPipe placeholders and `Drupal.ajax` to
spans.

Tested:

- A span posted as OTLP/JSON to the same-origin path, carrying the trace ID
  and the pre-allocated span ID, is accepted and joins the existing server
  trace after the fact.
- The callback script rejects other methods (405), invalid JSON or
  non-OTLP bodies (400), spans with bad IDs or stale timestamps (400), a
  foreign `Origin` (403) and oversized bodies (413). A payload claiming
  `service.name = drupal` with a server span kind and a 5000 character
  attribute was stored as `drupal-browser`, internal, cut to 2048
  characters. Of one valid and one invalid span, the valid one is kept.
- The joined trace has one root, and the chain reads
  `SQL query ← cache get ← route ← GET /path ← documentFetch ← pageload`
  across both services.

Tested in headless Chromium (Playwright, Chrome 159):

- A page view sends one beacon, answered with 204. The trace has the
  browser's `pageload` as its only root, Drupal's request span below
  `documentFetch`, and the server span lies inside `documentFetch` in time
  after the clock correction.
- A `fetch` and an `XMLHttpRequest` made by the page each produce a CLIENT
  span in the browser and Drupal's server span below it, in the same trace.
- TTFB, FCP, LCP and CLS arrive as attributes of `pageload`.
- No JavaScript errors on anonymous pages.

Not tested: INP (it needs a real interaction), pages with BigPipe
placeholders, Firefox and Safari, and how the element behaves on sites
whose themes or modules alter the HTML head in unusual ways. The script
element is added through a hook on an internal Drupal class
(`HtmlResponseAttachmentsProcessor`), which is the price of not needing a
module.

## 5. Details that decide whether it works in practice

**Clock skew.** Browser spans carry the visitor's clock, server spans the
server's. Jaeger corrects a child that falls outside its parent; Tempo shows
timestamps as sent. The agent should therefore shift its own spans onto the
server clock before sending. The server reports its root start and duration
(`srv`), the browser knows `requestStart` and `responseStart`, and the
offset is

```
offset = server_start − (requestStart + ((responseStart − requestStart) − server_ms) / 2)
```

This assumes the network time is split evenly between request and response.
On a stale transaction there is no server span to align with, and the
browser clock is used as is.

**Abuse of the endpoint.** It is unauthenticated by nature: anyone can post
spans. The callback script covers the shape of the data (size, counts,
service name, same origin). It does not limit how often a client posts;
that is a rate limit at the web server. If more is needed, the transaction
can be signed: the header carries `HMAC(trace_id, fetch_span_id, expiry)`
and the script verifies it before forwarding. Signing does not cover stale
transactions, where the browser picks the trace ID.

**Privacy.** Span attributes carry URLs. Query strings and fragments should
be stripped in the agent, and no user identifier is sent.

**Transactions nobody completes.** Blocked JavaScript, closed tabs and
crawlers that send `Sec-Fetch-*` leave a server root whose parent never
arrives. The trace is still fully usable; Jaeger marks it with an "invalid
parent span ID" warning and Tempo shows it as a trace without a root span.

## 6. How it reads in Grafana

Grafana only needs a trace data source. Both services write to the same
backend under the same trace ID, so one trace view shows the whole request
in the order of section 1: browser spans around and after, Drupal inside
`documentFetch`, each service in its own colour.

- **By trace ID.** The ID is in the `Server-Timing` header of the page, so a
  developer can copy it from the browser's network tab straight into
  Explore.
- **Cached pages.** A trace for an edge hit has no Drupal spans; the link on
  `documentFetch` opens the trace of the request that rendered the page.
- **By search.** With Tempo, TraceQL covers both sides in one query, for
  example slow pages that missed both caches:
  `{ resource.service.name = "drupal-browser" && span.http.cache.edge = "MISS" && span.browser.web_vital.lcp > 2500 }`
- **Service graph.** `documentFetch` is a CLIENT span and its child is
  Drupal's SERVER span, which is the pair Tempo's service graph is built
  from. This is the reason the server issues the ID of `documentFetch`
  rather than of the page-load root.
- **Dashboards.** Web Vitals and cache outcome as span attributes can be
  turned into metrics by the collector (span metrics) and linked back to
  example traces.

Tested: Grafana 11.6 in ddev with a provisioned Jaeger data source returns
the joined trace (one root, both services) and the linked trace of the edge
hit case. Checked through Grafana's data source API, not by looking at the
UI.

Not tested: Tempo, TraceQL, the service graph and dashboards. The client's
trace backend behind Grafana is not known yet.

## 7. Environments

| | ddev | Client (Grafana on premise) |
|---|---|---|
| PHP → traces | OTLP/HTTP to `jaeger:4318` | OTLP to an OpenTelemetry Collector or Grafana Alloy |
| Callback `/otel/v1/traces` | nginx snippet → `callback/traces.php` → `jaeger:4318` | web server route → `callback/traces.php` → the same collector |
| Edge cache | optional Varnish on `:8443` | CDN / Varnish, with the cache status in `Server-Timing` |
| Storage | Jaeger all-in-one, in memory | Tempo or Jaeger, to be confirmed |
| UI | Jaeger `:16686`, Grafana `:3000` | Grafana |

The application side is identical in both: the same environment variables,
the same header, the same callback path. Only where the path is forwarded
to differs.

ddev files, in [`examples/ddev`](../examples/ddev):

- `.ddev/web-build/Dockerfile.opentelemetry`: the PHP extension
- `.ddev/config.otel.yaml`: `OTEL_*` environment
- `.ddev/docker-compose.jaeger.yaml`: Jaeger with OTLP enabled
- `.ddev/docker-compose.grafana.yaml`, `.ddev/grafana/datasources.yaml`:
  Grafana with the Jaeger data source, anonymous admin
- `.ddev/docker-compose.varnish.yaml`, `.ddev/varnish/default.vcl`: Varnish
  in front of the site on port 8443; the normal URL bypasses it
- `.ddev/nginx/otel-callback.conf`: routes the callback path to
  `callback/traces.php`
- `browser-trace-demo.sh`: runs the fresh-transaction flow with curl and
  prints the Grafana and Jaeger links for the resulting trace

## 8. What is left to build

1. Agent: other browsers, a new trace per interaction on long-lived pages,
   a minified build.
2. Edge configuration for the CDN or Varnish in use (cache status in
   `Server-Timing`).
3. Endpoint hardening for production: a rate limit at the web server, and
   signature verification in the callback script if required.
4. Grafana: data source, a Web Vitals dashboard, links from panels to
   traces.
5. BigPipe support.

## Open decisions

- **Trace backend at the client.** Tempo or Jaeger behind Grafana decides
  the query language and whether the service graph is available.
- **Which edge.** Varnish, a commercial CDN, or both, and whether a cache
  status can be added to `Server-Timing` there.
- **Signed transactions.** Whether the callback script's checks plus a rate
  limit are enough, or it must also verify an HMAC.
- **Beacon timing.** The agent sends when the page is hidden (complete
  vitals, late trace). An earlier first beacon after `load` would make
  traces appear sooner while a tab stays open.
