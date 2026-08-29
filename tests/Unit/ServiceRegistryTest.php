<?php

declare(strict_types=1);

namespace Nimbus\Tests\Unit;

use Nimbus\Plugin\ServiceRegistry;
use PHPUnit\Framework\TestCase;

interface GreeterPort
{
    public function greet(): string;
}

final class Greeter implements GreeterPort
{
    public function greet(): string
    {
        return 'hi';
    }
}

final class NotAGreeter
{
}

/**
 * Typed service ports between plugins (ADR 0019, Hinge 5). The guards that keep
 * this a *contract-typed* port and not a generic locator: interface-only contracts,
 * matching implementations, one provider per contract, and fail-safe absence.
 */
final class ServiceRegistryTest extends TestCase
{
    public function test_a_provided_service_is_returned_by_its_contract(): void
    {
        $reg = new ServiceRegistry();
        $reg->provide(GreeterPort::class, new Greeter(), 'nimbuscms.greeter');

        $port = $reg->get(GreeterPort::class);
        self::assertInstanceOf(GreeterPort::class, $port);
        self::assertSame('hi', $port->greet());
    }

    public function test_an_unprovided_contract_is_null_not_an_error(): void
    {
        // Fail-safe: a consumer degrades when a collaborator isn't installed.
        self::assertNull((new ServiceRegistry())->get(GreeterPort::class));
    }

    public function test_a_contract_must_be_an_interface(): void
    {
        $reg = new ServiceRegistry();
        $this->expectException(\InvalidArgumentException::class);
        // A concrete class is not a contract — this prevents publishing internals.
        $reg->provide(Greeter::class, new Greeter(), 'p');
    }

    public function test_the_implementation_must_implement_the_contract(): void
    {
        $reg = new ServiceRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $reg->provide(GreeterPort::class, new NotAGreeter(), 'p');
    }

    public function test_only_one_provider_per_contract(): void
    {
        $reg = new ServiceRegistry();
        $reg->provide(GreeterPort::class, new Greeter(), 'nimbuscms.a');

        $this->expectException(\InvalidArgumentException::class);
        // A second plugin can't shadow the first's port.
        $reg->provide(GreeterPort::class, new Greeter(), 'acme.b');
    }

    public function test_forget_provider_frees_the_contract(): void
    {
        $reg = new ServiceRegistry();
        $reg->provide(GreeterPort::class, new Greeter(), 'nimbuscms.a');
        $reg->forgetProvider('nimbuscms.a');

        self::assertNull($reg->get(GreeterPort::class));
        // Freed — another provider may now claim it.
        $reg->provide(GreeterPort::class, new Greeter(), 'acme.b');
        self::assertInstanceOf(GreeterPort::class, $reg->get(GreeterPort::class));
    }
}
