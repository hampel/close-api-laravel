<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Account
    |--------------------------------------------------------------------------
    |
    | Which entry under "accounts" a call without a name uses: Close::leads() is
    | Close::client(<this>)->leads(). An application talking to one Close
    | organization never needs to name it.
    |
    */

    'default' => env('CLOSE_ACCOUNT', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    |
    | One entry per Close API key, named however you like -- the name is what
    | Close::client('...') takes. Each needs a key and nothing else: there is
    | one Close, so there is no URL to configure per account.
    |
    | A Close API key belongs to one user in one organization, and acts with
    | that user's permissions. A key made by an admin can read and change
    | everything in the organization. Make the key as a user whose role grants
    | only what the application needs.
    |
    | An account with no key is refused when its client is built, before a
    | request can fail. Every Close endpoint needs a credential.
    |
    | An OAuth integration acting for several Close users has a token per user
    | rather than a key per account, and the token changes when it is
    | refreshed. Such a token does not belong here: pass it to
    | Close::withAuthentication(new BearerToken($token)) at the time of the call.
    |
    */

    'accounts' => [

        'main' => [
            'key' => env('CLOSE_API_KEY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Base URI
    |--------------------------------------------------------------------------
    |
    | The API root, including the /api/v1/ segment. Null uses Close's own host,
    | which is right unless a recorded fixture is served locally or an outbound
    | proxy terminates the connection.
    |
    */

    'base_uri' => env('CLOSE_API_URL'),

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | The core package retries a 429 for every method, after waiting as long as
    | Close asks. It retries a 5xx or a connection failure only for a GET, PUT
    | or DELETE: a POST that failed that way may already have taken effect, and
    | repeating it is how duplicate leads get created.
    |
    | max_attempts counts the first request. base_delay is the backoff before
    | the first retry of a 5xx, in seconds, and doubles for each retry after it.
    | max_delay caps any single wait: if Close asks for a longer wait than this,
    | the request fails with a RateLimitException rather than waiting.
    |
    | THE WAIT HOLDS UP WHATEVER MADE THE CALL. In a web request that is the
    | request, so keep max_delay short there and let a queued job wait longer.
    | The waits go through Laravel's Sleep, so Sleep::fake() in a test skips
    | them.
    |
    | To change more than these three numbers, bind your own
    | Hampel\CloseApi\Http\RetryPolicy under the container key "close.retry_policy".
    |
    */

    'retries' => [
        'max_attempts' => env('CLOSE_RETRY_ATTEMPTS', 3),
        'base_delay' => env('CLOSE_RETRY_BASE_DELAY', 0.5),
        'max_delay' => env('CLOSE_RETRY_MAX_DELAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Applied to Laravel's HTTP client on every request, together with the
    | application's own Http::globalRequestMiddleware() and the transport
    | settings from Http::globalOptions(): proxy, TLS, protocol version and curl
    | options. Global headers, auth, query and body options are not applied,
    | because they would change the request the core package built.
    |
    | These limits apply to each attempt. A retried request can take up to
    | max_attempts times the timeout, plus the waits between attempts.
    |
    */

    'timeout' => env('CLOSE_TIMEOUT', 10),

    'connect_timeout' => env('CLOSE_CONNECT_TIMEOUT', 5),

];
