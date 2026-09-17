<?php

use App\Sync\SyncSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Entries live in SyncSchedule so the registration itself is testable.
SyncSchedule::register(Schedule::getFacadeRoot());
