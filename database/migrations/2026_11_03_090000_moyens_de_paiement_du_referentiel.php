<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les parcours de réservation lisent les moyens de paiement dans le référentiel.
 * Une base sans ces lignes bloque toute réservation : ils sont créés s’ils n’existent pas encore.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moyens = [
            ['slug' => 'airtel_money', 'label' => 'Airtel Money', 'sort_order' => 1],
            ['slug' => 'moov_money', 'label' => 'Moov Money', 'sort_order' => 2],
            ['slug' => 'card', 'label' => 'Carte bancaire', 'sort_order' => 3],
            ['slug' => 'agency_cash', 'label' => 'Règlement en agence', 'sort_order' => 4],
        ];

        foreach ($moyens as $moyen) {
            $existe = DB::table('listes_reference')
                ->where('type', 'payment_method')
                ->where('slug', $moyen['slug'])
                ->exists();
            if ($existe) {
                continue;
            }
            DB::table('listes_reference')->insert([
                ...$moyen,
                'type' => 'payment_method',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Ces lignes peuvent avoir été reprises par l’administration : on ne les retire pas.
    }
};
