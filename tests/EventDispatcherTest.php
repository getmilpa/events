<?php

/**
 * This file is part of Milpa Events — the string-named event dispatch system of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/events
 */

declare(strict_types=1);

namespace Milpa\Eventing\Tests;

use PHPUnit\Framework\TestCase;
use Milpa\Eventing\EventDispatcher;
use Psr\Log\LoggerInterface;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = new EventDispatcher($this->logger);
    }

    public function testSubscribeAndDispatch(): void
    {
        $called = false;
        $receivedPayload = null;

        $this->dispatcher->subscribe('user.created', function ($eventName, $payload) use (&$called, &$receivedPayload) {
            $called = true;
            $receivedPayload = $payload;
        });

        $this->dispatcher->dispatch('user.created', ['user_id' => 123]);

        $this->assertTrue($called);
        $this->assertEquals(['user_id' => 123], $receivedPayload);
    }

    public function testDispatchWithoutSubscribers(): void
    {
        // Should not throw
        $this->dispatcher->dispatch('unknown.event', ['data' => 'test']);
        $this->assertTrue(true);
    }

    public function testMultipleSubscribersForSameEvent(): void
    {
        $callOrder = [];

        $this->dispatcher->subscribe('order.placed', function () use (&$callOrder) {
            $callOrder[] = 'handler1';
        });

        $this->dispatcher->subscribe('order.placed', function () use (&$callOrder) {
            $callOrder[] = 'handler2';
        });

        $this->dispatcher->dispatch('order.placed');

        $this->assertCount(2, $callOrder);
        $this->assertContains('handler1', $callOrder);
        $this->assertContains('handler2', $callOrder);
    }

    public function testSubscriberPriority(): void
    {
        $callOrder = [];

        $this->dispatcher->subscribe('test.event', function () use (&$callOrder) {
            $callOrder[] = 'low';
        }, 0);

        $this->dispatcher->subscribe('test.event', function () use (&$callOrder) {
            $callOrder[] = 'high';
        }, 100);

        $this->dispatcher->subscribe('test.event', function () use (&$callOrder) {
            $callOrder[] = 'medium';
        }, 50);

        $this->dispatcher->dispatch('test.event');

        $this->assertEquals(['high', 'medium', 'low'], $callOrder);
    }

    public function testWildcardSubscriptionWithDot(): void
    {
        $calledEvents = [];

        $this->dispatcher->subscribe('user.*', function ($eventName) use (&$calledEvents) {
            $calledEvents[] = $eventName;
        });

        $this->dispatcher->dispatch('user.created');
        $this->dispatcher->dispatch('user.updated');
        $this->dispatcher->dispatch('order.created');

        $this->assertCount(2, $calledEvents);
        $this->assertContains('user.created', $calledEvents);
        $this->assertContains('user.updated', $calledEvents);
        $this->assertNotContains('order.created', $calledEvents);
    }

    public function testWildcardSubscriptionPrefix(): void
    {
        $calledEvents = [];

        $this->dispatcher->subscribe('*.created', function ($eventName) use (&$calledEvents) {
            $calledEvents[] = $eventName;
        });

        $this->dispatcher->dispatch('user.created');
        $this->dispatcher->dispatch('order.created');
        $this->dispatcher->dispatch('user.updated');

        $this->assertCount(2, $calledEvents);
        $this->assertContains('user.created', $calledEvents);
        $this->assertContains('order.created', $calledEvents);
    }

    public function testHasSubscribers(): void
    {
        $this->assertFalse($this->dispatcher->hasSubscribers('test.event'));

        $this->dispatcher->subscribe('test.event', fn () => null);

        $this->assertTrue($this->dispatcher->hasSubscribers('test.event'));
    }

    public function testHasSubscribersWithWildcard(): void
    {
        $this->dispatcher->subscribe('user.*', fn () => null);

        $this->assertTrue($this->dispatcher->hasSubscribers('user.created'));
        $this->assertTrue($this->dispatcher->hasSubscribers('user.deleted'));
        $this->assertFalse($this->dispatcher->hasSubscribers('order.created'));
    }

    public function testGetSubscribers(): void
    {
        $handler1 = fn () => 'handler1';
        $handler2 = fn () => 'handler2';

        $this->dispatcher->subscribe('test.event', $handler1);
        $this->dispatcher->subscribe('test.event', $handler2);

        $subscribers = $this->dispatcher->getSubscribers('test.event');

        $this->assertCount(2, $subscribers);
    }

    public function testGetSubscribersIncludesWildcard(): void
    {
        $this->dispatcher->subscribe('user.created', fn () => 'exact');
        $this->dispatcher->subscribe('user.*', fn () => 'wildcard');

        $subscribers = $this->dispatcher->getSubscribers('user.created');

        $this->assertCount(2, $subscribers);
    }

    public function testAsyncDispatch(): void
    {
        $asyncCalled = false;
        $asyncPayload = null;

        $this->dispatcher->setAsyncDispatcher(function ($eventName, $payload) use (&$asyncCalled, &$asyncPayload) {
            $asyncCalled = true;
            $asyncPayload = $payload;
        });

        // Regular subscriber should NOT be called for async
        $syncCalled = false;
        $this->dispatcher->subscribe('async.event', function () use (&$syncCalled) {
            $syncCalled = true;
        });

        $this->dispatcher->dispatch('async.event', ['key' => 'value'], true);

        $this->assertTrue($asyncCalled);
        $this->assertEquals(['key' => 'value'], $asyncPayload);
        $this->assertFalse($syncCalled);
    }

    public function testAsyncDispatchWithoutDispatcherFallsToSync(): void
    {
        $syncCalled = false;

        $this->dispatcher->subscribe('fallback.event', function () use (&$syncCalled) {
            $syncCalled = true;
        });

        // No async dispatcher set, should fall back to sync
        $this->dispatcher->dispatch('fallback.event', [], true);

        $this->assertTrue($syncCalled);
    }

    public function testHandlerErrorDoesNotStopOtherHandlers(): void
    {
        $handler2Called = false;

        $this->dispatcher->subscribe('error.event', function () {
            throw new \RuntimeException('Handler error');
        });

        $this->dispatcher->subscribe('error.event', function () use (&$handler2Called) {
            $handler2Called = true;
        });

        $this->dispatcher->dispatch('error.event');

        $this->assertTrue($handler2Called);
    }

    public function testGetRegisteredPatterns(): void
    {
        $this->dispatcher->subscribe('user.created', fn () => null);
        $this->dispatcher->subscribe('order.*', fn () => null);
        $this->dispatcher->subscribe('*.deleted', fn () => null);

        $patterns = $this->dispatcher->getRegisteredPatterns();

        $this->assertCount(3, $patterns);
        $this->assertContains('user.created', $patterns);
        $this->assertContains('order.*', $patterns);
        $this->assertContains('*.deleted', $patterns);
    }

    public function testEventNamePassedToHandler(): void
    {
        $receivedEventName = null;

        $this->dispatcher->subscribe('test.event', function ($eventName) use (&$receivedEventName) {
            $receivedEventName = $eventName;
        });

        $this->dispatcher->dispatch('test.event');

        $this->assertEquals('test.event', $receivedEventName);
    }

    public function testEmptyPayload(): void
    {
        $receivedPayload = null;

        $this->dispatcher->subscribe('no.payload', function ($eventName, $payload) use (&$receivedPayload) {
            $receivedPayload = $payload;
        });

        $this->dispatcher->dispatch('no.payload');

        $this->assertEquals([], $receivedPayload);
    }

    public function testNegativePriority(): void
    {
        $callOrder = [];

        $this->dispatcher->subscribe('priority.test', function () use (&$callOrder) {
            $callOrder[] = 'negative';
        }, -10);

        $this->dispatcher->subscribe('priority.test', function () use (&$callOrder) {
            $callOrder[] = 'zero';
        }, 0);

        $this->dispatcher->dispatch('priority.test');

        $this->assertEquals(['zero', 'negative'], $callOrder);
    }

    // ========== Additional Tests for Coverage ==========

    public function testGetSubscribersWithNonWildcardPatternNotMatchingEvent(): void
    {
        // Subscribe to a non-wildcard pattern
        $this->dispatcher->subscribe('user.created', fn () => null);

        // Get subscribers for a different event - this tests the branch where
        // a non-wildcard pattern doesn't match the event name
        $subscribers = $this->dispatcher->getSubscribers('order.created');

        $this->assertEmpty($subscribers);
    }

    public function testMultipleWildcardPatterns(): void
    {
        $calledPatterns = [];

        $this->dispatcher->subscribe('user.*', function ($eventName) use (&$calledPatterns) {
            $calledPatterns[] = 'user.*';
        });

        $this->dispatcher->subscribe('*.updated', function ($eventName) use (&$calledPatterns) {
            $calledPatterns[] = '*.updated';
        });

        $this->dispatcher->dispatch('user.updated');

        // Both patterns should match
        $this->assertCount(2, $calledPatterns);
        $this->assertContains('user.*', $calledPatterns);
        $this->assertContains('*.updated', $calledPatterns);
    }

    public function testWildcardPatternWithMultipleParts(): void
    {
        $called = false;

        // This pattern should NOT match because * only matches one segment
        $this->dispatcher->subscribe('app.*', function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch('app.user.created');

        // 'app.*' only matches 'app.something', not 'app.user.created'
        $this->assertFalse($called);
    }

    public function testExactMatchAndWildcardPriorityOrder(): void
    {
        $callOrder = [];

        // Higher priority exact match
        $this->dispatcher->subscribe('test.event', function () use (&$callOrder) {
            $callOrder[] = 'exact-high';
        }, 100);

        // Lower priority wildcard
        $this->dispatcher->subscribe('test.*', function () use (&$callOrder) {
            $callOrder[] = 'wildcard-low';
        }, 0);

        // Medium priority exact match
        $this->dispatcher->subscribe('test.event', function () use (&$callOrder) {
            $callOrder[] = 'exact-medium';
        }, 50);

        $this->dispatcher->dispatch('test.event');

        $this->assertEquals(['exact-high', 'exact-medium', 'wildcard-low'], $callOrder);
    }

    public function testGetRegisteredPatternsWhenEmpty(): void
    {
        $patterns = $this->dispatcher->getRegisteredPatterns();

        $this->assertEmpty($patterns);
        $this->assertIsArray($patterns);
    }

    // ========== Wildcard grammar (documented on MilpaEventDispatcherInterface) ==========

    public function testBareStarMatchesOnlySingleSegmentEventNames(): void
    {
        $calledEvents = [];

        $this->dispatcher->subscribe('*', function ($eventName) use (&$calledEvents) {
            $calledEvents[] = $eventName;
        });

        $this->dispatcher->dispatch('boot');
        $this->dispatcher->dispatch('ready');
        $this->dispatcher->dispatch('user.created'); // dotted — must NOT match bare '*'

        $this->assertCount(2, $calledEvents);
        $this->assertContains('boot', $calledEvents);
        $this->assertContains('ready', $calledEvents);
        $this->assertNotContains('user.created', $calledEvents);
    }

    public function testWildcardMatchingIsCaseSensitive(): void
    {
        $called = false;

        $this->dispatcher->subscribe('User.*', function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch('user.created');

        $this->assertFalse($called, 'wildcard matching must be case-sensitive');
    }

    public function testWildcardMatchingIsAnchoredAgainstPartialNames(): void
    {
        $called = false;

        $this->dispatcher->subscribe('*.created', function () use (&$called) {
            $called = true;
        });

        // 'user.createdX' shares the '.created' substring but is a different,
        // longer segment — anchored matching (^...$) must reject it.
        $this->dispatcher->dispatch('user.createdX');

        $this->assertFalse($called, 'wildcard regex must be anchored, not a substring match');
    }
}
