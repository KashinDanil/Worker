<?php

declare(strict_types=1);

namespace DanilKashin\Worker\Tests\Unit;

use DanilKashin\Worker\Worker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @requires extension pcntl
 * @requires extension posix
 */
final class WorkerTest extends TestCase
{
    public function testTickIsCalledDuringRun(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
            if (3 === $tickCount) {
                posix_kill(posix_getpid(), SIGTERM);
            }
        });

        $worker->run();

        $this->assertSame(3, $tickCount);
    }

    public function testOnStoppingIsCalledOnShutdown(): void
    {
        $onStoppingCalled = false;

        $worker = $this->makeWorker(
            fn() => posix_kill(posix_getpid(), SIGTERM),
            function () use (&$onStoppingCalled): void {
                $onStoppingCalled = true;
            },
        );

        $worker->run();

        $this->assertTrue($onStoppingCalled);
    }

    public function testTickExceptionDoesNotCrashWorker(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
            if (1 === $tickCount) {
                throw new RuntimeException('tick error');
            }

            posix_kill(posix_getpid(), SIGTERM);
        });

        $worker->run();

        $this->assertSame(2, $tickCount);
    }

    public function testStopSignalReceivedIsFalseBeforeSignal(): void
    {
        $stopSignalReceived = null;

        $worker = $this->makeWorker(function (\Closure $checkStop) use (&$stopSignalReceived): void {
            $stopSignalReceived = $checkStop(); // capture before signal
            posix_kill(posix_getpid(), SIGTERM);
        });

        $worker->run();

        $this->assertFalse($stopSignalReceived);
    }

    public function testStopSignalReceivedIsTrueAfterSignal(): void
    {
        $stopSignalReceived = null;

        $worker = $this->makeWorker(function (\Closure $checkStop) use (&$stopSignalReceived): void {
            posix_kill(posix_getpid(), SIGTERM);
            $stopSignalReceived = $checkStop(); // capture after signal
        });

        $worker->run();

        $this->assertTrue($stopSignalReceived);
    }

    public function testWorkerStopsAfterMaxTicks(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
        }, maxTicks: 5);

        $worker->run();

        $this->assertSame(5, $tickCount);
    }

    public function testOnStoppingIsCalledWhenMaxTicksReached(): void
    {
        $onStoppingCalled = false;

        $worker = $this->makeWorker(
            fn() => null,
            function () use (&$onStoppingCalled): void {
                $onStoppingCalled = true;
            },
            maxTicks: 1,
        );

        $worker->run();

        $this->assertTrue($onStoppingCalled);
    }

    public function testFailedTickCountsTowardMaxTicks(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
            throw new RuntimeException('tick error');
        }, maxTicks: 3);

        $worker->run();

        $this->assertSame(3, $tickCount);
    }

    public function testMaxTicksCanBeOverriddenBySubclass(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
        }, getDefaultMaxTicksOverride: 4);

        $worker->run();

        $this->assertSame(4, $tickCount);
    }

    public function testConstructorParamTakesPriorityOverGetMaxTicks(): void
    {
        $tickCount = 0;
        $worker = $this->makeWorker(function () use (&$tickCount): void {
            $tickCount++;
        }, maxTicks: 3, getDefaultMaxTicksOverride: 10);

        $worker->run();

        $this->assertSame(3, $tickCount);
    }

    private function makeWorker(
        callable $tick,
        ?callable $onStopping = null,
        ?int $maxTicks = null,
        ?int $getDefaultMaxTicksOverride = null,
    ): Worker {
        return new class(
            \Closure::fromCallable($tick),
            null !== $onStopping ? \Closure::fromCallable($onStopping) : null,
            $maxTicks,
            $getDefaultMaxTicksOverride,
        ) extends Worker {
            public function __construct(
                private readonly \Closure $tickFn,
                private readonly ?\Closure $onStoppingFn,
                ?int $maxTicks = null,
                private readonly ?int $getDefaultMaxTicksOverride = null,
            ) {
                parent::__construct($maxTicks);
            }

            protected function tick(): void
            {
                // Pass a $checkStop closure so callers can probe shouldStop()
                // without needing direct access to the protected method.
                ($this->tickFn)(fn() => $this->shouldStop());
            }

            protected function onStopping(): void
            {
                ($this->onStoppingFn ?? fn() => null)();
            }

            protected function getDefaultMaxTicks(): ?int
            {
                return $this->getDefaultMaxTicksOverride;
            }

            protected function getTickIntervalMs(): int
            {
                return 0;
            }
        };
    }
}