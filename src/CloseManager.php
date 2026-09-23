<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel;

use BadMethodCallException;
use Hampel\CloseApi\Auth\ApiKey;
use Hampel\CloseApi\Auth\Authentication;
use Hampel\CloseApi\Close;
use Hampel\CloseApi\Http\RetryPolicy;
use Hampel\CloseApi\Http\Sleeper;
use Hampel\CloseApi\Http\Transport;
use Hampel\CloseApi\Laravel\Exception\InvalidConfiguration;
use Hampel\CloseApi\Laravel\Exception\UnknownAccount;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One Close client per configured account.
 *
 *     Close::leads()->list();                     // the default account
 *     Close::client('sales')->leads()->list();    // a named one
 *
 * Clients are memoised per name. The transport underneath them is not - see
 * PendingRequestClient, which resolves Laravel's HTTP factory at the moment of sending so that
 * `Http::fake()` works whenever it is called.
 *
 * Each client gets a Transport of its own, because the core package keeps the most recent
 * rate limit on the Transport (`lastRateLimit()`), and Close limits each API key separately.
 * A Transport shared between two keys would report whichever key made the most recent call.
 *
 * The Transport is built here rather than through `Close::withKey()`, because withKey() takes no
 * base URI, and this package passes the configured one. It also passes LaravelSleeper, which lets
 * `Sleep::fake()` skip the retry waits.
 *
 * The @mixin lets static analysis follow $manager->leads(): __call() forwards any method the
 * client has. The facade repeats the list explicitly, because __callStatic() can only be typed
 * with @method static lines, and a test checks that copy against the client.
 *
 * @mixin Close
 */
final class CloseManager
{
    /** @var array<string, Close> */
    private array $clients = [];

    private ?string $baseUri = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RetryPolicy $retryPolicy,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The client for a configured account, or for the default when no name is given.
     */
    public function client(?string $name = null): Close
    {
        $name ??= $this->getDefaultAccount();

        return $this->clients[$name] ??= $this->build($name);
    }

    /**
     * A client for a credential that is not in the configuration - an OAuth access token held
     * for one Close user, most often.
     *
     * It uses the same transport, retry policy and logger as a configured account, so
     * `Http::fake()` sees its requests too. It is not memoised: a token changes when it is
     * refreshed, and a cache keyed on it would keep every expired one.
     */
    public function withAuthentication(Authentication $auth): Close
    {
        return new Close($this->transport($auth));
    }

    public function getDefaultAccount(): string
    {
        $default = $this->config->get('close.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured account names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredAccounts(): array
    {
        $accounts = $this->config->get('close.accounts');

        return is_array($accounts) ? array_values(array_filter(array_keys($accounts), 'is_string')) : [];
    }

    private function build(string $name): Close
    {
        $settings = $this->config->get('close.accounts.' . $name);

        if (! is_array($settings)) {
            throw UnknownAccount::named($name, $this->configuredAccounts());
        }

        $key = $settings['key'] ?? null;

        // Empty counts as missing. An unset environment variable reaches config as "" as often
        // as null, and the core package would reject "" with a message about an argument
        // rather than about the variable nobody set.
        if (! is_string($key) || trim($key) === '') {
            throw InvalidConfiguration::missingKey($name);
        }

        return new Close($this->transport(new ApiKey($key)));
    }

    private function transport(Authentication $auth): Transport
    {
        return new Transport(
            auth: $auth,
            httpClient: $this->client,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            retryPolicy: $this->retryPolicy,
            sleeper: $this->sleeper,
            logger: $this->logger,
            baseUri: $this->baseUri(),
        );
    }

    /**
     * The configured API root, checked once and memoised.
     *
     * The core package does not check the base URI it is given. Without the check here, a value
     * with no scheme would fail on the first request as a transport error naming a host, not as
     * a setting to fix.
     */
    private function baseUri(): string
    {
        if ($this->baseUri !== null) {
            return $this->baseUri;
        }

        $configured = $this->config->get('close.base_uri');

        if (! is_string($configured) || trim($configured) === '') {
            return $this->baseUri = Transport::BASE_URI;
        }

        $configured = trim($configured);
        $scheme = parse_url($configured, PHP_URL_SCHEME);
        $host = parse_url($configured, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw InvalidConfiguration::baseUri($configured);
        }

        return $this->baseUri = $configured;
    }

    /**
     * Any other call goes to the default account's client, so an application with one account
     * never has to name it: `Close::leads()` rather than `Close::client('main')->leads()`.
     *
     * The facade has a @method line for each of these, and that is where their types come
     * from. See Facades\Close, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Close::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
