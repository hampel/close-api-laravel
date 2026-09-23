<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\CloseApi\Exception\InvalidArgumentException;
use Hampel\CloseApi\Http\DefaultRetryPolicy;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Laravel\Exception\InvalidConfiguration;
use Hampel\CloseApi\Laravel\Http\LaravelSleeper;
use Hampel\CloseApi\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the Close API manager in the container.
 *
 * The transport is bound under this package's own key, HTTP_CLIENT, and the manager is built
 * from that key alone. Rebind or decorate it and every account's client uses the replacement.
 * The default sends through Laravel's HTTP client, which is what lets Http::fake() see this
 * package's traffic - see PendingRequestClient.
 *
 * `Psr\Http\Client\ClientInterface` is deliberately not used. It is a single key in the
 * container, and other API wrappers bind it too. singleton() on a key that is already bound
 * replaces it, so the provider registered last would supply the transport for every package:
 * one package's timeouts applied to another's requests. For the same reason the manager does not
 * fall back to a ClientInterface binding when one exists, because another package may be the one
 * that made it.
 */
final class CloseServiceProvider extends ServiceProvider
{
    /**
     * The container key this package's PSR-18 transport is bound under - the override point for
     * an application that must send this package's requests through a client of its own.
     */
    public const HTTP_CLIENT = 'close.http_client';

    /**
     * The container key the retry policy is bound under. The default is the core package's
     * DefaultRetryPolicy, built from close.retries; bind any RetryPolicy here to replace it.
     */
    public const RETRY_POLICY = 'close.retry_policy';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/close.php', 'close');

        // A full Laravel application binds the HTTP client factory as a singleton, in
        // FoundationServiceProvider. A Laravel Zero application does not register that
        // provider, and nothing else in it binds the factory. Left unbound, the container
        // builds a new Factory on every make(), so the one this package uses is not the one
        // the Http facade configures, and Http::fake() does not intercept: the request goes to
        // the real API.
        //
        // It is dangerous because Http::fake() can appear to work. fake() calls
        // Facade::swap(), which binds its instance into the container, so faking BEFORE the
        // client is resolved happens to work and faking after it does not. Binding a singleton
        // here makes the order irrelevant on both platforms.
        //
        // singletonIf, so a full Laravel application keeps the framework's own binding.
        $this->app->singletonIf(HttpClientFactory::class, static fn (Container $app): HttpClientFactory => new HttpClientFactory(
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's implements both interfaces and laravel/framework requires it, so this
        // fallback resolves in every application the package can run in. Binding them also
        // means the core package's Psr17Discovery never runs here.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        // singletonIf, so an application's override survives whichever order the providers
        // register in. A full Laravel application registers its own providers after discovered
        // ones, so its override would win anyway. Laravel Zero runs no discovery, and its
        // config/app.php lists AppServiceProvider before a package provider added after it -
        // where singleton() would silently replace the override. No other package binds this
        // key, so the shared-binding collision described above cannot happen here.
        $this->app->singletonIf(self::HTTP_CLIENT, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // Resolve the Factory from the container on every send rather than once here, so
            // Http::fake() and Http::assertSent() see this package's requests, including after
            // Http::swap() has replaced the factory.
            $app = $this->app;

            return new PendingRequestClient(
                static fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                $this->number($config->get('close.timeout'), 10.0),
                $this->number($config->get('close.connect_timeout'), 5.0),
            );
        });

        // singletonIf for the same reason as the transport.
        $this->app->singletonIf(self::RETRY_POLICY, function (): RetryPolicy {
            $config = $this->app->make(Config::class);

            try {
                return new DefaultRetryPolicy(
                    (int) $this->number($config->get('close.retries.max_attempts'), 3),
                    $this->number($config->get('close.retries.base_delay'), 0.5),
                    $this->number($config->get('close.retries.max_delay'), 60.0),
                );
            } catch (InvalidArgumentException $e) {
                throw InvalidConfiguration::retries($e);
            }
        });

        $this->app->singleton(CloseManager::class, function (): CloseManager {
            $client = $this->app->make(self::HTTP_CLIENT);

            if (! $client instanceof ClientInterface) {
                throw InvalidConfiguration::binding(self::HTTP_CLIENT, ClientInterface::class, get_debug_type($client));
            }

            $retryPolicy = $this->app->make(self::RETRY_POLICY);

            if (! $retryPolicy instanceof RetryPolicy) {
                throw InvalidConfiguration::binding(self::RETRY_POLICY, RetryPolicy::class, get_debug_type($retryPolicy));
            }

            return new CloseManager(
                $this->app->make(Config::class),
                $client,
                $this->app->make(RequestFactoryInterface::class),
                $this->app->make(StreamFactoryInterface::class),
                $retryPolicy,
                new LaravelSleeper(),
                $this->app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // configPath() rather than the config_path() helper. The helper is defined by
            // illuminate/foundation, which this package does not and should not require. The
            // package claims to need no full application, and Testbench, which boots one,
            // would never notice the helper breaking that claim.
            $this->publishes([
                __DIR__ . '/../config/close.php' => $this->app->configPath('close.php'),
            ], 'close-config');
        }
    }

    private function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
