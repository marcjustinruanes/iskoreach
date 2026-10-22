<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('scholarships:remind')->dailyAt('08:05')->timezone('Asia/Manila')->withoutOverlapping();

Schedule::command('renewals:remind')->dailyAt('08:00')->timezone('Asia/Manila')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
