<?php

namespace Cyberwizard\RuntimeManager\Runner;

/**
 * Weighted, floor-guaranteed time allocator for a single tick.
 *
 * Services run sequentially (one process), each drawing from a shared deadline.
 * When a service is about to run it calls allot($key): the remaining time is
 * split across the services that have NOT yet run, by weight, but each service
 * is guaranteed at least its floor (if time remains). A service that finishes
 * early hands its unused time to those that follow — no idle budget, no
 * starvation.
 */
class ExecutionBudget
{
    private float $deadline;

    /** @var array<string,int|float> */
    private array $weights;

    /** @var array<string,int|float> */
    private array $floors;

    /** @var list<string> services already allotted */
    private array $consumed = [];

    public function __construct(array $config, ?float $deadline = null)
    {
        $total = (float) ($config['total_seconds'] ?? 50);
        $this->deadline = $deadline ?? (microtime(true) + $total);
        $this->weights = (array) ($config['weights'] ?? []);
        $this->floors  = (array) ($config['floors'] ?? []);
    }

    /** Seconds left before the overall tick deadline. */
    public function remaining(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    /**
     * Allot a slice to a service and mark it consumed. Returns the seconds the
     * service may use (never more than the time actually remaining).
     */
    public function allot(string $key): float
    {
        $remaining = $this->remaining();
        if ($remaining <= 0.0) {
            $this->consumed[] = $key;

            return 0.0;
        }

        $pendingWeight = 0.0;
        foreach ($this->weights as $k => $w) {
            if (! in_array($k, $this->consumed, true)) {
                $pendingWeight += (float) $w;
            }
        }

        $weight = (float) ($this->weights[$key] ?? 0);
        $share  = $pendingWeight > 0.0 ? ($weight / $pendingWeight) * $remaining : $remaining;

        $floor = (float) ($this->floors[$key] ?? 0);
        $alloc = max($share, min($floor, $remaining));

        $this->consumed[] = $key;

        return min($alloc, $remaining);
    }
}
