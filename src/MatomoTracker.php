<?php

namespace Hamaka\MatomoAiTracking;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * Sends AI chatbot hits to Matomo via the HTTP Tracking API in "bot only" mode (recMode=1).
 * These bots don't execute JavaScript, so the regular tracking code never sees them.
 * Results show up in Matomo under "AI Insights".
 *
 * Configured through environment variables:
 * - MATOMO_AI_TRACKING_ENABLED   "1" to enable tracking
 * - MATOMO_AI_TRACKING_URL       Matomo base URL, e.g. https://stats.example.com
 * - MATOMO_AI_TRACKING_SITE_ID   Matomo site ID
 * - MATOMO_AI_TRACKING_SITE_URL  (optional) public URL of the site, defaults to Director::absoluteBaseURL() of the request
 */
class MatomoTracker
{
    use Configurable;
    use Injectable;

    /** Keep this short: the call happens after the response, but under mod_php it keeps the PHP worker busy. */
    private static float $timeout_seconds = 2.0;

    private static float $connect_timeout_seconds = 1.0;

    private static string $source_label = 'SilverStripe';

    protected string $matomoUrl;
    protected int $siteId;

    public function __construct()
    {
        $this->matomoUrl = rtrim((string)Environment::getEnv('MATOMO_AI_TRACKING_URL'), '/');
        $this->siteId    = (int)Environment::getEnv('MATOMO_AI_TRACKING_SITE_ID');
    }

    /**
     * Public base URL of the site, without trailing slash. Call this while the request is being handled:
     * afterwards Director no longer knows the request and may fall back to http:// or the wrong host.
     */
    public static function getSiteUrl(): string
    {
        return rtrim((string)(Environment::getEnv('MATOMO_AI_TRACKING_SITE_URL') ?: Director::absoluteBaseURL()), '/');
    }

    public static function isEnabled(): bool
    {
        return (bool)Environment::getEnv('MATOMO_AI_TRACKING_ENABLED')
            && (bool)Environment::getEnv('MATOMO_AI_TRACKING_URL')
            && (int)Environment::getEnv('MATOMO_AI_TRACKING_SITE_ID') > 0;
    }

    /**
     * @param array{timestamp:int, url:string, isDownload:bool, status:int, bytes:int, ua:string, responseTimeMs:?int} $hit
     */
    public function track(array $hit): bool
    {
        $body = ['requests' => ['?' . http_build_query($this->buildTrackingParams($hit))]];

        try {
            $response = $this->createClient()->post($this->matomoUrl . '/matomo.php', [
                'json'    => $body,
                'headers' => ['User-Agent' => 'Hamaka-Matomo-AI-Tracking/1.0'],
            ]);
            $json     = json_decode((string)$response->getBody(), true);
        } catch (Throwable $e) {
            $this->logWarning('HTTP/API exception: ' . get_class($e) . ': ' . $e->getMessage());

            return false;
        }

        if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
            $this->logWarning('Unexpected response from Matomo: ' . substr((string)$response->getBody(), 0, 500));

            return false;
        }

        // Matomo answers "success" even when it rejected the hit (e.g. wrong site ID); those only show up here
        if ((int)($json['invalid'] ?? 0) > 0) {
            $this->logWarning('Matomo rejected the hit as invalid (check MATOMO_AI_TRACKING_SITE_ID): ' . substr((string)$response->getBody(), 0, 500));

            return false;
        }

        return true;
    }

    protected function createClient(): Client
    {
        return new Client([
            'timeout'         => (float)static::config()->get('timeout_seconds'),
            'connect_timeout' => (float)static::config()->get('connect_timeout_seconds'),
        ]);
    }

    public function buildTrackingParams(array $hit): array
    {
        $params = [
            'idsite'      => $this->siteId,
            'rec'         => 1,
            'recMode'     => 1, // bot tracking only: no visits/sessions
            'ua'          => $hit['ua'],
            'http_status' => $hit['status'],
            'bw_bytes'    => $hit['bytes'],
            'source'      => static::config()->get('source_label'),
            'cdt'         => $hit['timestamp'],
        ];

        $params[$hit['isDownload'] ? 'download' : 'url'] = $hit['url'];

        if ($hit['responseTimeMs'] !== null) {
            $params['pf_srv'] = $hit['responseTimeMs'];
        }

        return $params;
    }

    /**
     * Warning instead of error: when Matomo is down you don't want an error mail per bot request.
     */
    protected function logWarning(string $message): void
    {
        Injector::inst()->get(LoggerInterface::class)->warning('MatomoAiTracking: ' . $message);
    }
}
