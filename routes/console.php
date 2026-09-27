<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The schema has no column recording when a payment window closes, so the
// transition of a past-due `menunggu_pembayaran` booking to `kadaluarsa` is a
// scheduled application sweep, run daily.
Schedule::command('booking:kadaluarsa')->daily();
