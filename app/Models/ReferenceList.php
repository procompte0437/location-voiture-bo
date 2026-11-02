<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceList extends Model
{
    protected $table = 'listes_reference';

    protected $fillable = ['type', 'slug', 'label', 'parent_slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Pose les cinq objets d’origine du formulaire de contact
     * la première fois que la liste est demandée.
     */
    public static function assurerSujetsContact(): void
    {
        if (static::query()->where('type', 'contact_subject')->exists()) {
            return;
        }

        $sujets = [
            ['slug' => 'reservation', 'label' => 'Une réservation', 'sort_order' => 10],
            ['slug' => 'partenaire', 'label' => 'Devenir partenaire', 'sort_order' => 20],
            ['slug' => 'compte', 'label' => 'Mon compte', 'sort_order' => 30],
            ['slug' => 'reclamation', 'label' => 'Une réclamation', 'sort_order' => 40],
            ['slug' => 'autre', 'label' => 'Autre demande', 'sort_order' => 50],
        ];

        foreach ($sujets as $sujet) {
            static::query()->create([
                'type' => 'contact_subject',
                'slug' => $sujet['slug'],
                'label' => $sujet['label'],
                'sort_order' => $sujet['sort_order'],
                'is_active' => true,
            ]);
        }
    }
}
