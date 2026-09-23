<?php

declare(strict_types=1);

namespace Hampel\CloseApi\Laravel\Tests;

use Hampel\CloseApi\Close as Client;
use Hampel\CloseApi\Laravel\Facades\Close;
use Hampel\CloseApi\Laravel\CloseManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The facade's @method annotations are the only types a call through it has.
 *
 * Close::leads() goes through the manager's __call() and returns mixed; what makes
 * `Close::leads()->list()` analysable is the annotation, not the code. So an accessor added
 * to the core package's Client in a later release is a call that works at runtime and loses
 * its type silently - the failure mode a facade is most prone to, and the one nothing else here
 * would report. The core package is 0.x and expected to grow accessors, which makes this
 * cheaper to have from the start than to add after the first one is missed.
 *
 * Deliberately not a Testbench test: it reads classes, boots nothing, and is quicker to reach
 * for when the core package is upgraded.
 */
final class FacadeConformanceTest extends BaseTestCase
{
    #[Test]
    public function every_client_method_is_annotated_on_the_facade(): void
    {
        $missing = array_diff($this->clientMethods(), $this->annotatedMethods());

        $this->assertSame([], array_values($missing), sprintf(
            'The core package has %s that the facade does not annotate. Add a @method line for '
            . 'each to %s, or calls through the facade lose their return type.',
            implode(', ', $missing),
            Close::class
        ));
    }

    #[Test]
    public function the_facade_annotates_nothing_that_no_longer_exists(): void
    {
        $known = array_merge($this->clientMethods(), $this->managerMethods());
        $stale = array_diff($this->annotatedMethods(), $known);

        $this->assertSame([], array_values($stale), sprintf(
            'The facade annotates %s, which neither %s nor %s has.',
            implode(', ', $stale),
            Client::class,
            CloseManager::class
        ));
    }

    #[Test]
    public function every_annotated_return_type_resolves_to_a_real_class(): void
    {
        // A @method line naming a class that does not exist is invisible at runtime and
        // silently useless to static analysis, which is the same failure as not having one.
        $doc = (string) (new ReflectionClass(Close::class))->getDocComment();

        preg_match_all('/@method static ([^ ]+) /', $doc, $matches);

        foreach ($matches[1] as $type) {
            if (in_array($type, ['string', 'int', 'bool', 'void', 'mixed'], true) || str_contains($type, '<')) {
                continue;
            }

            $this->assertTrue(
                class_exists($this->resolve($type)) || interface_exists($this->resolve($type)),
                sprintf('The facade annotates a return type that does not exist: %s', $type)
            );
        }
    }

    #[Test]
    public function the_manager_does_not_shadow_a_client_method(): void
    {
        // The manager forwards unknown calls to the default client. A method that exists on
        // both would mean different things depending on which class the caller had in mind,
        // and the client's version could not be reached through the facade. Close has no
        // client() or withAuthentication() today; this fails if a later release adds one.
        $shadowed = array_intersect($this->managerMethods(), $this->clientMethods());

        $this->assertSame([], array_values($shadowed), sprintf(
            '%s declares %s, which %s also has - so the facade cannot reach the client\'s.',
            CloseManager::class,
            implode(', ', $shadowed),
            Client::class
        ));
    }

    /**
     * @return list<string>
     */
    private function annotatedMethods(): array
    {
        $doc = (string) (new ReflectionClass(Close::class))->getDocComment();

        preg_match_all('/@method static [^ ]+ ([A-Za-z0-9_]+)\(/', $doc, $matches);

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function clientMethods(): array
    {
        return $this->publicMethods(Client::class);
    }

    /**
     * @return list<string>
     */
    private function managerMethods(): array
    {
        return array_values(array_diff($this->publicMethods(CloseManager::class), ['__call']));
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function publicMethods(string $class): array
    {
        $methods = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isConstructor() && ! $method->isStatic()) {
                $methods[] = $method->getName();
            }
        }

        sort($methods);

        return $methods;
    }

    /**
     * Annotations are written short, against the facade's own imports - including an aliased
     * one: the core package's entry point is Hampel\CloseApi\Close, which has the facade's own
     * short name, so it is imported as Client.
     */
    private function resolve(string $type): string
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(Close::class))->getFileName());

        preg_match_all('/^use ([^; ]+)(?: as ([A-Za-z0-9_]+))?;$/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $alias = $match[2] ?? '';

            if ($alias === $type || ($alias === '' && str_ends_with($match[1], '\\' . $type))) {
                return $match[1];
            }
        }

        return $type;
    }
}
