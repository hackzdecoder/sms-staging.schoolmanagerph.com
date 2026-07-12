<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ====================================================
// PUSH NOTIFICATION SCHEDULER
// Automatically checks for new unread attendance/messages
// and sends push notifications to users every minute.
// ====================================================
Schedule::command('notifications:check-unread')->everyMinute();
