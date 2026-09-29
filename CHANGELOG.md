# Changelog

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
