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
    // Mode « sur demande » : délai de réponse du loueur avant expiration (cahier des charges : 12 h).
    'delai_reponse_heures' => (int) env('LOCAGABON_DELAI_REPONSE_HEURES', 12),

    'default_customer_password' => env('LOCAGABON_DEFAULT_CUSTOMER_PASSWORD', 'LocaGabon2026!'),

];
