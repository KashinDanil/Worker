<?php

declare(strict_types=1);

namespace DanilKashin\Worker;

use Throwable;

abstract class Worker
{
    private const int DEFAULT_TICK_INTERVAL_MS = 100;

    private WorkerState $state = WorkerState::STARTING;

    public function __construct(
        private readonly ?int $maxTicks = null,
    ) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->requestStop());
        pcntl_signal(SIGINT, fn() => $this->requestStop());
    }

    public function run(): void
    {
        $this->state = WorkerState::RUNNING;
        $maxTicks = $this->maxTicks ?? $this->getDefaultMaxTicks();
        $tickCount = 0;

        while ($this->state->isRunning()) {
            try {
                $this->tick();
            } catch (Throwable $e) {
                $this->handleTickError($e);
            }

            if ($maxTicks !== null && ++$tickCount >= $maxTicks) {
                $this->requestStop();
            }

            if ($this->state->isRunning()) {
                $this->sleepAfterTick();
            }
        }

        $this->state = WorkerState::STOPPING;
        $this->onStopping();
        $this->state = WorkerState::STOPPED;
    }

    abstract protected function tick(): void;

    protected function onStopping(): void
    {
    }

    protected function shouldStop(): bool
    {
        return $this->state->isStopRequested();
    }

    protected function handleTickError(Throwable $e): void
    {
        fwrite(
            STDERR,
            sprintf(
                "[%s] Worker tick failed: %s in %s:%d",
                static::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ) . PHP_EOL
        );
    }

    protected function getDefaultMaxTicks(): ?int
    {
        return null;
    }

    protected function getTickIntervalMs(): int
    {
        return self::DEFAULT_TICK_INTERVAL_MS;
    }

    private function sleepAfterTick(): void
    {
        echo '.';
        usleep($this->getTickIntervalMs() * 1_000);
    }

    private function requestStop(): void
    {
        $this->state = WorkerState::STOP_REQUESTED;
    }
}