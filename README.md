# OpenTelemetry Drupal auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for Drupal framework applications.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)

## Overview
The following features are supported:
* root span creation (Drupal core hooks)
* context propagation
* HttpClient client span creation
* HttpClient context propagation
* Message Bus span creation
* Message Transport span creation

## Installation via composer

```bash
$ composer require mladenrtl/opentelemetry-auto-drupal
```

## Installing dependencies and executing tests

From Drupal subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration
The extension can be disabled via runtime configuration:

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=drupal
```

The file and curl IO spans (`fopen`, `fwrite`, `file_get_contents`,
`curl_exec`, ...) are verbose and can be disabled on their own:

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=io
```

IO spans are only created below an active span, so IO performed outside of
a Drupal request does not produce single-span traces.

### Browser tracing

```shell
OTEL_PHP_DRUPAL_BROWSER_TRACING=true
```

For page navigations (`Sec-Fetch-Dest: document`) that carry no trace
context, the server issues a tracing transaction: the root span becomes the
child of a browser span that the browser sends later, and the IDs are
handed to the browser in the `Server-Timing` response header:

```
Server-Timing: traceparent;desc="00-<trace id>-<span id>-01", drupal-cache;desc="MISS", srv;desc="<start in ms>";dur=<ms>
```

Every HTML page also gets a script element for the browser agent
(`browser/agent.js`), which measures the page view and sends its spans to
`callback/traces.php`. No Drupal module is needed. See
[docs/browser-tracing.md](docs/browser-tracing.md) for the design and
[examples/ddev](examples/ddev) for a complete local setup.

### Query Explain Threshold Configuration

The EXPLAIN threshold feature allows you to set a minimum duration threshold for capturing EXPLAIN results in your traces. When `OTEL_PHP_DRUPAL_EXPLAIN_QUERIES` is enabled, you can use `OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD` to specify the minimum query duration (in milliseconds) that should trigger EXPLAIN capture.

For example:
```
OTEL_PHP_DRUPAL_EXPLAIN_THRESHOLD=100  # Capture EXPLAIN for queries taking longer than 100ms
```
