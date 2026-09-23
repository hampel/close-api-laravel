<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Http;

use Hampel\CloseApi\Http\Sleeper;
use Illuminate\Support\Sleep;

/**
 * Waits between retries using Laravel's Sleep, so that `Sleep::fake()` covers them.
 *
 * The core package retries a 429 after waiting as long as Close asks, and retries a 5xx on an
 * idempotent request after a backoff. Its own sleeper calls usleep(). In an application's test
 * suite that means a faked 429 or 500 blocks the test for real: one second for a 429 that
 * carries no rate limit header, and longer for the backoff on a 500. Nothing reports the wait,
 * and the suite just gets slower. Through Sleep, a test calls `Sleep::fake()` and then asserts
 * the waits with `Sleep::assertSlept()` instead.
 *
 * A new Sleep is made for each wait. Sleep reads whether it is faked when the wait runs, so a
 * test can call `Sleep::fake()` at any point before the request, including after the client
 * was resolved.
 */
final class LaravelSleeper implements Sleeper
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        Sleep::usleep((int) round($seconds * 1_000_000));
    }
}
