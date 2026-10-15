<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageContact;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ControleurContact extends Controller
{
    public function __construct(private AuditService $audit) {}

    /**
     * Enregistre un message du formulaire public.
     * La visite peut être anonyme : le compte n’est rattaché que s’il est déjà connecté.
     */
    public function enregistrer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'sujet' => ['required', 'in:reservation,partenaire,compte,reclamation,autre'],
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

        $message = MessageContact::query()->create([
            'utilisateur_id' => $utilisateur instanceof User ? $utilisateur->id : null,
            'nom' => $donnees['nom'],
            'email' => $donnees['email'],
            'telephone' => $donnees['telephone'] ?: null,
            'sujet' => $donnees['sujet'],
            'message' => $donnees['message'],
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
