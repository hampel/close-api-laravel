# hampel/close-api-laravel

Laravel integration for `hampel/close-api`: a service provider, a manager for named accounts, a
facade, and one adapter that is the reason the package exists.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/Http/LaravelSleeper.php` | the core package's retry waits, sent through Laravel's `Sleep` |
| `src/CloseManager.php` | one client per configured account, memoised; `withAuthentication()` for OAuth |
| `src/CloseServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Facades/Close.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/close.php` | the published config |

## Version support

PHP `>=8.3`, Laravel `^12.71|^13.40`. PHPStan runs at level 10 with `phpVersion` spanning 8.3 to
8.5; keep `phpstan.neon.dist` in step with the `php` constraint. CI tests three corners: PHP 8.3
with Laravel 12 and `--prefer-lowest`, PHP 8.3 with Laravel 13, and PHP 8.5 with Laravel 13.

**The Laravel floors are the lowest releases Composer will install.** Composer 2.9 and later
refuse a release affected by a published security advisory, so on 2026-11-24 nothing below
12.71.0 or 13.40.0 resolved, and a lower floor promised versions nobody could install or test.
When a new advisory covers the floor, the `--prefer-lowest` job fails to resolve: raise the floor
to what resolves, and re-run the suite and PHPStan there on PHP 8.3.

## `Http::fake()` reaching the core package's traffic is the claim everything rests on

`tests/HttpFakeTest.php` asserts it. The adapter's docblock carries the load-bearing decisions,
each with its reason:

- **Rebuild the pending request on every send**, from a factory **resolved on every send**.
- **Share one Guzzle handler**, so keep-alive survives.
- **Call `send()` with the options set by hand.**
- **Pass transport options from an allowlist.**

**`laravel_data` is filled from the query string.** Laravel puts a GET's query there, which is
what `$request['status_id']` reads in an assertion. The adapter receives a finished PSR-7 request,
so it parses the parameters back out of the URI. Close is filtered almost entirely through query
parameters, so without this the assertion a consumer writes most often raises "Undefined array
key". `TransportTest::a_get_filter_is_readable_by_the_assertion` pins it.

## The transport lives under `close.http_client`, never the shared PSR-18 key

**Bound with `singletonIf()`, and the manager is built from that key alone.** It does not bind
or read `Psr\Http\Client\ClientInterface`. That key is shared by every package that uses it, and
`singleton()` on a bound key replaces it, so in an application with several API wrappers the last
provider registered would supply the transport, and the timeouts, for all of them.
`singletonIf()` rather than `singleton()` because Laravel Zero lists `AppServiceProvider` before a
package provider, so `singleton()` would replace an application's override there.
`SharedPsr18BindingTest` and `HttpClientOverrideTest` are the guards. **Do not add a fallback to
`ClientInterface`.**

`close.retry_policy` is the same shape for the core package's `RetryPolicy`: `singletonIf()`,
built from `close.retries` by default, and type-checked when the manager is built.

## Retries wait through `Sleep`, so the manager builds the `Transport` itself

**The manager calls `new Transport(...)` directly, passing `LaravelSleeper`.** `Close::withKey()`
takes a `Sleeper` but no base URI, and the manager passes the configured one. Without
`LaravelSleeper`, a consumer's faked 429 or 5xx-on-GET would block the test for real. Probed:
with `SystemSleeper` swapped in, three `RetryTest` cases fail and the file takes 25 seconds
instead of under one.

**`RetryTest` measures wall-clock time, not Carbon.** A real sleep advances Carbon by the same
amount, so a Carbon check passes against the real sleeper.

**No live run has seen a 429 or a `RateLimit` header.** The core package's harness has not
received one in any run, so the fakes in `RetryTest` follow Close's documentation. A green suite
here says the wiring is right, not that Close answers that way.

## Each account gets its own `Transport`

**The core package keeps `lastRateLimit()` on the Transport, and Close limits each key
separately.** A shared Transport would report whichever account called last.
`ManagerTest::each_account_reports_its_own_rate_limit` pins it. The PSR-18 client, factories,
retry policy, sleeper and logger are shared; only the Transport and its credential are per
account.

## Facts worth not rediscovering

- **The core class and the facade share the short name `Close`.** The facade imports the core
  class as `Client`, and `FacadeConformanceTest::resolve()` follows the alias.
  `FacadeConformanceTest` compares the `@method` block with `Hampel\CloseApi\Close` in both
  directions, and checks that the manager's `client()`, `withAuthentication()`,
  `getDefaultAccount()` and `configuredAccounts()` shadow nothing on it.
- **`withAuthentication()` is not memoised.** An OAuth token changes on refresh, and a cache keyed
  on it would keep every expired one.
- **The core package does not validate its base URI**, so the manager refuses a value with no
  `http` or `https` scheme or no host. Otherwise it would surface as a transport error naming a
  host.
- **The package's exceptions extend the core's `InvalidArgumentException`**, which is not final,
  so they are `CloseApiException`s. The core package's base type is an interface, so there is no
  base class to extend.
- **`composer-require-checker` carries the undeclared-dependency check, and is not in
  `composer check`.** Every whitelisted symbol belongs to a component that is in `require`, and
  `laravel/framework` replaces those components, so the checker cannot see which package
  declares them. A new `Illuminate` symbol reported there is a prompt to check `require`. Run it
  by hand after changing any `use` in `src/`:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

- **`failOnDeprecation` needs help under Testbench.** Laravel's error handler replaces PHPUnit's
  when the application boots, and discards deprecations while testing.
  `withoutDeprecationHandling()` in `setUp()` covers code a test runs; `ConfigurationTest`
  registers and boots the provider against an application built in the test body, because
  Testbench runs both before `setUp()` can install the strict handler.
- **Laravel Zero binds no HTTP factory and runs no discovery.** `LaravelZeroTest` builds that
  container by hand.

## No harness

A Laravel package gets a harness only for something it owns end to end that Testbench cannot see.
This one owns wiring and nothing else: no queue job, no command. The live API is exercised by the
core package's own harness.
