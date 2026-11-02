<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageContact;
use App\Models\ReferenceList;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ControleurContact extends Controller
{
    public function __construct(private AuditService $audit) {}

    /**
     * Enregistre un message du formulaire public.
     * La visite peut être anonyme : le compte n’est rattaché que s’il est déjà connecté.
     */
    public function enregistrer(Request $request): JsonResponse
    {
        $sujets = ReferenceList::query()
            ->where('type', 'contact_subject')
            ->where('is_active', true)
            ->pluck('slug')
            ->all();
        // Tant qu’aucun objet n’est saisi dans le référentiel, les cinq sujets d’origine restent acceptés.
        if ($sujets === []) {
            $sujets = ['reservation', 'partenaire', 'compte', 'reclamation', 'autre'];
        }

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'prenom' => ['nullable', 'string', 'max:80'],
            'civilite' => ['nullable', 'string', 'max:40'],
            'pays' => ['nullable', 'string', 'max:80'],
            'entreprise' => ['nullable', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'sujet' => ['required', 'string', Rule::in($sujets)],
            'message' => ['required', 'string', 'min:20', 'max:4000'],
            'site_web' => ['nullable', 'string', 'max:200'],
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'sujet.required' => 'Choisissez un sujet.',
            'sujet.in' => 'Choisissez un sujet dans la liste.',
            'message.required' => 'Le message est obligatoire.',
            'message.min' => 'Le message doit contenir au moins 20 caractères.',
            'message.max' => 'Le message est trop long.',
        ]);

        // Champ invisible pour les visiteurs : un robot qui le remplit n’est pas enregistré.
        if (filled($donnees['site_web'] ?? null)) {
            return response()->json([
                'message' => 'Votre message a bien été envoyé. Nous vous répondrons à l’adresse indiquée.',
            ], 201);
        }

        $utilisateur = auth('sanctum')->user();

        $contexte = array_filter([
            filled($donnees['civilite'] ?? null) ? 'Civilité : '.$donnees['civilite'] : null,
            filled($donnees['prenom'] ?? null) ? 'Prénom : '.$donnees['prenom'] : null,
            filled($donnees['pays'] ?? null) ? 'Pays : '.$donnees['pays'] : null,
            filled($donnees['entreprise'] ?? null) ? 'Entreprise : '.$donnees['entreprise'] : null,
        ]);

        $nomComplet = trim(($donnees['prenom'] ?? '').' '.$donnees['nom']);

        $message = MessageContact::query()->create([
            'utilisateur_id' => $utilisateur instanceof User ? $utilisateur->id : null,
            'nom' => mb_substr($nomComplet, 0, 120),
            'email' => $donnees['email'],
            'telephone' => $donnees['telephone'] ?: null,
            'sujet' => $donnees['sujet'],
            'message' => ($contexte ? implode("\n", $contexte)."\n\n" : '').$donnees['message'],
        ]);

        $this->audit->log('contact.message_recu', $message, null, [
            'sujet' => $message->sujet,
            'email' => $message->email,
        ]);

        return response()->json([
            'message' => 'Votre message a bien été envoyé. Nous vous répondrons à l’adresse indiquée.',
        ], 201);
    }
}
