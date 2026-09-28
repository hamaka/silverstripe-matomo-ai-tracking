# Silverstripe Matomo AI tracking

Tracks requests from AI chatbots (ChatGPT-User, Claude-User, Perplexity-User, …) in
[Matomo AI Insights](https://matomo.org/) using server-side middleware.

AI assistants fetch your pages on behalf of their users, but they don't execute JavaScript, so the regular
Matomo tracking code never sees them. This module detects these requests in PHP and sends them to the Matomo
HTTP Tracking API in "bot only" mode (`recMode=1`). They show up under **AI Insights** without affecting your
visitor statistics.

## Requirements

- Silverstripe 5 or 6
- PHP 8.1+
- Matomo 5.x with the **BotTracking** plugin (AI Insights) enabled

## Installation

```sh
composer require hamaka/silverstripe-matomo-ai-tracking
```

Flush afterwards (`?flush=1` or `sake dev/build flush=1`). No `dev/build` is needed: the module adds no database tables.

## Configuration

Tracking is off by default. Enable it per environment in `.env`:

```dotenv
MATOMO_AI_TRACKING_ENABLED="1"
MATOMO_AI_TRACKING_URL="https://stats.example.com"
MATOMO_AI_TRACKING_SITE_ID="1"
# Optional: public URL used in the report, defaults to Director::absoluteBaseURL()
#MATOMO_AI_TRACKING_SITE_URL="https://www.example.com"
```

No token is needed: hits are always sent in real time.

Tip: only enable it on live (or point test environments to a separate Matomo site ID), otherwise test hits end up
in your production statistics.

### Optional YAML configuration

```yml
Hamaka\MatomoAiTracking\AiBotDetector:
  # user agent fragments recognised as AI chatbots (must match what Matomo counts)
  user_agent_patterns:
    - 'ChatGPT-User'
  # regex on the path of requests that are never sent
  exclude_path_pattern: '#^/(admin|dev|Security)(/|$)#i'
  # regex on the path of requests that are sent as a download instead of a page view
  download_path_pattern: '#\.(pdf|docx?)$#i'

Hamaka\MatomoAiTracking\MatomoTracker:
  timeout_seconds: 2.0
  connect_timeout_seconds: 1.0
  source_label: 'SilverStripe'
```

## How it works

- `MatomoAiTrackingMiddleware` is registered as the outermost Director middleware (`Before: '*'`), so pages served
  from a full-page cache (e.g. `tractorcow/silverstripe-dynamiccache`) are tracked too.
- Only user agents that Matomo counts as AI chatbots are sent. Crawlers such as GPTBot and ClaudeBot are ignored by
  Matomo in bot mode, so they are skipped here as well.
- Status code, response size and server response time (`pf_srv`) are sent along.
- The call to Matomo happens in a shutdown function, after the response has been sent. Under PHP-FPM the connection
  to the bot is closed first (`fastcgi_finish_request()`). Under mod_php the connection stays open until the call
  is done (max. `timeout_seconds`).
- When Matomo is unreachable a `warning` is logged and the hit is dropped. The response is never affected.

## Limitation: files served directly by the webserver

Existing files in `assets/` (PDFs, documents) are served by Apache/nginx without starting PHP, so this middleware
never sees them. If you want to know which documents AI assistants read, route **only AI chatbot requests** for
those files through Silverstripe. Regular visitors are unaffected.

Apache: add this before the "Non existant files passed to requesthandler" block in `public/assets/.htaccess`.
That file is generated, so put the rule in your project's override of the `PublicAssetAdapter_Htaccess.ss`
template.

```apache
# Route AI chatbots through Silverstripe so MatomoAiTrackingMiddleware can track them
RewriteCond %{HTTP_USER_AGENT} (ChatGPT-User|Claude-User|Perplexity-User|MistralAI-User|Gemini-Deep-Research|Google-NotebookLM|Google-GeminiNotebook) [NC]
RewriteRule \.(pdf|docx?|xlsx?|pptx?)$ ../index.php [QSA,L]
```

nginx: use an equivalent `location` block with an `if ($http_user_agent ~* ...)` rewrite to `index.php`.

Check that Silverstripe then actually serves the file (status 200) on your setup. Keep the list of bots in sync
with `user_agent_patterns`.

## Running the tests

From a Silverstripe project that has this module installed:

```sh
vendor/bin/phpunit vendor/hamaka/silverstripe-matomo-ai-tracking/tests
```

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).
