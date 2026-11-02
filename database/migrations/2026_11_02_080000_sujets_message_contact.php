<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les objets du formulaire de contact deviennent une liste du référentiel.
 * Les cinq sujets déjà proposés sont créés s’ils n’existent pas encore.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sujets = [
            ['slug' => 'reservation', 'label' => 'Une réservation', 'sort_order' => 10],
            ['slug' => 'partenaire', 'label' => 'Devenir partenaire', 'sort_order' => 20],
            ['slug' => 'compte', 'label' => 'Mon compte', 'sort_order' => 30],
            ['slug' => 'reclamation', 'label' => 'Une réclamation', 'sort_order' => 40],
            ['slug' => 'autre', 'label' => 'Autre demande', 'sort_order' => 50],
        ];

        foreach ($sujets as $sujet) {
            $existe = DB::table('listes_reference')
                ->where('type', 'contact_subject')
                ->where('slug', $sujet['slug'])
                ->exists();
            if ($existe) {
                continue;
            }
            DB::table('listes_reference')->insert([
                'type' => 'contact_subject',
                'slug' => $sujet['slug'],
                'label' => $sujet['label'],
                'parent_slug' => null,
                'sort_order' => $sujet['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('listes_reference')
            ->where('type', 'contact_subject')
            ->whereIn('slug', ['reservation', 'partenaire', 'compte', 'reclamation', 'autre'])
            ->delete();
    }
};
