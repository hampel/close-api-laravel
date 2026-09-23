<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Auth\BearerToken;
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reason the package exists.
 *
 * hampel/close-api takes a PSR-18 client, so by default nothing it sends is visible to
 * Http::fake(), and an application testing against it has to fake at the transport library
 * instead - using terms the rest of its suite does not use. Everything below goes through the
 * core package's real request building, status mapping and exception hierarchy. Only the
 * socket is replaced.
 *
 * If this file does not pass, the package has no reason to exist.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller(): void
    {
        Http::fake([
            'api.close.com/*' => Http::response(self::lead()),
        ]);

        $lead = Close::leads()->get('lead_abc123');

        $this->assertSame('Wayne Enterprises', $lead['name']);
        $this->assertSame('a custom value', $lead['custom.cf_xyz']);
    }

    #[Test]
    public function the_request_is_recorded_for_assertion(): void
    {
        Http::fake([
            'api.close.com/*' => Http::response(self::lead()),
        ]);

        Close::leads()->get('lead_abc123');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.close.com/api/v1/lead/lead_abc123/'
            && $request->hasHeader('Authorization', self::basic(self::MAIN_KEY))
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function a_json_body_is_readable_by_the_assertion(): void
    {
        // Illuminate\Http\Client\Request::isJson() is a substring test over the Content-Type,
        // which the core package's bare `application/json` satisfies - so data() parses the
        // body and $request['name'] works in a consumer's assertion.
        Http::fake([
            'api.close.com/*' => Http::response(self::lead()),
        ]);

        Close::leads()->create(['name' => 'Wayne Enterprises', 'status_id' => 'stat_x']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.close.com/api/v1/lead/'
            && $request->isJson()
            && $request['name'] === 'Wayne Enterprises');
    }

    #[Test]
    public function a_list_query_is_visible_to_the_assertion(): void
    {
        // Close joins list parameters with commas rather than repeating them, and silently
        // ignores the repeated form - returning the unfiltered collection. What reaches the
        // wire is the core package's, and an assertion can see it.
        Http::fake([
            'api.close.com/*' => Http::response(self::page([self::lead()])),
        ]);

        $leads = Close::leads()->list(['_fields' => ['id', 'name']]);

        $this->assertCount(1, $leads->data());
        Http::assertSent(fn (Request $request): bool => $request['_fields'] === 'id,name');
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The order that a cached transport gets wrong. Factory::fake() REPLACES the factory's
        // stub collection, and createPendingRequest() copies whatever is there when it is
        // called. A Guzzle client built when the client was resolved would hold a copy taken
        // before these stubs existed, and the request would go to the real API.
        // PendingRequestClient rebuilds the request on every send, so this does not happen.
        $client = Close::client();

        Http::preventStrayRequests();
        Http::fake([
            'api.close.com/*' => Http::response(self::lead('lead_late', ['name' => 'Late Lead'])),
        ]);

        $this->assertSame('Late Lead', $client->leads()->get('lead_late')['name']);
    }

    #[Test]
    public function swapping_the_factory_after_the_client_was_resolved_still_intercepts(): void
    {
        // Http::swap(new Factory) is how a test gets a clean set of fakes, because fake()
        // merges and the first match wins. swap() binds the new factory into the container, so
        // a transport that held the factory it was built with would keep answering from the old
        // one - PendingRequestClient resolves it at the moment of sending instead.
        $client = Close::client();

        Http::fake(['api.close.com/*' => Http::response(self::lead('lead_1', ['name' => 'Before']))]);
        $this->assertSame('Before', $client->leads()->get('lead_1')['name']);

        Http::swap(new Factory());
        Http::fake(['api.close.com/*' => Http::response(self::lead('lead_1', ['name' => 'After']))]);

        $this->assertSame('After', $client->leads()->get('lead_1')['name']);
    }

    #[Test]
    public function a_swapped_factorys_stray_request_guard_applies_to_a_client_resolved_before_it(): void
    {
        // The dangerous half of the same property. preventStrayRequests() is set on the NEW
        // factory. A transport still holding the old, unfaked factory would send the request
        // for real, with the configured key, and nothing would stop it.
        //
        // The base URI is a reserved .invalid host, so if this ever regresses, the escaped
        // request fails to resolve instead of reaching Close.
        $this->container()->make('config')->set('close.base_uri', 'https://api.close.invalid/api/v1/');

        $client = Close::client();

        Http::swap(new Factory());
        Http::preventStrayRequests();

        $this->expectException(StrayRequestException::class);

        $client->leads()->get('lead_1');
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not wrapped in the core package's TransportException. The core package catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException, so it
        // arrives with Laravel's own message and the URL still in it - and the retry policy,
        // which only sees the core package's exceptions, does not retry it.
        Http::preventStrayRequests();
        Http::fake(['example.test/*' => Http::response([])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://api.close.com/api/v1/lead/lead_abc123/');

        Close::leads()->get('lead_abc123');
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // This package supplies the transport and nothing more. A 404 must arrive as
        // NotFoundException, not as an unsuccessful Response.
        Http::fake([
            'api.close.com/*' => Http::response(['error' => 'Not Found'], 404),
        ]);

        $this->expectException(NotFoundException::class);

        Close::leads()->get('lead_missing');
    }

    #[Test]
    public function an_oauth_client_sends_through_the_same_faked_transport(): void
    {
        // withAuthentication() builds a client for a credential that is not in the config. If
        // it used a transport of its own, an application holding a token for each user would
        // have its first call faked and every call after it sent to the real API.
        Http::fake([
            'api.close.com/*' => Http::response(self::lead()),
        ]);

        Close::withAuthentication(new BearerToken('oauth-access-token'))->leads()->get('lead_abc123');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(
            'Authorization',
            'Bearer oauth-access-token'
        ));
    }

    #[Test]
    public function a_cursor_search_is_faked_one_response_per_page(): void
    {
        // Searching walks cursors, so a consumer's test needs one response per page, in order.
        // fakeSequence() is the tool, and each page goes back through the core package's
        // cursor handling.
        Http::fakeSequence('api.close.com/*')
            ->push(['data' => [['id' => 'lead_1']], 'cursor' => 'next-page'])
            ->push(['data' => [['id' => 'lead_2']], 'cursor' => null]);

        $ids = [];

        foreach (Close::search()->paginate(['type' => 'object_type', 'object_type' => 'lead']) as $row) {
            $this->assertIsArray($row);
            $ids[] = $row['id'];
        }

        $this->assertSame(['lead_1', 'lead_2'], $ids);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['cursor'] ?? null) === 'next-page');
    }
}
