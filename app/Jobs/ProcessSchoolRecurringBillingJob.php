<?php

namespace App\Jobs;

use App\Services\RecurringFeeBillingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSchoolRecurringBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(
        public int $schoolId,
        public string $targetMonth,
        public string $triggeredBy = 'scheduler'
    ) {}

    public function handle(): void
    {
        RecurringFeeBillingService::executeBillingCycle(
            $this->schoolId,
            Carbon::parse($this->targetMonth),
            $this->triggeredBy
        );
    }
}
