<?php

use Illuminate\Support\Facades\Schedule;

/*
| Federal Register publishes each weekday morning (Eastern). Two polls a
| weekday catch the issue and late-indexed documents; a nightly look back
| over 30 days catches corrections and metadata changes.
*/
Schedule::command('ruleradar:poll federal_register')
    ->weekdays()
    ->at('06:30')
    ->timezone('America/Chicago')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ruleradar:poll federal_register')
    ->weekdays()
    ->at('12:30')
    ->timezone('America/Chicago')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('ruleradar:poll federal_register --days=30')
    ->dailyAt('02:15')
    ->timezone('America/Chicago')
    ->withoutOverlapping()
    ->onOneServer();

// Reminders and digests go out before the working day starts.
Schedule::command('ruleradar:daily-notifications')
    ->dailyAt('07:00')
    ->timezone('America/Chicago')
    ->withoutOverlapping()
    ->onOneServer();

// Integrity and retention.
Schedule::command('ruleradar:audit-verify')
    ->dailyAt('03:30')
    ->timezone('America/Chicago')
    ->onOneServer();

Schedule::command('ruleradar:prune')
    ->dailyAt('03:45')
    ->timezone('America/Chicago')
    ->onOneServer();
