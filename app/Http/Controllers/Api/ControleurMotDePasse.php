<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as RegleMotDePasse;
use Illuminate\Validation\ValidationException;

/**
 * Mot de passe oublié : un lien à usage unique est envoyé par e-mail.
 * La réponse est la même que l’adresse existe ou non, pour ne rien révéler des comptes.
 */
class ControleurMotDePasse extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function demander(Request $requete): JsonResponse
    {
        $donnees = $requete->validate(['email' => ['required', 'email', 'max:255']]);
        $email = mb_strtolower(trim($donnees['email']));
        $lien = null;

        $compte = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($compte && $compte->status !== 'suspended') {
            Password::broker()->sendResetLink(['email' => $compte->email], function (User $utilisateur, string $jeton) use (&$lien) {
                $lien = self::lien($utilisateur->email, $jeton);
                $utilisateur->sendPasswordResetNotification($jeton);
            });
            $this->audit->log('auth.password_reset_requested', $compte, null, null, $compte->id);
        }

        return response()->json([
            'message' => 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d’y être envoyé. Il est valable 60 minutes.',
            // Sans serveur d’e-mail en local, le lien est rendu pour pouvoir tester le parcours.
            'lien' => app()->environment('local') ? $lien : null,
        ]);
    }

    public function reinitialiser(Request $requete): JsonResponse
    {
        $donnees = $requete->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', RegleMotDePasse::min(8)],
        ], [
            'password.confirmed' => 'Les deux mots de passe ne correspondent pas.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
        ]);

        $statut = Password::broker()->reset(
            ['email' => mb_strtolower(trim($donnees['email'])), 'token' => $donnees['token'], 'password' => $donnees['password']],
            function (User $utilisateur, string $motDePasse) {
                $utilisateur->forceFill(['password' => Hash::make($motDePasse)])->save();
                // Toute session ouverte avec l’ancien mot de passe est fermée.
                $utilisateur->tokens()->delete();
                event(new PasswordReset($utilisateur));
                $this->audit->log('auth.password_reset', $utilisateur, null, null, $utilisateur->id);
            }
        );

        if ($statut !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => ['Ce lien n’est plus valable. Demandez-en un nouveau depuis « Mot de passe oublié ».'],
            ]);
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }

    public static function lien(string $email, string $jeton): string
    {
        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');

        return $base.'/reinitialiser-mot-de-passe?token='.urlencode($jeton).'&email='.urlencode($email);
    }
}
