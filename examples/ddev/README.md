# ddev example

A complete local setup for a Drupal project that uses ddev (nginx-fpm):
the PHP extension, a trace backend, Grafana, an optional edge cache and the
callback endpoint for browser spans.

Copy the contents of `.ddev/` into the `.ddev/` directory of the project,
then:

```bash
ddev restart
ddev composer require mladenrtl/opentelemetry-auto-drupal open-telemetry/exporter-otlp
```

| File | Purpose |
|---|---|
| `web-build/Dockerfile.opentelemetry` | Installs the `opentelemetry` PHP extension into the web image |
| `config.otel.yaml` | `OTEL_*` environment: service name, OTLP exporter to Jaeger |
| `docker-compose.jaeger.yaml` | Jaeger all-in-one with OTLP enabled, UI on port 16686 |
| `docker-compose.grafana.yaml`, `grafana/datasources.yaml` | Grafana on port 3000 with the Jaeger data source, anonymous admin |
| `docker-compose.varnish.yaml`, `varnish/default.vcl` | Optional Varnish in front of the site on port 8443, reporting its cache status in `Server-Timing` |
| `nginx/otel-callback.conf` | Routes `POST /otel/v1/traces` to `callback/traces.php` of this package |

Each piece is independent; leave out Grafana or Varnish if they are not
needed. Jaeger keeps traces in memory, so a `ddev restart` clears them.

`browser-trace-demo.sh` runs the browser-tracing flow from
[docs/browser-tracing.md](../../docs/browser-tracing.md) with curl and
prints the Grafana and Jaeger links of the resulting trace:

```bash
BASE=https://example.ddev.site ./browser-trace-demo.sh /user/login
```

For Varnish to cache anonymous pages, Drupal needs a page cache maximum
age: `ddev drush config:set system.performance cache.page.max_age 300`.
