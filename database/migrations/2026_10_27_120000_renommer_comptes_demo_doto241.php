<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les comptes de démonstration affichés sur la connexion suivent le domaine doto241.com.
 * Les adresses réelles des clients et partenaires ne sont pas touchées.
 */
return new class extends Migration
{
    public function up(): void
    {
        $correspondances = [
            'admin@locagabon.ga' => 'admin@doto241.com',
            'client@locagabon.ga' => 'client@doto241.com',
            'partenaire@locagabon.ga' => 'partenaire@doto241.com',
            'pending@locagabon.ga' => 'pending@doto241.com',
        ];

        foreach ($correspondances as $ancien => $nouveau) {
            if (DB::table('utilisateurs')->where('email', $nouveau)->exists()) {
                continue;
            }

            DB::table('utilisateurs')->where('email', $ancien)->update(['email' => $nouveau]);
        }
    }

    public function down(): void
    {
        $correspondances = [
            'admin@doto241.com' => 'admin@locagabon.ga',
            'client@doto241.com' => 'client@locagabon.ga',
            'partenaire@doto241.com' => 'partenaire@locagabon.ga',
            'pending@doto241.com' => 'pending@locagabon.ga',
        ];

        foreach ($correspondances as $actuel => $precedent) {
            if (DB::table('utilisateurs')->where('email', $precedent)->exists()) {
                continue;
            }

            DB::table('utilisateurs')->where('email', $actuel)->update(['email' => $precedent]);
        }
    }
};
