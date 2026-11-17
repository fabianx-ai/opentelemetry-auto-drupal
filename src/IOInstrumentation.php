<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;

class IOInstrumentation extends InstrumentationBase {
  public const NAME = 'io';

  /**
   * One entry per IO call in flight: TRUE when no span was created for it.
   *
   * @var bool[]
   */
  protected array $skipped = [];

  /**
   * {@inheritdoc}
   *
   * IO functions are called long before (and after) Drupal handles the
   * request, e.g. by Composer, Drush or the span exporter itself. Without an
   * active span each of those calls would become a trace of its own, so IO
   * spans are only created as children of an existing span.
   */
  protected function preHook(
    string $operation,
    array $resolvedParamMap = [],
    ?callable $customHandler = NULL,
  ): callable {
    $inner = parent::preHook($operation, $resolvedParamMap, $customHandler);

    return function (
      $object,
      array $params,
      ?string $class,
      string $function,
      ?string $filename,
      ?int $lineno,
    ) use ($inner): void {
      $skip = !Span::getCurrent()->getContext()->isValid();
      $this->skipped[] = $skip;
      if (!$skip) {
        $inner($object, $params, $class, $function, $filename, $lineno);
      }
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function postHook(
    string $operation,
    ?string $resultAttribute = NULL,
    ?callable $customHandler = NULL,
  ): callable {
    $inner = $this->traitPostHook($operation, $resultAttribute, $customHandler);

    return function (
      $object,
      array $params,
      $returnValue,
      ?\Throwable $exception,
    ) use ($inner): void {
      if (!array_pop($this->skipped)) {
        $inner($object, $params, $returnValue, $exception);
      }
    };
  }

  /**
   *
   */
  public static function register(): void {

    $instrumentation = new CachedInstrumentation(
      'io.opentelemetry.contrib.php.io',
      null,
      'https://opentelemetry.io/schemas/1.24.0'
    );

    static::create(
      instrumentation: $instrumentation,
      prefix: 'drupal.io',
    );
  }

  /**
   *
   */
  protected function registerInstrumentation(): void {

    // Define operations.
    $operations = [
      'fopen' => [
        'params' => ['filename', 'mode'],
        'returnValue' => 'stream',
      ],
      'fwrite' => [
        'params' => ['stream'],
        'returnValue' => 'count',
      ],
      'fread' => [
        'params' => ['stream'],
      ],
      'fclose' => [
        'params' => ['stream'],
        'returnValue' => 'success',
      ],
      'file_get_contents' => [
        'params' => ['filename'],
      ],
      'file_put_contents' => [
        'params' => ['filename'],
      ],
      'curl_init' => [
        'params' => ['url'],
      ],
      'curl_exec' => [],
    ];

    // Register all operations with common params handling.
    $this->registerOperations(
      operations: $operations,
    );
  }
}
