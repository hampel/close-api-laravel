<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Laravel\CloseServiceProvider;
use Hampel\CloseApi\Laravel\Facades\Close;
use Hampel\CloseApi\Laravel\Tests\Fixture\OverrideHttpClientProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * An application's override of close.http_client, registered before this package's provider.
 *
 * In a full Laravel application package providers register before the application's own, so an
 * override in AppServiceProvider comes last and would win whatever the package did. Laravel Zero
 * runs no discovery: the order is config/app.php, where AppServiceProvider is listed first and the
 * package provider is added after it. A provider that claimed the key with singleton() would
 * then replace the application's override without a word, so it uses singletonIf().
 */
final class HttpClientOverrideTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OverrideHttpClientProvider::class, CloseServiceProvider::class];
    }

    #[Test]
    public function an_override_registered_before_this_provider_supplies_the_transport(): void
    {
        Http::preventStrayRequests();

        $this->assertSame('From The Override', Close::leads()->get('lead_abc123')['name']);
        $this->assertSame(['https://api.close.com/api/v1/lead/lead_abc123/'], OverrideHttpClientProvider::$client->sent ?? []);
    }

    protected function tearDown(): void
    {
        OverrideHttpClientProvider::$client = null;

        parent::tearDown();
    }
}
