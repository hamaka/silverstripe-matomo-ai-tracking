<?php

namespace Hamaka\MatomoAiTracking\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Hamaka\MatomoAiTracking\MatomoAiTrackingMiddleware;
use Hamaka\MatomoAiTracking\MatomoTracker;
use RuntimeException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class MatomoAiTrackingMiddlewareTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const UA_CHATGPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    private const UA_GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot';

    private const ENV_KEYS = [
        'MATOMO_AI_TRACKING_ENABLED',
        'MATOMO_AI_TRACKING_URL',
        'MATOMO_AI_TRACKING_SITE_ID',
        'MATOMO_AI_TRACKING_SITE_URL',
        'MATOMO_AI_TRACKING_CRAWLERS_ENABLED',
        'MATOMO_AI_TRACKING_CRAWLERS_SITE_ID',
        'MATOMO_AI_TRACKING_CRAWLERS_BOTS_PARAM',
    ];

    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = Environment::getEnv($key);
        }
        Environment::setEnv('MATOMO_AI_TRACKING_ENABLED', '1');
        Environment::setEnv('MATOMO_AI_TRACKING_URL', 'https://stats.example.com/');
        Environment::setEnv('MATOMO_AI_TRACKING_SITE_ID', '3');
        Environment::setEnv('MATOMO_AI_TRACKING_SITE_URL', 'https://www.example.com/');
        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_ENABLED', '');
        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_SITE_ID', '');
        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_BOTS_PARAM', '');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $value) {
            Environment::setEnv($key, $value === false || $value === null ? '' : $value);
        }

        parent::tearDown();
    }

    public function testBuildsHitForChatbotPageRequest(): void
    {
        $response = HTTPResponse::create('<html>12345</html>', 200);

        $hit = MatomoAiTrackingMiddleware::create()->buildHit(self::UA_CHATGPT, 'news/some-article?x=1', $response, 0.152);

        $this->assertNotNull($hit);
        $this->assertSame('chatbot', $hit['type']);
        $this->assertSame('https://www.example.com/news/some-article?x=1', $hit['url']);
        $this->assertFalse($hit['isDownload']);
        $this->assertSame(200, $hit['status']);
        $this->assertSame(18, $hit['bytes']);
        $this->assertSame(152, $hit['responseTimeMs']);

        $params = MatomoTracker::create()->buildTrackingParams($hit);
        $this->assertSame(3, $params['idsite']);
        $this->assertSame(1, $params['recMode']);
        $this->assertSame('https://www.example.com/news/some-article?x=1', $params['url']);
        $this->assertSame(152, $params['pf_srv']);
    }

    public function testBuildsRegularTrackingRequestForCrawlerWhenEnabled(): void
    {
        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_ENABLED', '1');
        $response = HTTPResponse::create('<html>12345</html>', 200);

        $hit = MatomoAiTrackingMiddleware::create()->buildHit(self::UA_GPTBOT, 'news/some-article', $response, 0.05);

        $this->assertNotNull($hit);
        $this->assertSame('crawler', $hit['type']);

        // no recMode: AI Insights drops crawlers; same site ID and no bots=1 unless configured
        $params = MatomoTracker::create()->buildTrackingParams($hit);
        $this->assertArrayNotHasKey('recMode', $params);
        $this->assertArrayNotHasKey('bots', $params);
        $this->assertSame(3, $params['idsite']);
        $this->assertSame('https://www.example.com/news/some-article', $params['url']);

        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_SITE_ID', '7');
        Environment::setEnv('MATOMO_AI_TRACKING_CRAWLERS_BOTS_PARAM', '1');
        $params = MatomoTracker::create()->buildTrackingParams($hit);
        $this->assertSame(7, $params['idsite']);
        $this->assertSame(1, $params['bots']);

        // chatbots keep going to AI Insights on the main site
        $chatbotHit = MatomoAiTrackingMiddleware::create()->buildHit(self::UA_CHATGPT, 'news', $response, 0.05);
        $params     = MatomoTracker::create()->buildTrackingParams($chatbotHit);
        $this->assertSame(3, $params['idsite']);
        $this->assertSame(1, $params['recMode']);
        $this->assertArrayNotHasKey('bots', $params);
    }

    public function testDetectsDownload(): void
    {
        $response = HTTPResponse::create('', 200)->addHeader('Content-Length', '51234');

        $hit = MatomoAiTrackingMiddleware::create()->buildHit('Claude-User/1.0', 'assets/Uploads/report.pdf', $response, 0.01);

        $this->assertTrue($hit['isDownload']);
        $this->assertSame(51234, $hit['bytes']);
        $this->assertSame('https://www.example.com/assets/Uploads/report.pdf', MatomoTracker::create()->buildTrackingParams($hit)['download']);
    }

    public function testUsesUrlFromBeforeRouting(): void
    {
        $request = new HTTPRequest('GET', '', ['utm' => 'test']);
        $request->addHeader('User-Agent', self::UA_CHATGPT);

        $middleware = new class extends MatomoAiTrackingMiddleware {
            public array $queued = [];

            protected function queueHit(array $hit): void
            {
                $this->queued[] = $hit;
            }
        };

        // simulate a controller rewriting the URL during routing, like RootURLController does ('' => 'home')
        $middleware->process($request, function (HTTPRequest $request) {
            $request->setUrl('home');

            return HTTPResponse::create('ok', 200);
        });

        $this->assertCount(1, $middleware->queued);
        $this->assertSame('https://www.example.com/?utm=test', $middleware->queued[0]['url']);
    }

    public function testTracksUncaughtExceptionAsServerError(): void
    {
        $request = new HTTPRequest('GET', 'news', []);
        $request->addHeader('User-Agent', self::UA_CHATGPT);

        $middleware = new class extends MatomoAiTrackingMiddleware {
            public array $queued = [];

            protected function queueHit(array $hit): void
            {
                $this->queued[] = $hit;
            }
        };

        try {
            $middleware->process($request, function () {
                throw new RuntimeException('boom');
            });
            $this->fail('The exception should bubble up');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(1, $middleware->queued);
        $this->assertSame(500, $middleware->queued[0]['status']);
    }

    public function testInvalidHitIsReportedAsFailure(): void
    {
        $tracker = $this->createTrackerWithResponse('{"status":"success","tracked":0,"invalid":1,"invalid_indices":[0]}');
        $this->assertFalse($tracker->track($this->createHit()));

        $tracker = $this->createTrackerWithResponse('{"status":"success","tracked":1,"invalid":0}');
        $this->assertTrue($tracker->track($this->createHit()));
    }
    private function createTrackerWithResponse(string $body): MatomoTracker
    {
        $tracker = new class extends MatomoTracker {
            public string $body = '';

            protected function createClient(): Client
            {
                return new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $this->body)]))]);
            }

            protected function logWarning(string $message): void
            {
            }
        };
        $tracker->body = $body;

        return $tracker;
    }

    private function createHit(): array
    {
        return [
            'timestamp'      => time(),
            'url'            => 'https://www.example.com/news',
            'isDownload'     => false,
            'status'         => 200,
            'bytes'          => 10,
            'ua'             => self::UA_CHATGPT,
            'responseTimeMs' => 5,
        ];
    }

    public function testIgnoresNonChatbotsExcludedPathsAndDisabledTracking(): void
    {
        $middleware = MatomoAiTrackingMiddleware::create();
        $response   = HTTPResponse::create('x', 200);

        // regular visitor / crawler that Matomo doesn't count as an AI chatbot
        $this->assertNull($middleware->buildHit('Mozilla/5.0 (Windows NT 10.0)', '', $response, 0));
        // crawlers are only sent when crawler tracking is enabled
        $this->assertNull($middleware->buildHit(self::UA_GPTBOT, '', $response, 0));
        // excluded paths
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'robots.txt', $response, 0));
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, '_resources/app/client/css/x.css', $response, 0));
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'admin/pages?x=1', $response, 0));

        // tracking disabled
        Environment::setEnv('MATOMO_AI_TRACKING_ENABLED', '');
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'news', $response, 0));
    }
}
