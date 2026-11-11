<?php

namespace Cyberwizard\RuntimeManager\Tests\Unit;

use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use PHPUnit\Framework\TestCase;

class ExecutionBudgetTest extends TestCase
{
    public function test_weighted_split_respects_weights(): void
    {
        $budget = new ExecutionBudget([
            'total_seconds' => 100,
            'weights' => ['a' => 1, 'b' => 4],
            'floors'  => [],
        ]);

        $a = $budget->allot('a'); // 1/5 of ~100
        $b = $budget->allot('b'); // 4/4 of the remainder (~80)

        $this->assertGreaterThan(15, $a);
        $this->assertLessThan(25, $a);
        // b receives essentially the whole remainder since it is the last service.
        $this->assertGreaterThan(70, $b);
    }

    public function test_floor_is_guaranteed(): void
    {
        $budget = new ExecutionBudget([
            'total_seconds' => 10,
            'weights' => ['a' => 1, 'b' => 100],
            'floors'  => ['a' => 5],
        ]);

        // Despite a tiny weight, 'a' must get at least its 5s floor.
        $this->assertGreaterThanOrEqual(5.0, $budget->allot('a'));
    }

    public function test_expired_budget_allots_zero(): void
    {
        $budget = new ExecutionBudget(['total_seconds' => 0, 'weights' => ['a' => 1]]);

        $this->assertTrue($budget->expired());
        $this->assertSame(0.0, $budget->allot('a'));
    }
}
