<?php

namespace App\Http\Controllers\Api\Partenaire;

use App\Models\Partner;
use Illuminate\Http\Request;

/**
 * Isolation multi-partenaire : chaque requête ne voit que le partenaire du compte connecté.
 * Un membre d’équipe n’accède qu’aux écrans de son rôle ; le responsable accède à tout.
 */
trait ResoutPartenaire
{
    /**
     * @param  list<string>  $rolesAutorises  rôles d’équipe admis en plus du responsable
     */
    private function partenaire(Request $requete, array $rolesAutorises = ['partner_agent', 'finance_manager']): Partner
    {
        $utilisateur = $requete->user();
        $partenaire = $utilisateur?->partenaireRattache();

        if (! $partenaire) {
            abort(response()->json(['message' => 'Aucun compte partenaire associé à cet utilisateur.'], 404));
        }

        $role = $utilisateur->roleDansEquipe();
        if ($role !== 'responsable' && ! in_array($role, $rolesAutorises, true)) {
            abort(response()->json(['message' => 'Votre rôle dans l’équipe ne donne pas accès à cet écran.'], 403));
        }

        return $partenaire;
    }
}
