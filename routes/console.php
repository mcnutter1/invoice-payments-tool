<?php

use Illuminate\Support\Facades\Schedule;

// Driven by a single one-minute system cron: `php artisan schedule:run` (see deploy/cron).
Schedule::command('finance:outbox')->everyMinute()->withoutOverlapping(10);
Schedule::command('finance:recurring')->everyMinute()->withoutOverlapping(10);
Schedule::command('finance:maintenance')->hourly()->withoutOverlapping(30);
Schedule::command('finance:reminders')->dailyAt('14:00')->withoutOverlapping(30);
