<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scrape:all')->daily()->at('03:00')
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scraper-scheduler.log'));

Schedule::command('scrape:all --download-images')->weekly()
    ->sundays()->at('02:00')
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scraper-scheduler.log'));
