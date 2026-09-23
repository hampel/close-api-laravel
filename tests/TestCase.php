<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Laravel\CloseServiceProvider;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Testbench boots a minimal Laravel application from inside this package, so the Laravel
 * version under test comes from Composer resolution rather than from an installed framework.
 * Never install a framework to test a package.
 */
abstract class TestCase extends BaseTestCase
{
    protected const string MAIN_KEY = 'api_main-key-under-test';

    protected const string SALES_KEY = 'api_sales-key-under-test';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CloseServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Close' => Close::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('close.default', 'main');
        $app['config']->set('close.accounts', [
            'main' => ['key' => self::MAIN_KEY],
            'sales' => ['key' => self::SALES_KEY],
        ]);
        $app['config']->set('close.base_uri', null);
    }

    /**
     * The Authorization header Close expects for an API key: HTTP Basic, the key as the
     * username and an empty password - note the trailing colon.
     */
    protected static function basic(string $key): string
    {
        return 'Basic ' . base64_encode($key . ':');
    }

    /**
     * A lead as Close answers with one, trimmed to the fields the tests read.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function lead(string $id = 'lead_abc123', array $overrides = []): array
    {
        return $overrides + [
            'id' => $id,
            'name' => 'Wayne Enterprises',
            'status_id' => 'stat_x',
            'status_label' => 'Potential',
            'date_created' => '2026-09-01T03:04:05.000000+00:00',
            'custom.cf_xyz' => 'a custom value',
        ];
    }

    /**
     * The envelope an offset-paginated list answers in.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected static function page(array $items, bool $hasMore = false): array
    {
        return ['data' => $items, 'has_more' => $hasMore];
    }

    /**
     * The application, narrowed.
     *
     * Testbench declares $app as nullable because it does not exist before setUp, so every use
     * of it in a test is otherwise a call on Application|null.
     */
    protected function container(): Application
    {
        $app = $this->app;

        $this->assertNotNull($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel's HandleExceptions bootstrapper replaces PHPUnit's error handler when the
        // app boots, and shouldIgnoreDeprecationErrors() discards deprecations outright while
        // running tests - so phpunit.xml's failOnDeprecation never sees one and is inert in
        // any Testbench-based package. This throws on them instead, which is the whole point
        // of the flag: a library should hear about a deprecation before its users do.
        $this->withoutDeprecationHandling();
    }
}
