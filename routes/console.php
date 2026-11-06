<?php

use Illuminate\Support\Facades\Schedule;

// Driven by a single one-minute system cron: `php artisan schedule:run` (see deploy/cron).
Schedule::command('finance:outbox')->everyMinute()->withoutOverlapping(10);
Schedule::command('finance:recurring')->everyMinute()->withoutOverlapping(10);
Schedule::command('finance:maintenance')->hourly()->withoutOverlapping(30);
Schedule::command('finance:reminders')->dailyAt(config('finance.reminders.run_at', '14:00'))->withoutOverlapping(30);
Schedule::command('finance:backups')->hourlyAt(5)->withoutOverlapping(55);
Schedule::command('finance:backups-weekly')->weeklyOn(1, '15:00')->withoutOverlapping(120);
