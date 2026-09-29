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
- Matomo 5.7 or newer, with the **BotTracking** plugin (AI Insights) enabled. The plugin does not exist in 5.6 and older.
- A full-page cache, if any, must run as Director middleware. See [Full-page caches](#full-page-caches).

## Installation

```sh
composer require hamaka/silverstripe-matomo-ai-tracking
```

Flush afterwards: `?flush=1` in the browser, or on the command line `vendor/bin/sake dev/build flush=1` (Silverstripe 5)
or `vendor/bin/sake flush` (Silverstripe 6). No database build is needed: the module adds no tables.

## Configuration

Tracking is off by default. Enable it per environment in `.env`:

```dotenv
MATOMO_AI_TRACKING_ENABLED="1"
MATOMO_AI_TRACKING_URL="https://stats.example.com"
MATOMO_AI_TRACKING_SITE_ID="1"
# Optional: public URL used in the report, defaults to the scheme and host of the current request
#MATOMO_AI_TRACKING_SITE_URL="https://www.example.com"
```

The site URL is taken from the request itself. Behind a proxy or load balancer that terminates TLS, make sure
Silverstripe recognises the request as https (`SS_TRUSTED_PROXY_IPS`), or set `MATOMO_AI_TRACKING_SITE_URL`.

No token is needed: hits are always sent in real time.

Tip: only enable it on live (or point test environments to a separate Matomo site ID), otherwise test hits end up
in your production statistics.

### Optional YAML configuration

**Excluded paths and downloads** are single regexes. Setting one in YAML *replaces* the default, so copy the
default from `src/AiBotDetector.php` and extend it. For example, to also exclude `/api/`:

```yml
Hamaka\MatomoAiTracking\AiBotDetector:
  # regex on the path of requests that are never sent (default + ^/api/)
  exclude_path_pattern: '#(^/robots\.txt$|^/favicon|^/_resources/|^/api/|^/(admin|dev|Security)(/|$)|\.(css|js|map|jpe?g|png|gif|svg|webp|avif|ico|woff2?|ttf|eot)$)#i'
  # regex on the path of requests that are sent as a download instead of a page view
  download_path_pattern: '#\.(pdf|docx?|xlsx?|pptx?|odt|ods|zip|csv|txt|epub)$#i'
```

**User agents**: `user_agent_patterns` is a list. Silverstripe *merges* lists from YAML with the default instead of
replacing them, so a YAML list can only add patterns. Adding one rarely helps: Matomo only counts the AI chatbots
its BotTracking plugin knows and silently drops the rest. To track fewer bots, replace the list in `_config.php`:

```php
use Hamaka\MatomoAiTracking\AiBotDetector;
use SilverStripe\Core\Config\Config;

Config::modify()->set(AiBotDetector::class, 'user_agent_patterns', ['ChatGPT-User', 'Claude-User']);
```

**Tracker settings**:

```yml
Hamaka\MatomoAiTracking\MatomoTracker:
  timeout_seconds: 2.0
  connect_timeout_seconds: 1.0
  source_label: 'SilverStripe'
```

## How it works

- `MatomoAiTrackingMiddleware` is registered as the outermost Director middleware (`Before: '*'`).
- Only user agents that Matomo counts as AI chatbots are sent. Crawlers such as GPTBot and ClaudeBot are ignored by
  Matomo in bot mode, so they are skipped here as well.
- Status code, response size and server response time (`pf_srv`) are sent along. A request that ends in an uncaught
  exception is tracked as a 500.
- The call to Matomo happens in a shutdown function, after the response has been sent. Under PHP-FPM the connection
  to the bot is closed first (`fastcgi_finish_request()`). Under mod_php the connection stays open until the call
  is done (max. `timeout_seconds`).
- A `warning` is logged and the hit is dropped when Matomo is unreachable, returns an error, or rejects the hit as
  invalid (for example a wrong site ID: Matomo still answers "success" then, with an `invalid` count). The response
  to the bot is never affected.

## Full-page caches

The middleware only sees requests that reach Silverstripe's Director:

- **Tracked:** full-page caches that run as Director middleware *after* this one (such as an in-house
  `DynamicCacheMiddleware`), because this middleware is registered first.
- **Not tracked:** caches that answer *before* Director, such as static publishing
  (`silverstripe/staticpublishqueue`), caches in `index.php` or `.htaccess`, a reverse proxy (Varnish) or a CDN.
  The original `tractorcow/silverstripe-dynamiccache` belongs here too: it hooks in before the framework and was
  never released for Silverstripe 5/6.

For those setups, bots that get a cached page never reach PHP. Track them at the proxy/CDN level or exclude AI
chatbot user agents from the cache.

## Limitation: files served directly by the webserver

Existing files in `assets/` (PDFs, documents) are served by Apache/nginx without starting PHP, so this middleware
never sees them. If you want to know which documents AI assistants read, route **only AI chatbot requests** for
those files through Silverstripe. Regular visitors are unaffected.

Apache: add this before the "Non existant files passed to requesthandler" block in `public/assets/.htaccess`.
That file is generated, so put the rule in your project's override of the template
`templates/SilverStripe/Assets/Flysystem/PublicAssetAdapter_HTAccess.ss` (mind the capitals on case-sensitive file
systems), then regenerate it with a flush.

```apache
# Route AI chatbots through Silverstripe so MatomoAiTrackingMiddleware can track them
RewriteCond %{HTTP_USER_AGENT} (ChatGPT-User|Claude-User|Perplexity-User|MistralAI-User|Gemini-Deep-Research|Google-NotebookLM|Google-GeminiNotebook) [NC]
RewriteRule [.](pdf|docx?|xlsx?|pptx?)$ ../index.php [QSA,L]
```

The rule uses `[.]` instead of `\.` on purpose: backslashes in that template are escaped (the existing rules use
`\\.` and `\\\\`), so a pasted `\.` may not end up in `.htaccess` as written. Always check the generated
`public/assets/.htaccess`.

nginx: use an equivalent `location` block with an `if ($http_user_agent ~* ...)` rewrite to `index.php`.

Check that Silverstripe then actually serves the file (status 200) on your setup. Keep the list of bots in sync
with `user_agent_patterns`.

## Running the tests

The `tests/` folder is not part of the Packagist download (see `.gitattributes`). Install the module from source
in a Silverstripe project to run them:

```sh
composer require hamaka/silverstripe-matomo-ai-tracking --prefer-source
vendor/bin/phpunit vendor/hamaka/silverstripe-matomo-ai-tracking/tests
```

If the tests fail with "getItemPath returned null", the class manifest is stale. Silverstripe picks up a `flush`
argument from the command line. With PHPUnit 9 (Silverstripe 5) use:

```sh
vendor/bin/phpunit vendor/hamaka/silverstripe-matomo-ai-tracking/tests '' flush=1
```

PHPUnit 10+ (Silverstripe 6) treats extra arguments as test paths, so this trick doesn't work there; see the
Silverstripe 6 testing docs for how to flush before a test run.

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).
