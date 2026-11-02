<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Passerelle Mobile Money. Sans ces identifiants, le paiement en ligne reste fermé.
    'pvit' => [
        'url' => env('PVIT_URL', 'https://api.mypvit.pro'),
        'compte' => env('PVIT_ACCOUNT_CODE'),
        'mot_de_passe' => env('PVIT_API_PASSWORD'),
        'code_secret' => env('PVIT_CODE_URL_SECRET'),
        'code_paiement' => env('PVIT_CODE_URL_PAYMENT'),
        'code_statut' => env('PVIT_CODE_URL_STATUS'),
        'code_kyc' => env('PVIT_CODE_URL_KYC'),
        'code_callback' => env('PVIT_CALLBACK_URL_CODE'),
        // CUSTOMER ajoute les frais au montant payé par le client, MERCHANT les retire de l’encaissement.
        'frais_a_charge' => env('PVIT_OWNER_CHARGE', 'MERCHANT'),
        'montant_maximum' => (int) env('PVIT_MAX_AMOUNT', 500000),
        // Sandbox uniquement : moins de 1 000 XAF réussit, plus de 1 000 XAF échoue.
        'montant_test' => env('PVIT_TEST_AMOUNT'),
        'operateurs' => [
            'airtel_money' => env('PVIT_OPERATOR_AIRTEL', 'AIRTEL_MONEY'),
            'moov_money' => env('PVIT_OPERATOR_MOOV', 'MOOV_MONEY'),
        ],
        // Ancienne route de démonstration qui marque payé sans encaisser.
        'simulation' => (bool) env('PAIEMENT_SIMULATION', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
