# Worker

A minimal PHP library for building tick-based background workers with signal handling.

## Requirements

- PHP 8.3+
- `ext-pcntl`

## Installation

```bash
composer require danil-kashin/worker
```

## Usage

Extend the `Worker` class and implement the `tick()` method:

```php
use DanilKashin\Worker\Worker;

class MyWorker extends Worker
{
    protected function tick(): void
    {
        // Your logic runs here on every tick
    }
}

(new MyWorker())->run();
```

### Limiting ticks

Pass `maxTicks` to run the worker a fixed number of times and exit — useful for cron jobs or batch processing:

```php
(new MyWorker(maxTicks: 100))->run();
```

Override `getDefaultMaxTicks()` if the limit is intrinsic to the worker class itself:

```php
protected function getDefaultMaxTicks(): ?int
{
    return 100;
}
```

`null` (the default) means the worker runs indefinitely until a stop signal is received.

### Tick interval

Override `getTickIntervalMs()` to control how long the worker sleeps between ticks (default: 100ms):

```php
protected function getTickIntervalMs(): int
{
    return 500; // 500ms between ticks
}
```

### Graceful shutdown

The worker handles `SIGTERM` and `SIGINT` automatically — it finishes the current tick before stopping.

Override `onStopping()` to run cleanup logic before the process exits:

```php
protected function onStopping(): void
{
    // flush buffers, close connections, etc.
}
```

If you need to react to a stop signal mid-tick (e.g. to break out of an inner loop early):

```php
protected function tick(): void
{
    foreach ($this->getItems() as $item) {
        if ($this->shouldStop()) {
            break;
        }

        $this->process($item);
    }
}
```

### Error handling

Exceptions thrown inside `tick()` are caught, written to `stderr`, and the worker continues running. Override `handleTickError()` to customize this behavior:

```php
protected function handleTickError(Throwable $e): void
{
    // custom error handling
}
```

## Running via CLI

The package ships with `bin/run_worker.php`, which instantiates and runs any `Worker` subclass by its fully qualified class name. Constructor parameters are passed as `--name=value` flags.

```bash
vendor/bin/run_worker.php "App\Workers\MyWorker"
```

Required constructor parameters without defaults will cause the script to fail with a clear error if omitted.