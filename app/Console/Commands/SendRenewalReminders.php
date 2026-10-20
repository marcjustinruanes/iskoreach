<?php

namespace App\Console\Commands;

use App\Services\RenewalWorkflow;
use Illuminate\Console\Command;

class SendRenewalReminders extends Command
{
    protected $signature = 'renewals:remind';

    protected $description = 'Save pending renewal reminders at 30, 14 and 7 Philippine calendar days before the deadline';

    public function handle(RenewalWorkflow $workflow): int
    {
        $this->info($workflow->reminders().' renewal reminders saved; email delivery is queued.');

        return self::SUCCESS;
    }
}
