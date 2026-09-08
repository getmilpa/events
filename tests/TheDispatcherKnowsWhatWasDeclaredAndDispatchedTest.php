<?php

/**
 * This file is part of Milpa Events — the string-named event dispatch system of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/events
 */

declare(strict_types=1);

namespace Milpa\Eventing\Tests;

use Milpa\Eventing\EventDispatcher;
use Milpa\Eventing\InterceptionSlot;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The dispatcher is the one authority on which events exist: what emitters declared to it, and what it
 * really dispatched (greenhouse decisions/0228).
 */
final class TheDispatcherKnowsWhatWasDeclaredAndDispatchedTest extends TestCase
{
    public function testItKeepsDeclarationsInOrderAndTheFirstOfANameWins(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        self::assertInstanceOf(DeclaredEvents::class, $dispatcher);
        self::assertSame([], $dispatcher->declared());

        $dispatcher->declare(
            new EventDeclaration('kernel.booted', 'Kernel', 'after every plugin booted'),
            new EventDeclaration('plugin.booting', 'Boot', 'before a plugin boots', 'slot', InterceptionSlot::class, interceptable: true),
        );
        $dispatcher->declare(new EventDeclaration('kernel.booted', 'Someone else', 'a second opinion'));

        self::assertSame(['kernel.booted', 'plugin.booting'], array_map(static fn (EventDeclaration $d): string => $d->name, $dispatcher->declared()));
        self::assertSame('Kernel', $dispatcher->declared()[0]->dispatchedBy, 'the first declaration of a name is kept');
    }

    public function testItRemembersEveryNameItDispatchedDeclaredOrNotWithSubscribersOrNone(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $dispatcher->declare(new EventDeclaration('kernel.booted', 'Kernel', 'after every plugin booted'));
        $dispatcher->subscribe('kernel.booted', static function (): void {
        });

        $dispatcher->dispatch('kernel.booted');
        $dispatcher->dispatch('foo.undeclared', ['x' => 1]);
        $dispatcher->dispatch('kernel.booted');

        self::assertSame(['kernel.booted', 'foo.undeclared'], $dispatcher->dispatched(), 'first occurrence first, no subscribers needed, no repeats');
    }

    public function testAQueuedDispatchIsRememberedToo(): void
    {
        $dispatcher = new EventDispatcher(new NullLogger());
        $queued = [];
        $dispatcher->setAsyncDispatcher(static function (string $name, array $payload) use (&$queued): void {
            $queued[] = $name;
        });

        $dispatcher->dispatch('job.queued', [], async: true);

        self::assertSame(['job.queued'], $queued, 'the control: it really went to the queue');
        self::assertSame(['job.queued'], $dispatcher->dispatched(), 'and the dispatcher remembers it left this way');
    }
}
