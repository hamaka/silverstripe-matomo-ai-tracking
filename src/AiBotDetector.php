<?php

namespace Hamaka\MatomoAiTracking;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Recognises AI chatbots and AI crawlers by user agent and decides which paths are (not) sent to Matomo, or sent as
 * a download.
 */
class AiBotDetector
{
    use Configurable;
    use Injectable;

    /** Fetches pages live on behalf of a user; counted by Matomo AI Insights. */
    public const TYPE_CHATBOT = 'chatbot';

    /** Crawls for training or an AI search index; not counted by AI Insights, see crawler_user_agent_patterns. */
    public const TYPE_CRAWLER = 'crawler';

    /**
     * User agent fragments (case-insensitive) that Matomo's BotTracking plugin recognises as AI chatbots.
     * See plugins/BotTracking/BotDetector.php in Matomo. Other bots (GPTBot, ClaudeBot, ...) are ignored by Matomo
     * in recMode=1, so we don't send those.
     */
    private static array $user_agent_patterns = [
        'ChatGPT-User',
        'MistralAI-User',
        'Gemini-Deep-Research',
        'Claude-User',
        'Perplexity-User',
        'Google-GeminiNotebook',
        'Google-NotebookLM',
    ];

    /**
     * User agent fragments (case-insensitive) of AI crawlers: training (GPTBot, ClaudeBot, CCBot, ...) and AI search
     * indexes (OAI-SearchBot, PerplexityBot, ...). Matomo AI Insights ignores these, so they are only sent when
     * MATOMO_AI_TRACKING_CRAWLERS_ENABLED is set, as regular tracking requests (see MatomoTracker).
     * Google-Extended and Applebot-Extended are robots.txt tokens only, not user agents, so they can't be detected.
     */
    private static array $crawler_user_agent_patterns = [
        'GPTBot',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-SearchBot',
        'anthropic-ai',
        'CCBot',
        'PerplexityBot',
        'Meta-ExternalAgent',
        'Bytespider',
        'Amazonbot',
    ];

    /**
     * Regex (on the path, without query string) for requests we never send: robots.txt, static assets, CMS.
     */
    private static string $exclude_path_pattern = '#(^/robots\.txt$|^/favicon|^/_resources/|^/(admin|dev|Security)(/|$)|\.(css|js|map|jpe?g|png|gif|svg|webp|avif|ico|woff2?|ttf|eot)$)#i';

    /**
     * Regex (on the path) for files that are sent to Matomo as a download instead of a page view.
     */
    private static string $download_path_pattern = '#\.(pdf|docx?|xlsx?|pptx?|odt|ods|zip|csv|txt)$#i';

    /**
     * @return string|null the matched user agent fragment (e.g. 'ChatGPT-User'), or null if this is not an AI chatbot
     */
    public function getBotName(string $userAgent): ?string
    {
        return $this->matchPattern($userAgent, 'user_agent_patterns');
    }

    public function isChatbot(string $userAgent): bool
    {
        return $this->getBotName($userAgent) !== null;
    }

    /**
     * @return string|null the matched user agent fragment (e.g. 'GPTBot'), or null if this is not an AI crawler
     */
    public function getCrawlerName(string $userAgent): ?string
    {
        return $this->matchPattern($userAgent, 'crawler_user_agent_patterns');
    }

    public function isCrawler(string $userAgent): bool
    {
        return $this->getCrawlerName($userAgent) !== null;
    }

    /**
     * @return string|null self::TYPE_CHATBOT, self::TYPE_CRAWLER or null. Chatbots win when both lists match.
     */
    public function getBotType(string $userAgent): ?string
    {
        if ($this->isChatbot($userAgent)) {
            return self::TYPE_CHATBOT;
        }

        return $this->isCrawler($userAgent) ? self::TYPE_CRAWLER : null;
    }

    protected function matchPattern(string $userAgent, string $configKey): ?string
    {
        foreach ((array)static::config()->get($configKey) as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @param string $path path with leading slash, without query string
     */
    public function isExcludedPath(string $path): bool
    {
        return (bool)preg_match((string)static::config()->get('exclude_path_pattern'), $path);
    }

    /**
     * @param string $path path with leading slash, without query string
     */
    public function isDownloadPath(string $path): bool
    {
        return (bool)preg_match((string)static::config()->get('download_path_pattern'), $path);
    }
}
