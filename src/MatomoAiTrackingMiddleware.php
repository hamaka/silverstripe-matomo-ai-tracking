<?php

namespace Hamaka\MatomoAiTracking;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * Sends requests from AI chatbots to Matomo AI Insights (see MatomoTracker).
 *
 * Must run before any full-page cache middleware, otherwise cached hits are missed (see _config/config.yml).
 * Files the webserver serves directly (e.g. existing files in assets/) never reach PHP and are not seen.
 * The hit is sent after the response has been delivered (shutdown), so the bot notices as little as possible.
 */
class MatomoAiTrackingMiddleware implements HTTPMiddleware
{
    use Injectable;

    public function process(HTTPRequest $request, callable $delegate)
    {
        // capture before routing: controllers may rewrite the request URL (e.g. '' => 'home')
        $userAgent  = (string)$request->getHeader('User-Agent');
        $urlAndVars = (string)$request->getURL(true);
        $start      = microtime(true);

        $response = $delegate($request);

        try {
            $hit = $this->buildHit($userAgent, $urlAndVars, $response, microtime(true) - $start);
            if ($hit) {
                $this->queueHit($hit);
            }
        } catch (Throwable $e) {
            // tracking must never break the response
            Injector::inst()->get(LoggerInterface::class)->warning('MatomoAiTracking: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * @param string $urlAndVars relative URL including query string, as returned by HTTPRequest::getURL(true)
     * @return array{timestamp:int, path:string, isDownload:bool, status:int, bytes:int, ua:string, responseTimeMs:?int}|null
     *         null when tracking is disabled, the user agent is not an AI chatbot, or the path is excluded.
     */
    public function buildHit(string $userAgent, string $urlAndVars, ?HTTPResponse $response, float $durationSeconds): ?array
    {
        if (!$response || !MatomoTracker::isEnabled()) {
            return null;
        }

        $detector = AiBotDetector::create();
        if (!$detector->isChatbot($userAgent)) {
            return null;
        }

        $pathAndQuery = '/' . ltrim($urlAndVars, '/');
        $path         = (string)parse_url($pathAndQuery, PHP_URL_PATH);
        if ($detector->isExcludedPath($path)) {
            return null;
        }

        return [
            'timestamp'      => time(),
            'path'           => $pathAndQuery,
            'isDownload'     => $detector->isDownloadPath($path),
            'status'         => $response->getStatusCode(),
            'bytes'          => $this->getResponseBytes($response),
            'ua'             => $userAgent,
            'responseTimeMs' => (int)round($durationSeconds * 1000),
        ];
    }

    /**
     * Send only after the response has been delivered. Under PHP-FPM fastcgi_finish_request() closes the
     * connection to the bot first; under mod_php the connection waits for the (short) Matomo call.
     */
    protected function queueHit(array $hit): void
    {
        register_shutdown_function(function () use ($hit) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            MatomoTracker::create()->track($hit);
        });
    }

    protected function getResponseBytes(HTTPResponse $response): int
    {
        $contentLength = $response->getHeader('Content-Length');
        if ($contentLength !== null && $contentLength !== '') {
            return (int)$contentLength;
        }

        // getBody() on a stream response reads the whole stream into memory; skip it
        if ($response instanceof HTTPStreamResponse) {
            return 0;
        }

        return strlen((string)$response->getBody());
    }
}
