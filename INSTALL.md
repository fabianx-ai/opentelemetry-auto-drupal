# Installation

This guide sets up the whole stack: Drupal with the OpenTelemetry PHP
extension and this package, a trace backend, Grafana, and optionally
browser-level tracing with an edge cache in front.

There are two paths:

- [Quick start with ddev](#quick-start-with-ddev): everything on a laptop
  in about ten minutes.
- [Installing on a server](#installing-on-a-server): the same pieces, one
  by one, for any environment.

Tested with Drupal 11.4, PHP 8.3, ext-opentelemetry 1.2.1, Jaeger 1.76,
Grafana 11.6 and Varnish 7.6. PHP 8.2 or later is required. Other
combinations, Drupal 10 included, have not been tested.

Until the package is released, it is installed from this repository:

```
https://github.com/fabianx-ai/opentelemetry-auto-drupal
branch: drupal-instrumentation--mega-pack--io--otel-1.2
```

## Quick start with ddev

Requirements: [ddev](https://ddev.com) 1.23 or later, with Docker running.

### 1. A Drupal project

Skip this step for an existing ddev project that uses nginx-fpm.

```bash
mkdir drupal-otel && cd drupal-otel
ddev config --project-type=drupal11 --docroot=web --php-version=8.3
ddev start
ddev composer create-project 'drupal/recommended-project:^11'
ddev composer require drush/drush
ddev drush site:install standard --account-pass=admin -y
```

### 2. The ddev configuration

Copy the example configuration into the project. It adds the PHP
extension, Jaeger, Grafana, Varnish, the `OTEL_*` environment and the
callback endpoint; see [examples/ddev](examples/ddev/README.md) for what
each file does, and leave out what is not needed.

```bash
git clone -b drupal-instrumentation--mega-pack--io--otel-1.2 \
  https://github.com/fabianx-ai/opentelemetry-auto-drupal /tmp/otel-drupal
cp -R /tmp/otel-drupal/examples/ddev/.ddev/. .ddev/
ddev restart
```

The restart rebuilds the web image with the extension. Check it:

```bash
ddev exec php --ri opentelemetry
```

This must print `opentelemetry hooks => enabled`. Do this before the next
step: Composer refuses to install the package without the extension.

### 3. The package

```bash
ddev composer config repositories.otel-drupal vcs https://github.com/fabianx-ai/opentelemetry-auto-drupal
ddev composer config --no-plugins allow-plugins.php-http/discovery true
ddev composer require \
  'mladenrtl/opentelemetry-auto-drupal:dev-drupal-instrumentation--mega-pack--io--otel-1.2' \
  open-telemetry/exporter-otlp
```

Nothing has to be enabled in Drupal. The package registers itself through
the Composer autoloader.

### 4. See a trace

Open the site, click around, then open Jaeger and pick the service
`drupal`:

| What | URL |
|---|---|
| Site | `https://drupal-otel.ddev.site` |
| Jaeger | `https://drupal-otel.ddev.site:16686` |
| Grafana | `https://drupal-otel.ddev.site:3000` (Explore, data source Jaeger) |
| Site through Varnish | `https://drupal-otel.ddev.site:8443` |

Each page view is one trace: the request, the route, views, entity
operations, SQL queries, cache bins, HTTP client calls and file IO. Drush
commands are traced as well (`ddev drush cron` gives a trace named
`drush cron`).

### 5. Browser tracing

The example configuration already enables it. To see the whole flow
without a browser:

```bash
BASE=https://drupal-otel.ddev.site /tmp/otel-drupal/examples/ddev/browser-trace-demo.sh /user/login
```

The script loads a page the way a browser navigation would, reads the
tracing transaction from the `Server-Timing` response header, sends
browser spans to the callback endpoint, and prints the Grafana and Jaeger
links of the resulting trace. In that trace the browser's `pageload` span
is the root and Drupal's request sits below `documentFetch`.

For Varnish to cache pages for anonymous visitors, Drupal needs a page
cache maximum age:

```bash
ddev drush config:set system.performance cache.page.max_age 300 -y
```

Jaeger keeps traces in memory: a `ddev restart` clears them.

## Installing on a server

### 1. The PHP extension

The package hooks into Drupal through the
[opentelemetry extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation).
Version 1.1 or later is required.

On Debian and Ubuntu with the [deb.sury.org](https://deb.sury.org) PHP
packages:

```bash
apt-get install php8.3-opentelemetry
```

Elsewhere, through PECL:

```bash
pecl install opentelemetry
echo "extension=opentelemetry.so" > /etc/php/8.3/mods-available/opentelemetry.ini
phpenmod opentelemetry
```

Restart PHP-FPM or the web server, then check both the command line and
the web server PHP:

```bash
php --ri opentelemetry
```

Only the Debian package route has been tested for this guide.

### 2. The package

```bash
composer config repositories.otel-drupal vcs https://github.com/fabianx-ai/opentelemetry-auto-drupal
composer config --no-plugins allow-plugins.php-http/discovery true
composer require \
  'mladenrtl/opentelemetry-auto-drupal:dev-drupal-instrumentation--mega-pack--io--otel-1.2' \
  open-telemetry/exporter-otlp
```

The project's `minimum-stability` can stay `stable`. A package of the same
name on Packagist is the upstream this repository was forked from; the
`repositories` entry makes Composer use this one.

### 3. A trace backend

Anything that accepts OTLP over HTTP works: an OpenTelemetry Collector,
Grafana Alloy, Grafana Tempo, or Jaeger. For a first test, Jaeger in one
container is enough:

```bash
docker run -d --name jaeger -e COLLECTOR_OTLP_ENABLED=true \
  -p 16686:16686 -p 4318:4318 jaegertracing/all-in-one:1.76.0
```

Its interface is on port 16686, the OTLP endpoint on port 4318.

### 4. Configuration

The SDK and this package are configured through environment variables.
They have to reach PHP in every way it runs:

- **PHP-FPM** clears the environment by default. Either set
  `clear_env = no` in the pool configuration, or list the variables there:

  ```ini
  ; /etc/php/8.3/fpm/pool.d/www.conf
  env[OTEL_PHP_AUTOLOAD_ENABLED] = true
  env[OTEL_SERVICE_NAME] = drupal
  env[OTEL_TRACES_EXPORTER] = otlp
  env[OTEL_METRICS_EXPORTER] = none
  env[OTEL_LOGS_EXPORTER] = none
  env[OTEL_EXPORTER_OTLP_PROTOCOL] = http/protobuf
  env[OTEL_EXPORTER_OTLP_ENDPOINT] = http://collector.example:4318
  env[OTEL_PROPAGATORS] = baggage,tracecontext
  ```

- **Apache with mod_php**: `SetEnv` in the virtual host.
- **Drush and cron**: the same variables in the shell or the crontab.

In containers, setting them on the container is usually enough; that is
what the ddev example does. Only the container route has been tested for
this guide.

See the [configuration reference](#configuration-reference) for all
variables.

### 5. Check

Load a page and look for the service `drupal` in the backend. If nothing
arrives, see [Troubleshooting](#troubleshooting).

## Configuration reference

Standard OpenTelemetry variables this setup relies on:

| Variable | Value | Purpose |
|---|---|---|
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` | Required. Lets the SDK configure itself from the environment. |
| `OTEL_SERVICE_NAME` | e.g. `drupal` | Name of the service in the backend. |
| `OTEL_TRACES_EXPORTER` | `otlp` | Send traces over OTLP. |
| `OTEL_METRICS_EXPORTER`, `OTEL_LOGS_EXPORTER` | `none` | This package produces traces only. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | OTLP over HTTP. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | e.g. `http://collector:4318` | Where traces are sent. |
| `OTEL_EXPORTER_OTLP_HEADERS` | `key=value,...` | Extra headers, for example authentication. |
| `OTEL_PROPAGATORS` | `baggage,tracecontext` | Accept and send W3C trace context. |
| `OTEL_TRACES_SAMPLER`, `OTEL_TRACES_SAMPLER_ARG` | e.g. `parentbased_traceidratio`, `0.1` | Trace a share of the requests. Default: all. |
| `OTEL_SDK_DISABLED` | `true` | Switch everything off. |

Variables of this package:

| Variable | Default | Purpose |
|---|---|---|
| `OTEL_PHP_DISABLED_INSTRUMENTATIONS` | none | `drupal` disables the package; `io` disables only the file and curl spans. Comma separated. |
| `OTEL_PHP_DRUPAL_EXPLAIN_QUERIES` | off | Any non-empty value attaches `EXPLAIN` output to query spans. Runs an extra query per query; for debugging. |
| `OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD` | `0` | Milliseconds; see the README. |
| `OTEL_PHP_DRUPAL_BROWSER_TRACING` | off | `true` issues tracing transactions for page navigations in the `Server-Timing` header. |
| `OTEL_BROWSER_SERVICE_NAME` | `<OTEL_SERVICE_NAME>-browser` | Service name of browser spans received by the callback endpoint. |

A page view produces several hundred to a few thousand spans, most of them
cache and file operations. On a busy site, disable `io`, sample, or both.

## Browser tracing

Optional. It ties what the browser measures (network, edge cache, assets,
Web Vitals) to the server trace. The design, with what has been tested, is
in [docs/browser-tracing.md](docs/browser-tracing.md). Three pieces are
set up on the server; the fourth, the browser agent, is not available yet.

**1. Issue transactions.** Set `OTEL_PHP_DRUPAL_BROWSER_TRACING=true`.
Page navigations then receive:

```
Server-Timing: traceparent;desc="00-<trace id>-<span id>-01", drupal-cache;desc="MISS", srv;desc="<start ms>";dur=<ms>
```

Check with:

```bash
curl -sI -H 'Sec-Fetch-Dest: document' https://example.com/ | grep -i server-timing
```

Without the `Sec-Fetch-Dest` header there is no transaction; that is
intended, see the design document.

**2. Route the callback endpoint.** The browser posts its spans to
`/otel/v1/traces` on the site's own origin. Route that path to
`callback/traces.php` of this package. For nginx:

```nginx
location = /otel/v1/traces {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/html/vendor/mladenrtl/opentelemetry-auto-drupal/callback/traces.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    client_max_body_size 256k;
}
```

Adjust the two paths to the server. The script reads
`OTEL_EXPORTER_OTLP_ENDPOINT` and needs no configuration of its own. It
does not limit how often a client posts; add a rate limit at the web
server for production. Only nginx has been tested.

Check with a request it must reject:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/otel/v1/traces   # 405
```

**3. Tell the browser about the edge cache.** A CDN or Varnish that caches
pages replays the `Server-Timing` header of the request that rendered
them. It has to add its cache status to the header so that the browser can
tell. For Varnish, see
[examples/ddev/.ddev/varnish/default.vcl](examples/ddev/.ddev/varnish/default.vcl).
For a commercial CDN this is vendor-specific and has not been tested.
Drupal's own page cache needs nothing.

## Grafana

Grafana needs a trace data source pointing at the backend: Tempo, or
Jaeger as in the ddev example
([datasources.yaml](examples/ddev/.ddev/grafana/datasources.yaml)). Open
Explore, choose the data source, and search by service or paste a trace
ID. With browser tracing on, the trace ID of any page is in its
`Server-Timing` response header in the browser's network tab.

Only the Jaeger data source has been tested.

## Troubleshooting

**No traces at all.**
Check `php --ri opentelemetry` for the PHP that serves the site, not only
the command line. Check that the variables reach PHP:
`OTEL_PHP_AUTOLOAD_ENABLED=true` is the one most often missing under
PHP-FPM. Check that the endpoint is reachable from the PHP host.

**Composer cannot resolve the package.**
`ext-opentelemetry` missing: install the extension first. A complaint
about `minimum-stability` or `symfony/http-client 6.4.x-dev`: Composer is
using the upstream package from Packagist; add the `repositories` entry.

**`Warning: OpenTelemetry: pre hook invalid signature`.**
An older version of this package with ext-opentelemetry 1.1 or later.
Update the package.

**Traces from Drush are missing or incomplete.**
Same cause: update the package. The variables must also be set in the
shell that runs Drush.

**No `Server-Timing` header.**
`OTEL_PHP_DRUPAL_BROWSER_TRACING` not set for the web server PHP; the
request has no `Sec-Fetch-Dest: document` header (curl does not send it);
the request already carries a `traceparent`; or the request was not
sampled.

**The callback endpoint returns 404 or the Drupal page.**
The web server does not route the path to `callback/traces.php`; the
request ends up in Drupal.

**Too many spans.**
`OTEL_PHP_DISABLED_INSTRUMENTATIONS=io` removes the file and curl spans.
`OTEL_TRACES_SAMPLER=parentbased_traceidratio` with
`OTEL_TRACES_SAMPLER_ARG=0.1` traces one request in ten.

**Traces disappeared.**
Jaeger all-in-one stores them in memory; restarting it clears them.
