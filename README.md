# Close CRM API for Laravel

[![Tests](https://github.com/hampel/close-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/close-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/close-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/close-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/close-api-laravel.svg?style=flat-square)](https://github.com/hampel/close-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/close-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/close-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/close-api`][core]: a service provider, a manager for named
accounts, and a facade.

It adds three things an application would otherwise write itself:

- **`Http::fake()` sees the API client's traffic.** The core package sends through a PSR-18
  client, so by default Laravel's HTTP fakes cannot see its requests. This package sends every
  request through Laravel's own handler stack, so `Http::fake()`, `Http::assertSent()` and
  `Http::preventStrayRequests()` all work.
- **Named accounts.** One API key per account, a default account, and `Close::client('name')` to
  reach any of them. Laravel's own database and mail managers work the same way.
- **Retries you can configure and fake.** The core package retries a rate-limited request after
  waiting as long as Close asks. Here the limits come from config, and the wait goes through
  Laravel's `Sleep`, so `Sleep::fake()` skips it in a test.

The core package still builds every request, maps each status to its exception and owns the
exception hierarchy. So a 401 and a 404 remain different exceptions, rather than both becoming an
unsuccessful response.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero works too, once the HTTP component is installed (`php <app> app:install http`). A full
Laravel application binds `Illuminate\Http\Client\Factory` as a singleton in
`FoundationServiceProvider`, but a Laravel Zero application does not register that provider.
Without that binding, `Http::fake()` fails to intercept without any error, and the request reaches
the real API. This package binds the factory when nothing else has, so both behave the same.

## Installation

```bash
composer require hampel/close-api-laravel
```

In a Laravel application the provider and the `Close` alias are discovered automatically.
Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=close-config
```

**Laravel Zero does not run package discovery**, so list the provider yourself in
`config/app.php`:

```php
'providers' => [
    Hampel\CloseApi\Laravel\CloseServiceProvider::class,
],
```

The global `Close` alias is not registered there either. Import the facade class instead
(`use Hampel\CloseApi\Laravel\Facades\Close;`), or inject `CloseManager`.

## Configuration

An account needs an API key and nothing else. There is only one Close, so there is no URL to
configure.

```dotenv
CLOSE_API_KEY=api_xxxxxxxxxxxxxxxx
```

**A Close API key acts with the permissions of the user who created it.** A key created by an
admin can read and change everything in the organization. Create the key as a user whose role
allows only what the application needs.

The shipped `config/close.php` defines one account called `main`. Add more by naming them:

```php
'default' => 'sales',

'accounts' => [
    'sales' => [
        'key' => env('CLOSE_API_KEY'),
    ],

    'support' => [
        'key' => env('SUPPORT_CLOSE_API_KEY'),
    ],
],
```

**An account with no key is refused when its client is built.** Left to reach the API, it would
come back 401, which looks like a revoked key when the real cause is an unset environment
variable. An empty string counts as no key. The refusal raises
`Hampel\CloseApi\Laravel\Exception\InvalidConfiguration`, which is a `CloseApiException`.

**An application config file named `close.php` replaces these settings key by key.** Laravel
merges a package's config shallowly, and the application's file wins for every top-level key it
defines. So if an application already has its own `config/close.php` with, say, a `timeout` that
means something else, that value becomes this package's timeout, and nothing reports it. Publish
this file and edit it, or give your own settings file a different name.

### Retries

```php
'retries' => [
    'max_attempts' => 3,     // counts the first request; 1 turns retries off
    'base_delay' => 0.5,     // seconds, before the first retry of a 5xx
    'max_delay' => 60,       // the longest single wait before the package gives up
],
```

The core package decides what is retried:

- **A 429 is retried for every method**, after waiting as long as Close asks. Close applies a
  rate limit before it processes a request, so nothing happened and repeating it is safe.
- **A 5xx or a connection failure is retried only for `GET`, `PUT` and `DELETE`.** A `POST` that
  failed that way may already have taken effect, and repeating it could create a duplicate lead.

**The wait blocks whatever made the call.** In a web request, that request waits. If Close asks
for a longer wait than `max_delay`, the request fails with `RateLimitException` straight away, so
keep `max_delay` short where a person is waiting.

**In a queue worker, a wait holds the worker.** Nothing else runs on it while it sleeps through
Close's rate limit window. Bind the core package's `NoRetryPolicy` so that a failure reaches the
job at once:

```php
use Hampel\CloseApi\Http\NoRetryPolicy;
use Hampel\CloseApi\Laravel\CloseServiceProvider;

$this->app->singleton(CloseServiceProvider::RETRY_POLICY, fn () => new NoRetryPolicy());
```

Then the job releases itself for as long as Close asked:

```php
use Hampel\CloseApi\Exception\RateLimitException;

try {
    Close::leads()->create($attributes);
} catch (RateLimitException $e) {
    $this->release((int) ceil($e->waitSeconds()));
}
```

The binding applies to every account and every caller. If web requests should still retry, bind
a policy of your own that decides per context instead.

To go beyond these three numbers, bind any `Hampel\CloseApi\Http\RetryPolicy` under
`close.retry_policy`:

```php
use Hampel\CloseApi\Laravel\CloseServiceProvider;

$this->app->singleton(CloseServiceProvider::RETRY_POLICY, fn () => new MyRetryPolicy());
```

### Base URI and transport

```php
'base_uri' => env('CLOSE_API_URL'),  // null uses https://api.close.com/api/v1/
'timeout' => 10,
'connect_timeout' => 5,
```

`base_uri` exists for serving a recorded fixture locally, and for an outbound proxy that
terminates the connection. A value without an `http` or `https` scheme is refused when the first
client is built.

The timeouts apply to every request, together with any `Http::globalRequestMiddleware()` the
application has configured and the transport settings from `Http::globalOptions()`: a proxy, a CA
bundle or client certificate, the protocol version, curl options. **Global options that would
change the request itself are not applied.** `headers`, `auth`, `query` and the body options
would overwrite what the core package built, including its `Authorization` header. The timeouts
apply to each attempt, so a request that is retried can take longer in total.

## Usage

The facade reaches the default account directly:

```php
use Hampel\CloseApi\Laravel\Facades\Close;

$lead = Close::leads()->get('lead_abc123');

echo $lead['name'];
echo $lead['custom.cf_xyz'];          // custom fields are literal keys

foreach (Close::leads()->paginate(['status_id' => 'stat_x']) as $lead) {
    // pages fetched as needed
}
```

Name an account to reach another:

```php
$leads = Close::client('support')->leads()->list();
```

Everything after that point is the core package. See [its documentation][core] for the
resources, both kinds of pagination and their limits, the Advanced Filtering API, and reaching
an endpoint it does not wrap through `transport()`.

Inject the manager where you do not want a facade:

```php
use Hampel\CloseApi\Laravel\CloseManager;

public function __construct(private readonly CloseManager $close) {}

$this->close->client('support')->leads()->list();
```

### OAuth

An integration that acts on behalf of several Close users has one OAuth access token per user
instead of one key per account. A token changes each time it is refreshed, so it does not belong
in config. Pass it when you make the call:

```php
use Hampel\CloseApi\Auth\BearerToken;

$close = Close::withAuthentication(new BearerToken($user->close_access_token));

$close->leads()->list();
```

That client uses the same transport, retry policy and logger as a configured account, so
`Http::fake()` sees its requests too. It is built new on each call rather than cached. This
package does not run the authorization flow or refresh tokens; the application that holds the
client secret does that.

## Errors

The core package's exceptions reach the caller unchanged:

```php
use Hampel\CloseApi\Exception\AuthenticationException;
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Exception\RateLimitException;

try {
    $lead = Close::leads()->get($id);
} catch (NotFoundException) {
    return null;                          // an ordinary answer, not a failure
} catch (AuthenticationException) {
    // the key is no good: a configuration error, not an empty result
} catch (RateLimitException $e) {
    $retryIn = $e->waitSeconds();         // retries were used up, or the wait exceeded max_delay
}
```

`UnknownAccount` and `InvalidConfiguration` extend the core package's `InvalidArgumentException`,
so they are `CloseApiException`s too. An application that already catches that catches these as
well.

## Logging

The core package logs through the application's default PSR-3 logger. Each request and response
is logged at `debug`, each retry at `warning`, and a request that never reached Close at `error`.
The API key is never logged; the log shows only its prefix and length. **At `debug` the log
includes request URIs, and a URI can contain a search term**, so choose the log level with that
in mind.

## Testing

Fake the API with the same `Http::` helpers the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();
Http::fake([
    'api.close.com/*' => Http::response(['id' => 'lead_abc123', 'name' => 'Wayne Enterprises']),
]);

$lead = Close::leads()->get('lead_abc123');

Http::assertSent(fn ($request) => $request->url() === 'https://api.close.com/api/v1/lead/lead_abc123/');
```

The package's real code runs, and only the socket is replaced. So a faked 404 still arrives as
`NotFoundException`, and a faked 200 whose body is HTML still arrives as `DecodeException`.

- **Paths end in a slash.** The core package adds one to every path, because Close answers a path
  without one with a 308. Match `api.close.com/*` or the full URL, including the trailing slash.
- **Filters are readable in an assertion.** `$request['status_id']` reads the query string of a
  `GET`, and the body of a request that has one. A list parameter is comma-joined, as Close
  expects: `$request['_fields']` is `'id,name'`, not an array.
- **Give every fake a body.** `Http::fake()` with no arguments answers every request with an
  empty 200, which raises `DecodeException`. Close never answers a 2xx with an empty body, even
  on a delete, so an empty body means the response came from something other than Close. A
  forgotten fixture fails loudly instead of reading as "no leads".
- **Fake `Sleep` when you fake an error.** A faked 429, or a 5xx on a `GET`, is retried with a
  real wait unless `Sleep::fake()` is on. `Sleep::assertSleptTimes()` then says how many retries
  there were.
- **A cursor search needs one response per page.** Queue them with `Http::fakeSequence()`.
- **The order of setup does not matter.** Faking after the client has been resolved works, and so
  does `Http::swap(new Factory)` to start a test from a clean set of fakes, because the transport
  looks the factory up at the moment it sends.

Replace the transport entirely by binding `close.http_client`. That is how an application with
its own outbound HTTP policy makes this package send through it:

```php
use Hampel\CloseApi\Laravel\CloseServiceProvider;

$this->app->singleton(CloseServiceProvider::HTTP_CLIENT, fn () => $myPsr18Client);
```

The package binds that key only if nothing has bound it already, so the override works from any
service provider, whether it registers before or after this package's. That includes
`AppServiceProvider` in a Laravel Zero `config/app.php`, where it is listed first.

**This package does not use a `Psr\Http\Client\ClientInterface` binding**, whether yours or
another package's, and does not bind that key itself. Every package that uses that key shares it,
so in an application with several API integrations installed, whichever registered last would
supply the transport for all of them. To send several packages through one client, bind each
package's own key to it.

### What Laravel's HTTP events see

**`RequestSending` fires; `ResponseReceived` and `ConnectionFailed` do not.** Laravel raises the
first from inside the handler stack this package sends through, and the other two from a layer
above it. So Telescope's HTTP client watcher, which listens for `ResponseReceived`, will not show
this traffic. The core package's own log lines cover it instead: see [Logging](#logging).

## Versioning

`hampel/close-api` is 0.x, so its public API can change in a minor release. This package
constrains it to `^0.2` and expects to raise that constraint.

## Licence

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/close-api
