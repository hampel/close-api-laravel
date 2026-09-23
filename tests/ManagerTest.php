<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Auth\BearerToken;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Exception\CloseApiException;
use Hampel\CloseApi\Laravel\CloseManager;
use Hampel\CloseApi\Laravel\Exception\InvalidConfiguration;
use Hampel\CloseApi\Laravel\Exception\UnknownAccount;
use Hampel\CloseApi\Laravel\Facades\Close as CloseFacade;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_account(): void
    {
        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        $this->manager()->client('sales')->leads()->get('lead_abc123');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', self::basic(self::SALES_KEY)));
    }

    #[Test]
    public function it_defaults_to_the_configured_account(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultAccount());
        $this->assertSame(
            $this->manager()->client('main'),
            $this->manager()->client(),
        );
    }

    #[Test]
    public function clients_are_memoised_per_account(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->client('main'), $manager->client('main'));
        $this->assertNotSame($manager->client('main'), $manager->client('sales'));
    }

    #[Test]
    public function each_account_reports_its_own_rate_limit(): void
    {
        // Close limits each API key separately, and the core package records the most recent
        // RateLimit header on the Transport. If two accounts shared a Transport, one account's
        // lastRateLimit() would show whichever key had made the latest call.
        $manager = $this->manager();

        $this->assertNotSame($manager->client('main')->transport(), $manager->client('sales')->transport());

        Http::fake([
            'api.close.com/*' => Http::response(self::lead(), 200, ['RateLimit' => 'limit=80, remaining=79, reset=1']),
        ]);

        $manager->client('sales')->leads()->get('lead_abc123');

        $this->assertNull($manager->client('main')->transport()->lastRateLimit());
        $this->assertSame(79, $manager->client('sales')->transport()->lastRateLimit()?->remaining);
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(CloseManager::class));
        $this->assertSame($this->manager(), CloseFacade::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_accounts(): void
    {
        $this->assertSame(['main', 'sales'], $this->manager()->configuredAccounts());
    }

    #[Test]
    public function an_unknown_account_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('Configured accounts: main, sales.');

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        // They extend the core package's exceptions rather than defining a separate hierarchy,
        // so an application that already catches CloseApiException also catches a
        // misconfiguration instead of meeting it as an unhandled error.
        $this->expectException(CloseApiException::class);

        $this->manager()->client('nope');
    }

    #[Test]
    public function an_account_without_a_key_is_refused(): void
    {
        // Refused here, rather than left to fail with a 401 on the first request, which would
        // look like a revoked key rather than an unset environment variable.
        $this->configure('nowhere', []);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no API key');

        $this->manager()->client('nowhere');
    }

    #[Test]
    public function an_empty_key_is_treated_as_absent(): void
    {
        $this->configure('blank', ['key' => '  ']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no API key');

        $this->manager()->client('blank');
    }

    #[Test]
    public function a_base_uri_without_a_scheme_is_refused_before_a_request_is_spent(): void
    {
        // The core package does not check its base URI, so without this a missing scheme would
        // show up as a transport error on the first request.
        $this->container()->make(Config::class)->set('close.base_uri', 'api.close.com/api/v1/');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('close.base_uri');

        $this->manager()->client();
    }

    #[Test]
    public function an_unset_default_falls_back_to_main(): void
    {
        $this->container()->make(Config::class)->set('close.default', '');

        $this->assertSame('main', $this->manager()->getDefaultAccount());
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_account(): void
    {
        $this->assertSame(
            $this->manager()->client()->transport(),
            $this->manager()->transport(),
        );
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        // Called through __call() explicitly, because that is the method under test: it turns
        // a typo into a named error rather than a fatal error one call further in.
        $this->manager()->__call('noSuchResource', []);
    }

    #[Test]
    public function a_client_for_an_unconfigured_credential_is_built_fresh_each_time(): void
    {
        // Built fresh, not cached: an OAuth token changes when it is refreshed, and a cache
        // keyed on the token would keep every expired one.
        $manager = $this->manager();
        $token = new BearerToken('oauth-access-token');

        $client = $manager->withAuthentication($token);

        $this->assertInstanceOf(Close::class, $client);
        $this->assertNotSame($client, $manager->withAuthentication($token));
        $this->assertNotSame($manager->client(), $client);
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        // An application that reads its account list from somewhere other than config needs
        // to be able to report WHY the list is empty, rather than have every command fail.
        // Nothing here throws until a specific account is named, so a diagnostic command can
        // resolve the manager and describe the problem.
        $this->container()->make(Config::class)->set('close.accounts', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredAccounts());
        $this->assertSame('main', $manager->getDefaultAccount());

        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('No accounts are configured');

        $manager->client('somewhere');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $accounts = $config->get('close.accounts');
        $config->set('close.accounts', array_merge(is_array($accounts) ? $accounts : [], [$name => $settings]));
    }

    private function manager(): CloseManager
    {
        return $this->container()->make(CloseManager::class);
    }
}
