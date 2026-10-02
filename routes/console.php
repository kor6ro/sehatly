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

// F11: each active reminder is evaluated on its OWN zone's wall clock, so the
// tick runs every minute and `pengingat_terkirim`'s unique key makes a repeat
// inside the same minute a no-op rather than a duplicate. `withoutOverlapping`
// keeps a slow tick from stacking on the next one; the command is idempotent
// even without the lock.
Schedule::command('pengingat:kirim')->everyMinute()->withoutOverlapping();
