<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Laravel\CloseManager;
use Hampel\CloseApi\Laravel\CloseServiceProvider;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_account(): void
    {
        // The transport is bound under its own key, rather than built inside the manager, so an
        // application with its own outbound HTTP policy can replace it. Such an application
        // (one that sends everything through a proxy-aware, SSRF-guarded client, say) binds
        // that client here and this package uses it. The application does not have to write a
        // second API client.
        $recorder = new class () implements ClientInterface {
            /** @var list<array{string, string}> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = [(string) $request->getUri(), $request->getHeaderLine('Authorization')];

                return new \GuzzleHttp\Psr7\Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"id":"lead_1","name":"Recorded"}'
                );
            }
        };

        $this->container()->instance(CloseServiceProvider::HTTP_CLIENT, $recorder);

        Close::leads()->get('lead_1');
        Close::client('sales')->leads()->get('lead_2');

        $this->assertSame([
            ['https://api.close.com/api/v1/lead/lead_1/', self::basic(self::MAIN_KEY)],
            ['https://api.close.com/api/v1/lead/lead_2/', self::basic(self::SALES_KEY)],
        ], $recorder->sent);
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        // Rebuilding the pending request on every send is what makes this work: the
        // application's own Http:: configuration applies to this package's requests, and the
        // package needs no knowledge of it.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        Close::leads()->get('lead_abc123');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function the_configured_timeouts_reach_the_request(): void
    {
        // This does not happen by itself. PendingRequest merges its options only inside its own
        // sendRequest(), and the adapter sends through the built Guzzle client instead, so the
        // timeouts reach a request only because the adapter passes them on. The test reads them
        // at the handler, where Guzzle applies them.
        $this->container()->make(Config::class)->set('close.timeout', 7);
        $this->container()->make(Config::class)->set('close.connect_timeout', 3);
        $this->container()->forgetInstance(CloseServiceProvider::HTTP_CLIENT);
        $this->container()->forgetInstance(CloseManager::class);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        Close::leads()->get('lead_abc123');

        $this->assertSame(7.0, $options[0]['timeout'] ?? null);
        $this->assertSame(3.0, $options[0]['connect_timeout'] ?? null);
    }

    #[Test]
    public function a_global_transport_option_reaches_the_request(): void
    {
        // Http::globalOptions() is how an application sets a proxy or a CA bundle for all of
        // its outbound traffic, and the README says it applies here.
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/corporate-ca.pem',
            'proxy' => ['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']],
        ]);

        $options = $this->optionsSeenByTheHandler();

        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        Close::leads()->get('lead_abc123');

        $this->assertSame('/etc/ssl/certs/corporate-ca.pem', $options[0]['verify'] ?? null);
        $this->assertSame(['https' => 'http://proxy.example.test:3128', 'no' => ['localhost']], $options[0]['proxy'] ?? null);
    }

    #[Test]
    public function a_global_option_that_would_change_the_request_is_not_applied(): void
    {
        // The core package builds the request, and the transport sends it unchanged. A global
        // header, query or body would otherwise overwrite its Accept and Authorization headers,
        // or replace its query string or body.
        Http::globalOptions([
            'headers' => ['Accept' => 'text/html', 'Authorization' => 'Bearer not-this-one'],
            'query' => ['injected' => '1'],
            'json' => ['injected' => true],
        ]);

        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        Close::leads()->create(['name' => 'Wayne Enterprises']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.close.com/api/v1/lead/'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Authorization', self::basic(self::MAIN_KEY))
            && $request['name'] === 'Wayne Enterprises'
            && ! isset($request['injected']));
    }

    #[Test]
    public function a_get_filter_is_readable_by_the_assertion(): void
    {
        // Laravel's own client puts a GET's query into laravel_data, which is what
        // $request['status_id'] reads. This adapter is handed a finished PSR-7 request, so it
        // reads the parameters back out of the URI. Without that, the assertion a consumer is
        // most likely to write raises "Undefined array key" instead of comparing.
        Http::fake(['api.close.com/*' => Http::response(self::page([self::lead()]))]);

        Close::leads()->list(['status_id' => 'stat_x', '_limit' => 50]);

        Http::assertSent(fn (Request $request): bool => $request['status_id'] === 'stat_x'
            && $request['_limit'] === '50');
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // Measured rather than reasoned, because the reasoning is easy to get half right.
        // RequestSending is dispatched from a before-sending callback INSIDE the handler stack
        // this adapter drives, so it fires. ResponseReceived is dispatched from
        // PendingRequest::send(), a layer above that stack, which the adapter never calls - so
        // it does not, and nothing listening for it (Telescope's HTTP client watcher among
        // them) sees this package's traffic.
        $sending = 0;
        $received = 0;

        Event::listen(RequestSending::class, function () use (&$sending): void {
            $sending++;
        });
        Event::listen(ResponseReceived::class, function () use (&$received): void {
            $received++;
        });

        Http::fake(['api.close.com/*' => Http::response(self::lead())]);

        Close::leads()->get('lead_abc123');

        $this->assertSame(1, $sending);
        $this->assertSame(0, $received);
    }

    #[Test]
    public function the_configured_base_uri_is_where_the_requests_go(): void
    {
        // The setting exists for a recorded fixture served locally and for a proxy that
        // terminates the connection. The test checks the request that is sent, because a base
        // URI that was accepted but never used for a request would look configured and change
        // nothing.
        $this->container()->make(Config::class)->set('close.base_uri', 'http://localhost:8080/api/v1');

        Http::fake(['localhost:8080/*' => Http::response(self::lead())]);

        Close::leads()->get('lead_abc123');

        Http::assertSent(fn (Request $request): bool => $request->url()
            === 'http://localhost:8080/api/v1/lead/lead_abc123/');
    }

    /**
     * Records the Guzzle options of every request, as the handler receives them.
     *
     * @return \ArrayObject<int, array<array-key, mixed>>
     */
    private function optionsSeenByTheHandler(): \ArrayObject
    {
        /** @var \ArrayObject<int, array<array-key, mixed>> $seen */
        $seen = new \ArrayObject();

        Http::globalMiddleware(static fn (callable $handler): callable => static function (RequestInterface $request, array $options) use ($handler, $seen): mixed {
            $seen[] = $options;

            return $handler($request, $options);
        });

        return $seen;
    }
}
