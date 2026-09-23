<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Exception\RuntimeException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Laravel\CloseManager;
use Hampel\CloseApi\Laravel\CloseServiceProvider;
use Hampel\CloseApi\Laravel\Exception\InvalidConfiguration;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;

/**
 * The core package's retries, running inside Laravel.
 *
 * The core package retries on its own schedule and waits between attempts. Two things change
 * inside Laravel. The waits go through Sleep, so a consumer's suite can fake them. And the
 * policy's limits come from config, where a web request and a queued job can be given
 * different ones.
 *
 * WHAT THIS DOES NOT PROVE: that Close answers a rate-limited request the way these fakes do.
 * No live run of the core package's harness has yet received a 429 or a RateLimit header, so
 * the shapes below come from Close's documentation. See the core package's DESIGN.md.
 */
final class RetryTest extends TestCase
{
    #[Test]
    public function a_rate_limited_request_waits_as_long_as_close_asks_and_the_wait_is_fakeable(): void
    {
        Sleep::fake();

        Http::fakeSequence('api.close.com/*')
            ->push(['error' => 'Too many requests'], 429, ['RateLimit' => 'limit=80, remaining=0, reset=2.5'])
            ->push(self::lead());

        $lead = Close::leads()->get('lead_abc123');

        $this->assertSame('Wayne Enterprises', $lead['name']);
        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::usleep(2_500_000)]);
    }

    #[Test]
    public function a_rate_limited_post_is_retried_because_nothing_happened(): void
    {
        // Close applies a rate limit before it processes the request, so repeating a POST that
        // was rate limited cannot create a duplicate.
        Sleep::fake();

        Http::fakeSequence('api.close.com/*')
            ->push(['error' => 'Too many requests'], 429, ['Retry-After' => '1'])
            ->push(self::lead());

        Close::leads()->create(['name' => 'Wayne Enterprises']);

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    #[Test]
    public function a_server_error_on_a_post_is_not_retried(): void
    {
        // A POST that failed with a 5xx may already have been applied, so sending it again
        // could create a second lead. The core package decides this. The test checks that the
        // decision still holds when the request goes through Laravel's HTTP client.
        Sleep::fake();

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Internal Server Error'], 500)]);

        try {
            Close::leads()->create(['name' => 'Wayne Enterprises']);
            $this->fail('Expected a ServerException.');
        } catch (ServerException) {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function a_server_error_on_a_get_is_retried_up_to_the_configured_attempts(): void
    {
        Sleep::fake();
        $this->container()->make(Config::class)->set('close.retries.max_attempts', 4);
        $this->forgetRetryPolicy();

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Internal Server Error'], 500)]);

        try {
            Close::leads()->get('lead_abc123');
            $this->fail('Expected a ServerException.');
        } catch (ServerException) {
            Http::assertSentCount(4);
            Sleep::assertSleptTimes(3);
        }
    }

    #[Test]
    public function a_wait_longer_than_max_delay_is_reported_instead_of_slept_through(): void
    {
        // A web request should not block for a minute to wait out a rate limit window. With a
        // short max_delay, the caller gets the RateLimitException and can decide what to do.
        Sleep::fake();
        $this->container()->make(Config::class)->set('close.retries.max_delay', 5);
        $this->forgetRetryPolicy();

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Too many requests'], 429, ['Retry-After' => '30'])]);

        try {
            Close::leads()->get('lead_abc123');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(30.0, $e->waitSeconds());
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    #[Test]
    public function a_single_attempt_turns_retries_off(): void
    {
        Sleep::fake();
        $this->container()->make(Config::class)->set('close.retries.max_attempts', 1);
        $this->forgetRetryPolicy();

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Too many requests'], 429, ['Retry-After' => '1'])]);

        $this->expectException(RateLimitException::class);

        try {
            Close::leads()->get('lead_abc123');
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function a_wait_is_skipped_when_sleep_is_faked_after_the_client_was_resolved(): void
    {
        // Sleep decides whether it is faked when each wait runs, so a test can resolve the
        // client in setUp and fake Sleep later. The test measures wall-clock time, because a real
        // sleep advances Carbon by the same amount and a Carbon check would pass against it.
        $client = Close::client();

        Sleep::fake();
        $started = microtime(true);

        Http::fakeSequence('api.close.com/*')
            ->push(['error' => 'Too many requests'], 429, ['Retry-After' => '20'])
            ->push(self::lead());

        $client->leads()->get('lead_abc123');

        $this->assertLessThan(5.0, microtime(true) - $started);
        Sleep::assertSleptTimes(1);
    }

    #[Test]
    public function an_unusable_retry_setting_names_the_configuration(): void
    {
        $this->container()->make(Config::class)->set('close.retries.max_attempts', 0);
        $this->forgetRetryPolicy();

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('close.retries');

        Close::client();
    }

    #[Test]
    public function an_application_can_bind_its_own_retry_policy(): void
    {
        $policy = new class () implements RetryPolicy {
            public int $consulted = 0;

            public function retryAfter(int $attempt, RequestInterface $request, RuntimeException $error): ?float
            {
                $this->consulted++;

                return null;
            }
        };

        $this->container()->instance(CloseServiceProvider::RETRY_POLICY, $policy);
        $this->container()->forgetInstance(CloseManager::class);

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Too many requests'], 429)]);

        try {
            Close::leads()->get('lead_abc123');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException) {
            $this->assertSame(1, $policy->consulted);
        }
    }

    #[Test]
    public function a_retry_policy_binding_of_the_wrong_type_is_named_as_configuration(): void
    {
        $this->container()->instance(CloseServiceProvider::RETRY_POLICY, new \stdClass());
        $this->container()->forgetInstance(CloseManager::class);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('close.retry_policy must be a Hampel\CloseApi\Http\RetryPolicy; it resolved to stdClass');

        $this->container()->make(CloseManager::class);
    }

    private function forgetRetryPolicy(): void
    {
        $this->container()->forgetInstance(CloseServiceProvider::RETRY_POLICY);
        $this->container()->forgetInstance(CloseManager::class);
    }
}
