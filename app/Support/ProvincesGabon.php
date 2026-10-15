<?php

namespace App\Support;

/**
 * Les 9 provinces du Gabon (référentiel stable pour carte + agences).
 */
final class ProvincesGabon
{
    /** @return list<array{slug: string, nom: string, chef_lieu: string}> */
    public static function toutes(): array
    {
        return [
            ['slug' => 'estuaire', 'nom' => 'Estuaire', 'chef_lieu' => 'Libreville'],
            ['slug' => 'haut-ogooue', 'nom' => 'Haut-Ogooué', 'chef_lieu' => 'Franceville'],
            ['slug' => 'moyen-ogooue', 'nom' => 'Moyen-Ogooué', 'chef_lieu' => 'Lambaréné'],
            ['slug' => 'ngounie', 'nom' => 'Ngounié', 'chef_lieu' => 'Mouila'],
            ['slug' => 'nyanga', 'nom' => 'Nyanga', 'chef_lieu' => 'Tchibanga'],
            ['slug' => 'ogooue-ivindo', 'nom' => 'Ogooué-Ivindo', 'chef_lieu' => 'Makokou'],
            ['slug' => 'ogooue-lolo', 'nom' => 'Ogooué-Lolo', 'chef_lieu' => 'Koulamoutou'],
            ['slug' => 'ogooue-maritime', 'nom' => 'Ogooué-Maritime', 'chef_lieu' => 'Port-Gentil'],
            ['slug' => 'woleu-ntem', 'nom' => 'Woleu-Ntem', 'chef_lieu' => 'Oyem'],
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::toutes(), 'slug');
    }

    public static function trouver(string $slug): ?array
    {
        foreach (self::toutes() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }

        return null;
    }
}
