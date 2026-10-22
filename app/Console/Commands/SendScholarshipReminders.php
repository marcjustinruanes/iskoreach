<?php

namespace App\Console\Commands;

use App\Services\ScholarshipNotices;
use Illuminate\Console\Command;

class SendScholarshipReminders extends Command
{
    protected $signature = 'scholarships:remind';

    protected $description = 'Send idempotent application deadline and missing-requirement reminders';

    public function handle(ScholarshipNotices $notices): int
    {
        $this->info($notices->reminders().' reminders saved.');

        return self::SUCCESS;
    }
}
