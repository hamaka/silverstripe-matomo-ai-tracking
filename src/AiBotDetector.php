<?php

namespace Hamaka\MatomoAiTracking;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Recognises AI chatbots by user agent and decides which paths are (not) sent to Matomo, or sent as a download.
 */
class AiBotDetector
{
    use Configurable;
    use Injectable;

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
        foreach ((array)static::config()->get('user_agent_patterns') as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return $pattern;
            }
        }

        return null;
    }

    public function isChatbot(string $userAgent): bool
    {
        return $this->getBotName($userAgent) !== null;
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
