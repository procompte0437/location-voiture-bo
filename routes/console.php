<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rappels J-1 et pièces qui expirent, chaque matin (heure du Gabon).
// En production, un cron doit lancer « php artisan schedule:run » chaque minute.
Illuminate\Support\Facades\Schedule::command('doto:rappels')->dailyAt('08:00')->timezone('Africa/Libreville');

// Mode « sur demande » : une demande sans réponse du loueur dans le délai expire et libère le véhicule.
Illuminate\Support\Facades\Schedule::command('doto:expirer-demandes')->everyFifteenMinutes();
