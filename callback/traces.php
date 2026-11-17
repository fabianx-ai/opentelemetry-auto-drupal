<?php

/**
 * @file
 * Callback endpoint for browser spans.
 *
 * Receives OTLP/JSON trace data from the browser (navigator.sendBeacon),
 * validates and sanitizes it, and forwards it to the OTLP endpoint the PHP
 * SDK is configured with. See docs/browser-tracing.md.
 *
 * The script is standalone on purpose: it does not bootstrap Drupal and does
 * not load the Composer autoloader, so a beacon costs a few milliseconds and
 * is never traced itself. Route a same-origin URL to it, for example
 * POST /otel/v1/traces.
 *
 * Configuration (environment):
 * - OTEL_EXPORTER_OTLP_TRACES_ENDPOINT or OTEL_EXPORTER_OTLP_ENDPOINT:
 *   where spans are forwarded to (OTLP/HTTP).
 * - OTEL_EXPORTER_OTLP_HEADERS: extra headers for the forwarded request.
 * - OTEL_BROWSER_SERVICE_NAME: service.name of the browser spans. Defaults
 *   to "<OTEL_SERVICE_NAME>-browser".
 * - OTEL_SDK_DISABLED=true: accept and drop everything.
 */

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal\Callback;

const MAX_BODY_BYTES = 262144;
const MAX_SPANS = 1000;
const MAX_ATTRIBUTES = 64;
const MAX_EVENTS = 32;
const MAX_LINKS = 8;
const MAX_NAME_LENGTH = 256;
const MAX_KEY_LENGTH = 128;
const MAX_VALUE_LENGTH = 2048;
// A page can stay open for a long time before it reports.
const MAX_AGE_SECONDS = 86400;
const MAX_FUTURE_SECONDS = 300;
const FORWARD_TIMEOUT_SECONDS = 2.0;

/**
 * Thrown for requests that are rejected; the code is the HTTP status.
 */
final class Rejected extends \RuntimeException {}

/**
 * Sends the response and, where possible, releases the browser.
 */
function respond(int $status, string $message = ''): void {
  http_response_code($status);
  header('Cache-Control: no-store');
  if ($message !== '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $message, "\n";
  }
  if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
  }
}

/**
 * Only same-origin browser requests are accepted.
 */
function checkRequest(array $server): void {
  if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    throw new Rejected('Only POST is supported.', 405);
  }
  // Browsers send Origin on beacons. A missing header (curl, tests) is
  // accepted; a header naming another site is not.
  $origin = $server['HTTP_ORIGIN'] ?? '';
  if ($origin !== '') {
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    $origin_host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $origin_port = parse_url($origin, PHP_URL_PORT);
    if ($origin_port !== NULL && $origin_port !== FALSE) {
      $origin_host .= ':' . $origin_port;
    }
    if ($origin_host === '' || ($origin_host !== $host && $origin_host !== preg_replace('/:\d+$/', '', $host))) {
      throw new Rejected('Cross-origin requests are not accepted.', 403);
    }
  }
  if (($server['HTTP_SEC_FETCH_SITE'] ?? 'same-origin') !== 'same-origin') {
    throw new Rejected('Cross-origin requests are not accepted.', 403);
  }
  if ((int) ($server['CONTENT_LENGTH'] ?? 0) > MAX_BODY_BYTES) {
    throw new Rejected('Payload too large.', 413);
  }
}

/**
 * Reads and decodes the request body.
 */
function readBody(): array {
  $body = file_get_contents('php://input', FALSE, NULL, 0, MAX_BODY_BYTES + 1);
  if ($body === FALSE || $body === '') {
    throw new Rejected('Empty request body.', 400);
  }
  if (strlen($body) > MAX_BODY_BYTES) {
    throw new Rejected('Payload too large.', 413);
  }
  $data = json_decode($body, TRUE, 32);
  if (!is_array($data) || !isset($data['resourceSpans']) || !is_array($data['resourceSpans'])) {
    throw new Rejected('Expected OTLP/JSON trace data.', 400);
  }
  return $data;
}

/**
 * Returns a lower-case hex ID of the given length, or NULL.
 */
function hexId(mixed $value, int $length): ?string {
  if (!is_string($value) || strlen($value) !== $length || !ctype_xdigit($value)) {
    return NULL;
  }
  $value = strtolower($value);
  // All zeros is the invalid ID in W3C Trace Context.
  return trim($value, '0') === '' ? NULL : $value;
}

/**
 * Returns a nanosecond timestamp as a digit string, or NULL.
 */
function nanos(mixed $value, int $now): ?string {
  if (is_int($value)) {
    $value = (string) $value;
  }
  if (!is_string($value) || $value === '' || strlen($value) > 20 || !ctype_digit($value)) {
    return NULL;
  }
  $seconds = (int) substr($value, 0, -9);
  if ($seconds < $now - MAX_AGE_SECONDS || $seconds > $now + MAX_FUTURE_SECONDS) {
    return NULL;
  }
  return $value;
}

/**
 * Cuts a string to a number of bytes without breaking UTF-8.
 */
function cut(string $value, int $length): string {
  return strlen($value) <= $length ? $value : mb_strcut($value, 0, $length, 'UTF-8');
}

/**
 * Keeps scalar attributes only, with bounded keys and values.
 */
function attributes(mixed $attributes): array {
  $clean = [];
  if (!is_array($attributes)) {
    return $clean;
  }
  foreach ($attributes as $attribute) {
    if (count($clean) >= MAX_ATTRIBUTES) {
      break;
    }
    $key = $attribute['key'] ?? NULL;
    $value = $attribute['value'] ?? NULL;
    if (!is_string($key) || $key === '' || !is_array($value)) {
      continue;
    }
    // The resource and the server decide these, not the page.
    if ($key === 'service.name' || str_starts_with($key, 'service.')) {
      continue;
    }
    if (isset($value['stringValue']) && is_string($value['stringValue'])) {
      $value = ['stringValue' => cut($value['stringValue'], MAX_VALUE_LENGTH)];
    }
    elseif (isset($value['boolValue']) && is_bool($value['boolValue'])) {
      $value = ['boolValue' => $value['boolValue']];
    }
    elseif (isset($value['intValue']) && (is_int($value['intValue']) || (is_string($value['intValue']) && preg_match('/^-?\d{1,19}$/', $value['intValue'])))) {
      $value = ['intValue' => (string) $value['intValue']];
    }
    elseif (isset($value['doubleValue']) && (is_float($value['doubleValue']) || is_int($value['doubleValue']))) {
      $value = ['doubleValue' => (float) $value['doubleValue']];
    }
    else {
      continue;
    }
    $clean[] = ['key' => cut($key, MAX_KEY_LENGTH), 'value' => $value];
  }
  return $clean;
}

/**
 * Rebuilds one span from the known fields; returns NULL when it is invalid.
 */
function span(mixed $span, int $now): ?array {
  if (!is_array($span)) {
    return NULL;
  }
  $trace_id = hexId($span['traceId'] ?? NULL, 32);
  $span_id = hexId($span['spanId'] ?? NULL, 16);
  $start = nanos($span['startTimeUnixNano'] ?? NULL, $now);
  $end = nanos($span['endTimeUnixNano'] ?? NULL, $now);
  $name = $span['name'] ?? NULL;
  if ($trace_id === NULL || $span_id === NULL || $start === NULL || $end === NULL || !is_string($name) || $name === '') {
    return NULL;
  }
  if (strlen($end) < strlen($start) || (strlen($end) === strlen($start) && strcmp($end, $start) < 0)) {
    return NULL;
  }

  $clean = [
    'traceId' => $trace_id,
    'spanId' => $span_id,
    'name' => cut($name, MAX_NAME_LENGTH),
    // A browser only produces internal (1) and client (3) spans.
    'kind' => ($span['kind'] ?? 1) === 3 ? 3 : 1,
    'startTimeUnixNano' => $start,
    'endTimeUnixNano' => $end,
    'attributes' => attributes($span['attributes'] ?? NULL),
  ];

  if (isset($span['parentSpanId']) && $span['parentSpanId'] !== '') {
    $parent = hexId($span['parentSpanId'], 16);
    if ($parent === NULL) {
      return NULL;
    }
    $clean['parentSpanId'] = $parent;
  }

  foreach (array_slice(is_array($span['events'] ?? NULL) ? $span['events'] : [], 0, MAX_EVENTS) as $event) {
    $time = nanos($event['timeUnixNano'] ?? NULL, $now);
    if ($time !== NULL && is_string($event['name'] ?? NULL) && $event['name'] !== '') {
      $clean['events'][] = [
        'timeUnixNano' => $time,
        'name' => cut($event['name'], MAX_NAME_LENGTH),
        'attributes' => attributes($event['attributes'] ?? NULL),
      ];
    }
  }

  foreach (array_slice(is_array($span['links'] ?? NULL) ? $span['links'] : [], 0, MAX_LINKS) as $link) {
    $link_trace = hexId($link['traceId'] ?? NULL, 32);
    $link_span = hexId($link['spanId'] ?? NULL, 16);
    if ($link_trace !== NULL && $link_span !== NULL) {
      $clean['links'][] = [
        'traceId' => $link_trace,
        'spanId' => $link_span,
        'attributes' => attributes($link['attributes'] ?? NULL),
      ];
    }
  }

  if (is_array($span['status'] ?? NULL) && in_array($span['status']['code'] ?? NULL, [1, 2], TRUE)) {
    $clean['status'] = ['code' => $span['status']['code']];
    if (is_string($span['status']['message'] ?? NULL)) {
      $clean['status']['message'] = cut($span['status']['message'], MAX_VALUE_LENGTH);
    }
  }

  return $clean;
}

/**
 * Rebuilds the payload: one resource owned by the server, clean spans.
 *
 * @return array{0: array, 1: int, 2: int}
 *   The payload, the number of spans kept and the number dropped.
 */
function sanitize(array $data, array $server, int $now): array {
  $spans = [];
  $dropped = 0;
  $scope = 'browser';
  foreach ($data['resourceSpans'] as $resource_spans) {
    foreach (is_array($resource_spans['scopeSpans'] ?? NULL) ? $resource_spans['scopeSpans'] : [] as $scope_spans) {
      if (is_string($scope_spans['scope']['name'] ?? NULL) && $scope_spans['scope']['name'] !== '') {
        $scope = cut($scope_spans['scope']['name'], MAX_NAME_LENGTH);
      }
      foreach (is_array($scope_spans['spans'] ?? NULL) ? $scope_spans['spans'] : [] as $span) {
        $clean = count($spans) < MAX_SPANS ? span($span, $now) : NULL;
        if ($clean === NULL) {
          $dropped++;
          continue;
        }
        $spans[] = $clean;
      }
    }
  }

  // The resource is never taken from the page: a visitor must not be able
  // to write spans under the name of another service.
  $resource = [
    ['key' => 'service.name', 'value' => ['stringValue' => serviceName()]],
    ['key' => 'telemetry.sdk.language', 'value' => ['stringValue' => 'webjs']],
  ];
  if (is_string($server['HTTP_USER_AGENT'] ?? NULL) && $server['HTTP_USER_AGENT'] !== '') {
    $resource[] = ['key' => 'user_agent.original', 'value' => ['stringValue' => cut($server['HTTP_USER_AGENT'], 512)]];
  }

  $payload = [
    'resourceSpans' => [
      [
        'resource' => ['attributes' => $resource],
        'scopeSpans' => [['scope' => ['name' => $scope], 'spans' => $spans]],
      ],
    ],
  ];
  return [$payload, count($spans), $dropped];
}

/**
 * The service name browser spans are stored under.
 */
function serviceName(): string {
  $name = getenv('OTEL_BROWSER_SERVICE_NAME');
  if (is_string($name) && $name !== '') {
    return $name;
  }
  $service = getenv('OTEL_SERVICE_NAME');
  return (is_string($service) && $service !== '' ? $service : 'drupal') . '-browser';
}

/**
 * The OTLP/HTTP traces URL of the SDK configuration, or NULL.
 */
function endpoint(): ?string {
  $traces = getenv('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT');
  if (is_string($traces) && $traces !== '') {
    return $traces;
  }
  $base = getenv('OTEL_EXPORTER_OTLP_ENDPOINT');
  if (is_string($base) && $base !== '') {
    return rtrim($base, '/') . '/v1/traces';
  }
  return NULL;
}

/**
 * Forwards the payload as OTLP/JSON; returns whether it was accepted.
 */
function forward(string $endpoint, array $payload): bool {
  $headers = ['Content-Type: application/json'];
  foreach (explode(',', (string) getenv('OTEL_EXPORTER_OTLP_HEADERS')) as $pair) {
    if (str_contains($pair, '=')) {
      [$name, $value] = explode('=', $pair, 2);
      $headers[] = trim($name) . ': ' . rawurldecode(trim($value));
    }
  }
  $context = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => implode("\r\n", $headers),
      'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
      'timeout' => FORWARD_TIMEOUT_SECONDS,
      'ignore_errors' => TRUE,
    ],
  ]);
  $result = @file_get_contents($endpoint, FALSE, $context);
  $status = isset($http_response_header[0]) ? (int) (explode(' ', $http_response_header[0])[1] ?? 0) : 0;
  if ($result === FALSE || $status < 200 || $status >= 300) {
    error_log(sprintf('OpenTelemetry browser callback: forwarding to %s failed (HTTP %d).', $endpoint, $status));
    return FALSE;
  }
  return TRUE;
}

/**
 * Handles the request.
 */
function main(array $server): void {
  try {
    checkRequest($server);
    $data = readBody();
  }
  catch (Rejected $rejected) {
    respond($rejected->getCode(), $rejected->getMessage());
    return;
  }

  [$payload, $kept, $dropped] = sanitize($data, $server, time());
  if ($kept === 0) {
    respond(400, 'No valid spans.');
    return;
  }

  $endpoint = endpoint();
  if (strtolower((string) getenv('OTEL_SDK_DISABLED')) === 'true' || $endpoint === NULL) {
    respond(204);
    return;
  }

  // Answer first: the browser does not wait for the collector.
  header('X-Spans-Accepted: ' . $kept);
  header('X-Spans-Dropped: ' . $dropped);
  respond(204);
  forward($endpoint, $payload);
}

// Allow tests to include the file without handling a request.
if (PHP_SAPI !== 'cli' || getenv('OTEL_BROWSER_CALLBACK_RUN')) {
  main($_SERVER);
}
