<?php

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\DrupalKernel;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\AutoRootSpan;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use function OpenTelemetry\Instrumentation\hook;

/**
 *
 */
class DrupalAutoRootSpan {

  /**
   * Whether a root span has been created for this process.
   */
  protected static bool $rootSpanCreated = FALSE;

  /**
   *
   */
  public static function register(): void {

    hook(
      DrupalKernel::class,
      '__construct',
      static::registerAutoRootSpan(),
      NULL,
    );

    // Drush defers its exit() to the very end of the shutdown functions,
    // which is before the deferred shutdown handlers of the SDK run: the
    // root span would never end and the last batch of spans would never be
    // exported. End and flush as soon as the Drush runtime returns instead.
    hook(
      'Drush\\Runtime\\Runtime',
      'run',
      NULL,
      static function ($runtime, array $params, $returnValue, ?\Throwable $exception) {
        if (!static::$rootSpanCreated) {
          return;
        }
        AutoRootSpan::shutdownHandler();
        $tracerProvider = Globals::tracerProvider();
        if ($tracerProvider instanceof TracerProviderInterface) {
          $tracerProvider->forceFlush();
        }
      },
    );
  }

  /**
   * @param \OpenTelemetry\API\Instrumentation\CachedInstrumentation $instrumentation
   *
   * @return \Closure
   */
  public static function registerAutoRootSpan(): \Closure {
    return static function (DrupalKernel $kernel, array $params, string $class, string $function, ?string $filename, ?int $lineno) {
      $request = AutoRootSpan::createRequest();

      if ($request) {
        $request = BrowserTransaction::issue($request);
        AutoRootSpan::create($request);
        AutoRootSpan::registerShutdownHandler();
        static::$rootSpanCreated = TRUE;

        if (PHP_SAPI === 'cli') {
          // Drush and other scripts: name the root span after the command
          // instead of the "GET /" of the request emulated from globals.
          $argv = $_SERVER['argv'] ?? [];
          $script = basename((string) array_shift($argv), '.php');
          $command = current(array_filter($argv, static fn ($arg) => is_string($arg) && $arg !== '' && $arg[0] !== '-'));
          Span::getCurrent()->updateName(trim($script . ' ' . $command));
        }
      }
    };
  }

}
