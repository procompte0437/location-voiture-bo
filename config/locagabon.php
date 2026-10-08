<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mot de passe temporaire des comptes créés à la réservation
    |--------------------------------------------------------------------------
    |
    | Lorsqu’un client réserve sans être connecté, un compte est créé avec
    | ce mot de passe générique. Il pourra le changer ensuite.
    |
    */
    'default_customer_password' => env('LOCAGABON_DEFAULT_CUSTOMER_PASSWORD', 'LocaGabon2026!'),

];
