<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Exception;

use Hampel\CloseApi\Exception\InvalidArgumentException;

/**
 * The configuration cannot be turned into a client.
 *
 * Each of these is refused when the client is built, because otherwise it fails later and
 * points the wrong way. A missing key would fail on the first request as a 401, which looks
 * like a revoked key when the real cause is an unset environment variable. A base URI with no
 * scheme would fail as a transport error that names a host, not a setting.
 *
 * Extends the core package's InvalidArgumentException, so it is also a CloseApiException, and
 * one `catch (CloseApiException)` covers a misconfiguration as well as a failed request.
 */
final class InvalidConfiguration extends InvalidArgumentException
{
    public static function missingKey(string $account): self
    {
        return new self(sprintf(
            'Close account "%s" has no API key. Set it in config/close.php, or set CLOSE_API_KEY '
            . 'if the shipped config is in use. Every Close endpoint needs a credential, so a '
            . 'request without one cannot succeed.',
            $account
        ));
    }

    public static function baseUri(string $baseUri): self
    {
        return new self(sprintf(
            'close.base_uri is "%s", which is not an absolute http or https URL. Use the API '
            . 'root including its version segment, such as https://api.close.com/api/v1/, or '
            . 'leave it unset for Close\'s own host.',
            $baseUri
        ));
    }

    /**
     * The retry settings were refused by the core package's DefaultRetryPolicy.
     *
     * Wrapped, so the message names the setting to change, with the core package's exception
     * kept as the previous one.
     */
    public static function retries(\Throwable $previous): self
    {
        return new self(
            'The close.retries settings do not describe a usable retry policy. '
            . $previous->getMessage(),
            0,
            $previous
        );
    }

    /**
     * A container key that an application rebinds holds the wrong type.
     */
    public static function binding(string $key, string $expected, string $type): self
    {
        return new self(sprintf(
            'The container binding %s must be a %s; it resolved to %s.',
            $key,
            $expected,
            $type
        ));
    }
}
