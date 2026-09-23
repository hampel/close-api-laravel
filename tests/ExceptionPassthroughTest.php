<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Exception\AuthenticationException;
use Hampel\CloseApi\Exception\BadRequestException;
use Hampel\CloseApi\Exception\DecodeException;
use Hampel\CloseApi\Exception\ForbiddenException;
use Hampel\CloseApi\Exception\MethodNotAllowedException;
use Hampel\CloseApi\Exception\NotFoundException;
use Hampel\CloseApi\Exception\PaymentRequiredException;
use Hampel\CloseApi\Exception\ResponseException;
use Hampel\CloseApi\Exception\ServerException;
use Hampel\CloseApi\Exception\UnsupportedMediaTypeException;
use Hampel\CloseApi\Laravel\Facades\Close;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * This matters more than convenience. The core package's value is less its transport than its
 * mapping of statuses to exceptions. A client that reports every error status the same way
 * cannot tell a missing lead from an invalid key, and only the invalid key is a configuration
 * error that should fail loudly.
 *
 * Laravel's own Http:: client is what reduces them all to one kind of failure, so this package
 * must not bring that back by another route.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'a rejected value, with the field on it' => [400, BadRequestException::class],
            'an unusable key is not an empty result' => [401, AuthenticationException::class],
            'a plan that does not include the feature' => [402, PaymentRequiredException::class],
            'a forbidden action is its own failure' => [403, ForbiddenException::class],
            'a missing lead' => [404, NotFoundException::class],
            'a method the endpoint does not take' => [405, MethodNotAllowedException::class],
            'a body Close cannot read' => [415, UnsupportedMediaTypeException::class],
            'Close broke, not the caller' => [500, ServerException::class],
            'anything else the caller got wrong' => [418, ResponseException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $expected): void
    {
        // Faked, because a 500 on a GET is retried with a wait between attempts.
        Sleep::fake();

        Http::fake([
            'api.close.com/*' => Http::response(['error' => 'Something Close said.'], $status),
        ]);

        $this->expectException($expected);

        Close::leads()->get('lead_abc123');
    }

    #[Test]
    public function a_rejected_field_is_still_readable_on_the_exception(): void
    {
        // The shape the core package observed from the live API for a 400.
        Http::fake([
            'api.close.com/*' => Http::response([
                'errors' => [],
                'field-errors' => ['status_id' => 'Status does not exist.'],
            ], 400),
        ]);

        try {
            Close::leads()->create(['name' => 'Wayne Enterprises', 'status_id' => 'stat_missing']);
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertSame(['status_id' => 'Status does not exist.'], $e->fieldErrors());
            $this->assertStringContainsString('Status does not exist.', $e->getMessage());
        }
    }

    #[Test]
    public function the_exception_carries_the_request_that_caused_it(): void
    {
        Http::fake(['api.close.com/*' => Http::response(['error' => 'Not Found'], 404)]);

        try {
            Close::leads()->get('lead_missing');
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->status());
            $this->assertSame('GET', $e->method());
            $this->assertSame('https://api.close.com/api/v1/lead/lead_missing/', $e->uri());
        }
    }

    #[Test]
    public function faking_with_no_arguments_fails_loudly_rather_than_reporting_no_leads(): void
    {
        // Http::fake() with no arguments answers every request with an empty 200, and is the
        // easiest mistake to make in a consumer's suite. Close never answers a 2xx with an empty
        // body (the core package checked eighteen GET endpoints and DELETE, which returns {}), so
        // from hampel/close-api 0.1.3 an empty body raises instead of reading as an empty result.
        Http::fake();

        $this->expectException(DecodeException::class);

        Close::leads()->list();
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a WAF challenge, a CDN interstitial and a truncated body all
        // arrive as a 200 with something other than JSON. Returned as an empty result, each
        // would look like "this organization has no leads" to everything that used it.
        Http::fake([
            'api.close.com/*' => Http::response('<html><body>Down for maintenance</body></html>', 200),
        ]);

        $this->expectException(DecodeException::class);

        Close::leads()->list();
    }
}
