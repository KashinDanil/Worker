<?php

declare(strict_types=1);

namespace DanilKashin\Worker;

enum WorkerState: int
{
    case STARTING = 0;
    case RUNNING = 1;
    case STOP_REQUESTED = 2;
    case STOPPING = 3;
    case STOPPED = 4;

    public function isRunning(): bool
    {
        return self::RUNNING === $this;
    }

    public function isStopRequested(): bool
    {
        return self::STOP_REQUESTED === $this;
    }
}