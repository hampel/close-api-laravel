<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Exception;

use Hampel\CloseApi\Exception\InvalidArgumentException;

/**
 * An account was requested by a name that the configuration does not contain.
 *
 * A CloseApiException through the core package's InvalidArgumentException, so an application
 * that already catches CloseApiException also catches a mistyped account name.
 */
final class UnknownAccount extends InvalidArgumentException
{
    /**
     * @param  list<string>  $configured
     */
    public static function named(string $name, array $configured): self
    {
        return new self(sprintf(
            'Close account "%s" is not configured. %s',
            $name,
            $configured === []
                ? 'No accounts are configured; add one under close.accounts.'
                : 'Configured accounts: ' . implode(', ', $configured) . '.'
        ));
    }
}
