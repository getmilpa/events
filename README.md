<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Events

> The **reference event dispatcher** for the Milpa PHP framework, built on **`milpa/core`**. String-named events with dot-segment wildcard subscriptions (`user.*`), priority ordering, per-listener error isolation, and a pluggable async (queue) seam — the concrete implementation of the `MilpaEventDispatcherInterface` contract `milpa/core` defines.

[![CI](https://github.com/getmilpa/events/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/events/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/events.svg)](https://packagist.org/packages/milpa/events)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/events/)

`milpa/events` is where `milpa/core`'s event-dispatch seam becomes a working engine.
`Milpa\Interfaces\Event\MilpaEventDispatcherInterface` is a contract defined in core; this
package is the concrete `EventDispatcher` — plugins publish and subscribe to string-named
events (`'user.created'`, `'order.shipped'`) without depending on each other directly.
**No Doctrine, no HTTP kernel, no concrete queue** — the async seam is a plain callable you
wire in; the queue itself lives in your host application.

## Install

```bash
composer require milpa/events
```

## Quick example

Subscribe by exact name or by a dot-segment wildcard, dispatch, and let priority decide the
call order — higher priority runs first:

```php
use Milpa\Eventing\EventDispatcher;
use Psr\Log\NullLogger;

$dispatcher = new EventDispatcher(new NullLogger());

$dispatcher->subscribe('user.*', function (string $event, array $payload): void {
    // catches every one-segment event under `user.` — user.created, user.updated, ...
});

$dispatcher->subscribe('user.created', function (string $event, array $payload): void {
    // runs before the wildcard handler above: higher priority
}, priority: 10);

$dispatcher->dispatch('user.created', ['id' => 42]);

$dispatcher->hasSubscribers('user.created'); // true
$dispatcher->hasSubscribers('order.created'); // false — no exact or wildcard match
```

A handler that throws is logged and does **not** stop the remaining handlers — one bad
listener never aborts a dispatch:

```php
$dispatcher->subscribe('order.placed', fn () => throw new \RuntimeException('boom'));
$dispatcher->subscribe('order.placed', fn () => /* still runs */ null);

$dispatcher->dispatch('order.placed'); // both handlers ran; the exception was logged, not thrown
```

## Wildcard grammar

Event names are dot-separated segments; `*` matches exactly **one** segment — it never spans
a `.`. Matching is case-sensitive and anchored (the whole name must match):

| Pattern | Matches | Does not match |
|---|---|---|
| `user.*` | `user.created`, `user.deleted` | `user.profile.updated` (two segments after `user.`) |
| `*.created` | `user.created`, `order.created` | `user.createdX` (anchored, not a substring match) |
| `*` | `boot`, `ready` (single-segment names) | `user.created` (dotted) |

## Async: a seam, not an implementation

`dispatch($event, $payload, async: true)` requests deferred execution. **This package ships
no queue** — you wire one in with `setAsyncDispatcher()`:

```php
$dispatcher->setAsyncDispatcher(function (string $event, array $payload): void {
    // hand off to your queue (Symfony Messenger, a Doctrine-backed job table, ...)
});

$dispatcher->dispatch('order.placed', ['id' => 1], async: true); // -> queue, not inline
```

Without a dispatcher wired, `async: true` **degrades to synchronous dispatch** (subscribers
run inline, in the same call) rather than silently dropping the event — a conformant
fallback per the interface's documented `$async` contract, not a bug. Both branches are
covered in `tests/EventDispatcherTest.php`.

## Why the namespace is `Milpa\Eventing`

`milpa/core` already owns `Milpa\Events\` for its event *objects*
(`VerificationRequestedEvent` and friends, under `src/Events/`). A package literally named
`milpa/events` colliding with that namespace would either clash or force an awkward split.
`Milpa\EventDispatcher\EventDispatcher` was the other candidate and stutters. `Milpa\Eventing`
avoids both: no collision, no stutter, and it names the *mechanism* (dispatch, subscribe,
wildcards, priority) distinctly from `Milpa\Events`, which names the *event objects* — the
two packages' responsibilities stay visibly distinct. This follows the family-wide rule:
namespace semantically correct beats cosmetic symmetry with the package name.

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | `MilpaEventDispatcherInterface`, `EventSubscriberInterface` — the seam, not the engine. |
| **Dispatcher** | **`milpa/events`** (this package) | The concrete `EventDispatcher`: exact + wildcard subscriber matching, priority ordering, listener error isolation, and the async seam. |
| Your app | your host / plugins | The queue `setAsyncDispatcher()` hands events to, and any PSR-3 logger you pass in. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.6**
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Documentation

**Full API reference: [getmilpa.github.io/events](https://getmilpa.github.io/events/)** — generated
straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=events)**.
