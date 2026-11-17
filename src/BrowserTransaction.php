<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues the tracing transaction a browser completes later.
 *
 * A page navigation cannot carry a traceparent header. For such a request
 * the IDs of a browser span that does not exist yet are allocated here and
 * used as the remote parent of the root span. The browser receives them in
 * the Server-Timing response header and sends the missing span to the
 * callback endpoint afterwards.
 *
 * @see docs/browser-tracing.md
 */
final class BrowserTransaction {

  /**
   * Environment variable that enables browser transactions.
   */
  public const ENABLED = 'OTEL_PHP_DRUPAL_BROWSER_TRACING';

  /**
   * The traceparent issued for this request, if it is sampled.
   */
  private static ?string $traceparent = NULL;

  /**
   * Whether browser transactions are enabled.
   */
  public static function isEnabled(): bool {
    return filter_var(getenv(self::ENABLED), FILTER_VALIDATE_BOOLEAN);
  }

  /**
   * Issues a transaction for a navigation request without trace context.
   *
   * @param \Psr\Http\Message\ServerRequestInterface $request
   *   The request the root span is about to be created for.
   *
   * @return \Psr\Http\Message\ServerRequestInterface
   *   The request, carrying the issued traceparent header if there is one.
   */
  public static function issue(ServerRequestInterface $request): ServerRequestInterface {
    self::$traceparent = NULL;

    if (PHP_SAPI === 'cli' || !self::isEnabled()) {
      return $request;
    }
    // A request that is already part of a trace keeps its parent.
    if ($request->hasHeader('traceparent')) {
      return $request;
    }
    // Only browsers loading a page can complete a transaction. Without
    // this check API clients, curl and most bots would be given a parent
    // span that never arrives.
    if ($request->getHeaderLine('Sec-Fetch-Dest') !== 'document') {
      return $request;
    }

    $traceId = bin2hex(random_bytes(16));
    $spanId = bin2hex(random_bytes(8));
    // The sampling decision stays with the configured sampler: the flag of
    // the issued parent carries what it would decide for this trace ID.
    $sampled = self::isSampled($traceId);
    $traceparent = sprintf('00-%s-%s-%s', $traceId, $spanId, $sampled ? '01' : '00');
    if ($sampled) {
      self::$traceparent = $traceparent;
    }

    return $request->withHeader('traceparent', $traceparent);
  }

  /**
   * Adds the transaction to the Server-Timing header of the response.
   *
   * Server-Timing is the only header of a navigation response that
   * JavaScript can read, and unlike markup it is not stored by the page
   * cache: this runs after the page cache middleware.
   */
  public static function addServerTiming(Response $response): void {
    if (self::$traceparent === NULL) {
      return;
    }

    $metrics = [sprintf('traceparent;desc="%s"', self::$traceparent)];

    // X-Drupal-Cache itself is not readable on a navigation response.
    $pageCache = (string) $response->headers->get('X-Drupal-Cache');
    if (preg_match('/^[A-Za-z]+$/', $pageCache)) {
      $metrics[] = sprintf('drupal-cache;desc="%s"', $pageCache);
    }

    // The server clock, so that the browser can align its own spans.
    $start = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(TRUE));
    $metrics[] = sprintf('srv;desc="%d";dur=%.1F', (int) round($start * 1000), (microtime(TRUE) - $start) * 1000);

    $response->headers->set('Server-Timing', implode(', ', $metrics), FALSE);
  }

  /**
   * Asks the configured sampler what it decides for a new trace.
   */
  private static function isSampled(string $traceId): bool {
    $tracerProvider = Globals::tracerProvider();
    if (!$tracerProvider instanceof TracerProvider) {
      return TRUE;
    }
    $result = $tracerProvider->getSampler()->shouldSample(
      Context::getRoot(),
      $traceId,
      'browser',
      SpanKind::KIND_SERVER,
      Attributes::create([]),
      [],
    );
    return $result->getDecision() === SamplingResult::RECORD_AND_SAMPLE;
  }

}
