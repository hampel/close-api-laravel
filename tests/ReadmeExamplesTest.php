<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Auth\BearerToken;
use Hampel\CloseApi\Exception\RateLimitException;
use Hampel\CloseApi\Laravel\CloseManager;
use Hampel\CloseApi\Laravel\CloseServiceProvider;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops matching
 * what the package does. These are the ones with a signature in them, so a renamed accessor, a
 * changed argument name or a changed return type fails here rather than being discovered by
 * somebody following the documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_examples_return_what_the_readme_says_they_do(): void
    {
        Http::fake([
            'api.close.com/api/v1/lead/lead_abc123/' => Http::response(self::lead()),
            'api.close.com/api/v1/lead/*' => Http::response(self::page([self::lead('lead_1'), self::lead('lead_2')])),
        ]);

        $lead = Close::leads()->get('lead_abc123');

        $this->assertSame('Wayne Enterprises', $lead['name']);
        $this->assertSame('a custom value', $lead['custom.cf_xyz']);

        $ids = [];

        foreach (Close::leads()->paginate(['status_id' => 'stat_x']) as $row) {
            $this->assertIsArray($row);
            $ids[] = $row['id'];
        }

        $this->assertSame(['lead_1', 'lead_2'], $ids);
    }

    #[Test]
    public function a_named_account_and_an_injected_manager_are_reached_the_way_the_readme_says(): void
    {
        $this->container()->make('config')->set('close.accounts.support', ['key' => 'api_support-key']);

        Http::fake(['api.close.com/*' => Http::response(self::page([self::lead()]))]);

        Close::client('support')->leads()->list();
        $this->container()->make(CloseManager::class)->client('support')->leads()->list();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', self::basic('api_support-key')));
    }

    #[Test]
    public function the_oauth_example_sends_the_token(): void
    {
        Http::fake(['api.close.com/*' => Http::response(self::page([self::lead()]))]);

        $close = Close::withAuthentication(new BearerToken('user-access-token'));

        $close->leads()->list();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer user-access-token'));
    }

    #[Test]
    public function the_testing_example_asserts_what_the_readme_says(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.close.com/*' => Http::response(['id' => 'lead_abc123', 'name' => 'Wayne Enterprises']),
        ]);

        $lead = Close::leads()->get('lead_abc123');

        $this->assertSame('Wayne Enterprises', $lead['name']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.close.com/api/v1/lead/lead_abc123/');
    }

    #[Test]
    public function the_errors_example_reads_the_wait_from_a_rate_limit(): void
    {
        Sleep::fake();
        $this->container()->make('config')->set('close.retries.max_delay', 5);
        $this->container()->forgetInstance(CloseServiceProvider::RETRY_POLICY);
        $this->container()->forgetInstance(CloseManager::class);

        Http::fake(['api.close.com/*' => Http::response(['error' => 'Too many requests'], 429, ['Retry-After' => '30'])]);

        try {
            Close::leads()->get('lead_abc123');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(30.0, $e->waitSeconds());
        }
    }
}
