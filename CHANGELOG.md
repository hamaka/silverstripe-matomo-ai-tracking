# Changelog

## Unreleased

- Optionally track AI crawlers (GPTBot, ClaudeBot, CCBot, OAI-SearchBot, PerplexityBot, …), which Matomo AI
  Insights ignores. They are sent as regular tracking requests with `bots=1` to a separate Matomo site. Off by
  default, see `MATOMO_AI_TRACKING_CRAWLERS_*` in the README. Chatbot tracking is unchanged.
- Track an `HTTPResponse_Exception` thrown by middleware further down (e.g. a redirect) with its own status code
  instead of 500.
- README: how to keep query parameters out of Matomo, and the `.htaccess` rule for crawlers.

## 1.1.0

- Resolve the site URL while the request is handled instead of in the shutdown function, so https and the
  right host are used (Director no longer knows the request at shutdown).
- Track requests that end in an uncaught exception as a 500.
- Log a warning when Matomo rejects a hit as invalid (e.g. wrong site ID); its bulk API still answers "success".
- README: require Matomo 5.7+, explain which full-page caches are (not) tracked, fix the YAML examples
  (list merging, regex replacing the default), correct the `PublicAssetAdapter_HTAccess.ss` template name and
  escaping, and document Silverstripe 6 commands.

## 1.0.1

- README: explain how to run the tests (not included in the Packagist download).

## 1.0.0

- Initial release: server-side tracking of AI chatbot requests to Matomo AI Insights via middleware.
