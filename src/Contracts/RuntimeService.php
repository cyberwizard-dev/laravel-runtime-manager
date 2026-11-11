<?php

namespace Cyberwizard\RuntimeManager\Contracts;

use Cyberwizard\RuntimeManager\Runner\ExecutionBudget;
use Cyberwizard\RuntimeManager\Runner\ServiceResult;

/**
 * A unit of work the tick runs within the execution budget. Each service is
 * independent, self-reports whether it is enabled, and must respect the time
 * allotted to it so no other service is starved.
 */
interface RuntimeService
{
    /** Stable key used for budget weighting, config and run records. */
    public function key(): string;

    /** Whether this service should run this tick. */
    public function isEnabled(): bool;

    /** Do the work within the allotted slice of the budget. */
    public function run(ExecutionBudget $budget): ServiceResult;
}
