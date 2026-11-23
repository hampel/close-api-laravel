# CHANGELOG

## Unreleased

- Requires Laravel 12.71 or later, or 13.40 or later

## 0.1.0 (2026-09-23)

Initial release.

- `CloseServiceProvider` binds `CloseManager`, merges `config/close.php` and publishes it under
  the `close-config` tag
- `CloseManager` builds one `Hampel\CloseApi\Close` per configured account, memoised by name, each
  with its own `Transport`. A call that names no account goes to the default account
- `CloseManager::withAuthentication()` builds a client for a credential that is not in the config,
  such as an OAuth access token, over the same transport. It is not memoised
- `Close` facade, with a `@method` annotation for each method on the core package's `Close`
- `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` apply to the requests the
  API client makes. The core package's request building, status mapping and exception hierarchy
  are unchanged, and its exceptions reach the caller as they are
- In an `Http::assertSent()` closure, `$request['...']` reads a request's query parameters
- The PSR-18 transport is bound under `close.http_client` and the retry policy under
  `close.retry_policy`, each only when nothing has bound it already
- `close.retries` sets `max_attempts`, `base_delay` and `max_delay` for the core package's
  `DefaultRetryPolicy`
- `Sleep::fake()` skips retry waits
- The configured `timeout` and `connect_timeout` and the transport settings from
  `Http::globalOptions()` are applied to requests. Global headers, `auth`, `query` and body
  options are not
- `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not bound one
- `UnknownAccount` and `InvalidConfiguration` are `CloseApiException`s
- Requires `hampel/close-api` `^0.2`, PHP 8.3, and Laravel 12 or 13
