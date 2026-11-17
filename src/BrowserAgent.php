<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Drupal;

use Drupal\Core\Render\HtmlResponse;
use function OpenTelemetry\Instrumentation\hook;

/**
 * Adds the browser agent to HTML pages.
 *
 * The agent is one script element in the head of the page. It is the same
 * for every visitor, so it is safe in cached pages: what differs per
 * request is in the Server-Timing header.
 *
 * @see \OpenTelemetry\Contrib\Instrumentation\Drupal\BrowserTransaction
 * @see docs/browser-tracing.md
 */
final class BrowserAgent {

  /**
   * Path below the site root that is routed to this package.
   */
  public const PATH = '/otel/v1';

  /**
   * Whether the agent has been added to a response of this request.
   */
  private static bool $attached = FALSE;

  /**
   * Registers the hook that attaches the agent.
   */
  public static function register(): void {
    if (!BrowserTransaction::isEnabled()) {
      return;
    }

    hook(
      'Drupal\\Core\\Render\\HtmlResponseAttachmentsProcessor',
      'processAttachments',
      static function ($processor, array $params) {
        $response = $params[0] ?? NULL;
        // BigPipe processes the attachments of every placeholder as well:
        // the page itself comes first.
        if (self::$attached || !$response instanceof HtmlResponse) {
          return;
        }
        self::$attached = TRUE;

        $attachments = $response->getAttachments();
        $attachments['html_head'][] = [
          [
            '#tag' => 'script',
            '#attributes' => [
              'src' => self::basePath() . self::PATH . '/agent.js?v=' . self::version(),
              'async' => TRUE,
              'data-endpoint' => self::basePath() . self::PATH . '/traces',
            ],
          ],
          'opentelemetry_browser_agent',
        ];
        $response->setAttachments($attachments);
      },
      NULL,
    );
  }

  /**
   * The base path of the site, without trailing slash.
   */
  private static function basePath(): string {
    return \Drupal::hasRequest() ? \Drupal::request()->getBasePath() : '';
  }

  /**
   * Changes whenever the agent changes, so that it can be cached for long.
   */
  private static function version(): string {
    static $version;
    return $version ??= substr((string) @md5_file(dirname(__DIR__) . '/browser/agent.js'), 0, 8);
  }

}
