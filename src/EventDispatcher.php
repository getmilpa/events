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

namespace Milpa\Eventing;

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Reference implementation of {@see MilpaEventDispatcherInterface}.
 *
 * Central hub for string-named, event-driven communication between plugins:
 * exact and dot-segment wildcard subscriptions, priority ordering, listener
 * error isolation (a throwing handler is logged and does not stop the rest),
 * a pluggable async (queue) seam — see {@see setAsyncDispatcher()} and the
 * `$async` semantics documented on {@see dispatch()} — and honors the
 * {@see InterceptionSlot} interception contract (KEYSTONE, core 0.5) documented
 * on {@see dispatch()}: an optional `$payload['slot']` a handler can stop or
 * short-circuit, purely additive and inert when absent.
 */
class EventDispatcher implements MilpaEventDispatcherInterface
{
    /**
     * @var array<string, array<int, array<callable>>> Subscribers organized by event name, then priority
     */
    private array $subscribers = [];

    /**
     * @var LoggerInterface Logger for debugging events
     */
    private LoggerInterface $logger;

    /**
     * @var callable|null Queue dispatcher for async events
     */
    private $asyncDispatcher = null;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Wire the async dispatcher (typically a queue service) that `dispatch(..., async: true)`
     * hands events to. Without this call, `$async=true` degrades to synchronous dispatch —
     * see the `$async` semantics on {@see dispatch()}.
     *
     * @param callable $dispatcher fn(string $eventName, array $payload): void
     */
    public function setAsyncDispatcher(callable $dispatcher): void
    {
        $this->asyncDispatcher = $dispatcher;
    }

    /**
     * {@inheritdoc}
     *
     * `$async` semantics for this implementation, per the MAY/MUST contract on
     * {@see MilpaEventDispatcherInterface::dispatch()}: with an async dispatcher wired via
     * {@see setAsyncDispatcher()}, `$async=true` hands the event to that callable instead of
     * invoking subscribers inline — a conformant queue dispatch, not a fallback. Without one
     * wired, `$async=true` degrades to synchronous dispatch (subscribers run inline, in the
     * same call) — a conformant MAY fallback, never a silent drop; the event is always either
     * queued or run.
     *
     * Interception (KEYSTONE, core 0.5) for this implementation: after EACH handler's
     * error-isolating try/catch — unconditionally, whether or not that handler threw —
     * this dispatcher checks whether `$payload['slot']` is an {@see InterceptionSlot} and,
     * if `$slot->isStopped()`, stops invoking the remaining handlers for this dispatch
     * (priority order is preserved up to that point — a higher-priority handler that
     * stops means lower-priority handlers never run). A payload without a `'slot'` key,
     * or with a `'slot'` that is not an {@see InterceptionSlot}, is completely unaffected
     * — byte-identical to the pre-interception behavior. `$async=true` combined with an
     * {@see InterceptionSlot} in the payload throws {@see \InvalidArgumentException}
     * before anything else happens — see the guard at the top of this method.
     *
     * @throws \InvalidArgumentException if `$async` is true and `$payload['slot']` is an {@see InterceptionSlot} — interception is inherently synchronous
     */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        if ($async && ($payload['slot'] ?? null) instanceof InterceptionSlot) {
            throw new \InvalidArgumentException(
                "Cannot dispatch '{$eventName}' asynchronously with an InterceptionSlot in the payload: "
                . 'interception (stop()/shortCircuit()) is inherently synchronous — a slot handed to a '
                . 'queue is never re-read by the emitter, so the veto/short-circuit would be silently '
                . 'lost. Dispatch synchronously ($async = false) or drop the slot from the payload.'
            );
        }

        $this->logger->debug("[EventDispatcher] Dispatching event: {$eventName}" . ($async ? " (async)" : ""));

        if ($async && $this->asyncDispatcher !== null) {
            // Dispatch via queue for deferred execution
            ($this->asyncDispatcher)($eventName, $payload);
            return;
        }

        $handlers = $this->getSubscribers($eventName);

        if (empty($handlers)) {
            $this->logger->debug("[EventDispatcher] No subscribers for: {$eventName}");
            return;
        }

        $this->logger->debug("[EventDispatcher] Found " . count($handlers) . " handler(s) for: {$eventName}");

        $slot = $payload['slot'] ?? null;

        foreach ($handlers as $handler) {
            try {
                $handler($eventName, $payload);
            } catch (\Throwable $e) {
                $this->logger->error("[EventDispatcher] Handler error for {$eventName}: " . $e->getMessage());
            }

            // Unconditional: runs whether or not the handler above threw (hallazgo (a) —
            // a handler that calls stop()/shortCircuit() and THEN throws must still stop
            // propagation).
            if ($slot instanceof InterceptionSlot && $slot->isStopped()) {
                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        if (!isset($this->subscribers[$eventName])) {
            $this->subscribers[$eventName] = [];
        }

        if (!isset($this->subscribers[$eventName][$priority])) {
            $this->subscribers[$eventName][$priority] = [];
        }

        $this->subscribers[$eventName][$priority][] = $handler;

        $this->logger->debug("[EventDispatcher] Subscribed to: {$eventName} (priority: {$priority})");
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribers(string $eventName): array
    {
        $handlers = [];

        // Get exact match subscribers
        if (isset($this->subscribers[$eventName])) {
            foreach ($this->subscribers[$eventName] as $priority => $priorityHandlers) {
                foreach ($priorityHandlers as $handler) {
                    $handlers[] = ['priority' => $priority, 'handler' => $handler];
                }
            }
        }

        // Get wildcard match subscribers
        foreach ($this->subscribers as $pattern => $priorityGroups) {
            if ($this->matchesWildcard($pattern, $eventName)) {
                foreach ($priorityGroups as $priority => $priorityHandlers) {
                    foreach ($priorityHandlers as $handler) {
                        $handlers[] = ['priority' => $priority, 'handler' => $handler];
                    }
                }
            }
        }

        // Sort by priority (higher first)
        usort($handlers, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_column($handlers, 'handler');
    }

    /**
     * {@inheritdoc}
     */
    public function hasSubscribers(string $eventName): bool
    {
        return !empty($this->getSubscribers($eventName));
    }

    /**
     * Check if a pattern matches an event name using wildcards.
     *
     * `*` matches exactly one dot-separated segment (it does not span a `.`);
     * matching is case-sensitive and anchored.
     * Examples:
     *   'user.*'    matches 'user.created', 'user.deleted' (not 'user.a.b')
     *   '*.created' matches 'user.created', 'order.created'
     *   '*'         matches only single-segment names (e.g. 'boot'), not dotted ones
     *
     * @param string $pattern   Subscription pattern (may contain wildcards)
     * @param string $eventName Actual event name
     *
     * @return bool
     */
    private function matchesWildcard(string $pattern, string $eventName): bool
    {
        // Skip if pattern is exact match (already handled above)
        if ($pattern === $eventName) {
            return false;
        }

        // No wildcards in pattern
        if (strpos($pattern, '*') === false) {
            return false;
        }

        // Convert wildcard pattern to regex
        $regex = '/^' . str_replace(['.', '*'], ['\.', '[^.]+'], $pattern) . '$/';

        return (bool) preg_match($regex, $eventName);
    }

    /**
     * Get all registered event patterns (for debugging).
     *
     * @return array<string>
     */
    public function getRegisteredPatterns(): array
    {
        return array_keys($this->subscribers);
    }
}
