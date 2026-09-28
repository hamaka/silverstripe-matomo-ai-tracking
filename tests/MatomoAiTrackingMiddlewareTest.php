<?php

namespace Hamaka\MatomoAiTracking\Tests;

use Hamaka\MatomoAiTracking\MatomoAiTrackingMiddleware;
use Hamaka\MatomoAiTracking\MatomoTracker;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class MatomoAiTrackingMiddlewareTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const UA_CHATGPT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    private const ENV_KEYS = ['MATOMO_AI_TRACKING_ENABLED', 'MATOMO_AI_TRACKING_URL', 'MATOMO_AI_TRACKING_SITE_ID', 'MATOMO_AI_TRACKING_SITE_URL'];

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
        $this->assertSame('/news/some-article?x=1', $hit['path']);
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
        $this->assertSame('/?utm=test', $middleware->queued[0]['path']);
    }

    public function testIgnoresNonChatbotsExcludedPathsAndDisabledTracking(): void
    {
        $middleware = MatomoAiTrackingMiddleware::create();
        $response   = HTTPResponse::create('x', 200);

        // regular visitor / crawler that Matomo doesn't count as an AI chatbot
        $this->assertNull($middleware->buildHit('Mozilla/5.0 (Windows NT 10.0)', '', $response, 0));
        $this->assertNull($middleware->buildHit('GPTBot/1.1', '', $response, 0));
        // excluded paths
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'robots.txt', $response, 0));
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, '_resources/app/client/css/x.css', $response, 0));
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'admin/pages?x=1', $response, 0));

        // tracking disabled
        Environment::setEnv('MATOMO_AI_TRACKING_ENABLED', '');
        $this->assertNull($middleware->buildHit(self::UA_CHATGPT, 'news', $response, 0));
    }
}
