<?php

namespace App\Http\Controllers\Api;

use App\Enums\PartnerStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\Partner;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private AuditService $audit) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:utilisateurs,email'],
            'phone' => ['nullable', 'required_if:role,partner', 'string', 'max:30', 'unique:utilisateurs,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['nullable', 'in:customer,partner'],
            'company_name' => ['nullable', 'required_if:role,partner', 'string', 'max:160'],
            'city' => ['nullable', 'required_if:role,partner', 'string', 'max:80'],
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Connectez-vous ou choisissez une autre adresse.',
            'phone.required_if' => 'Le téléphone est obligatoire pour un compte partenaire.',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'company_name.required_if' => 'Le nom de l’entreprise est obligatoire.',
            'city.required_if' => 'La ville est obligatoire pour un compte partenaire.',
        ]);

        $role = $data['role'] ?? 'customer';

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => $role,
            'status' => 'active',
            'locale' => 'fr',
        ]);

        if ($role === UserRole::Customer->value) {
            CustomerProfile::create([
                'user_id' => $user->id,
                'first_name' => $data['name'],
            ]);
        }

        // Société, responsable, ville et téléphone suffisent à ouvrir l’espace.
        // RCCM, NIF et adresse se complètent ensuite dans le dossier partenaire.
        if ($role === UserRole::Partner->value) {
            Partner::create([
                'owner_user_id' => $user->id,
                'type' => 'agency',
                'company_name' => $data['company_name'],
                'manager_name' => $data['name'],
                'city' => $data['city'],
                'phone' => $data['phone'] ?? null,
                'status' => PartnerStatus::Pending,
                'trust_level' => 'new',
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;
        $this->audit->log('auth.register', $user, null, ['role' => $role], $user->id);

        return response()->json([
            'user' => $user->load('customerProfile', 'ownedPartner'),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        // Les comptes de démo ont été affichés sur doto241.com avant le renommage en base.
        $anciennesAdressesDemo = [
            'admin@doto241.com' => 'admin@locagabon.ga',
            'client@doto241.com' => 'client@locagabon.ga',
            'partenaire@doto241.com' => 'partenaire@locagabon.ga',
            'pending@doto241.com' => 'pending@locagabon.ga',
        ];
        $adresseSaisie = $credentials['email'];
        if (! $user && isset($anciennesAdressesDemo[$adresseSaisie])) {
            $user = User::where('email', $anciennesAdressesDemo[$adresseSaisie])->first();
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        if ($user->email !== $adresseSaisie && isset($anciennesAdressesDemo[$adresseSaisie])) {
            $user->update(['email' => $adresseSaisie]);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Compte suspendu ou banni.'], 403);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('api')->plainTextToken;

        $this->audit->log('auth.login', $user, null, [
            'role' => $user->role instanceof \BackedEnum ? $user->role->value : $user->role,
        ], $user->id);

        return response()->json([
            'user' => $user->load('customerProfile', 'ownedPartner'),
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('customerProfile', 'ownedPartner'),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->audit->log('auth.logout', $user, null, null, $user->id);
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /**
     * Le titulaire met à jour son identité. Le rôle et le statut restent du ressort de l’administration.
     */
    public function mettreAJourProfil(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:utilisateurs,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30', 'unique:utilisateurs,phone,'.$user->id],
            'locale' => ['required', 'in:fr,en'],
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée.',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'locale.in' => 'La langue doit être le français ou l’anglais.',
        ]);

        $avant = $user->only(['name', 'email', 'phone', 'locale']);
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] !== '' ? $data['phone'] : null,
            'locale' => $data['locale'],
        ]);
        $this->audit->log('auth.profil.modifie', $user, $avant, $user->only(['name', 'email', 'phone', 'locale']), $user->id);

        return $this->reponseCompte($user, 'Profil enregistré.');
    }

    /** Change le mot de passe après vérification de l’actuel, pour éviter une prise de session ouverte. */
    public function changerMotDePasse(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'mot_de_passe_actuel' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'mot_de_passe_actuel.required' => 'Le mot de passe actuel est obligatoire.',
            'password.required' => 'Le nouveau mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères.',
        ]);

        if (! Hash::check($data['mot_de_passe_actuel'], $user->password)) {
            throw ValidationException::withMessages([
                'mot_de_passe_actuel' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);
        $this->audit->log('auth.mot_de_passe.modifie', $user, null, null, $user->id);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }

    public function mettreAJourPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'photo.required' => 'Choisissez une photo.',
            'photo.image' => 'Le fichier doit être une image.',
            'photo.mimes' => 'Formats acceptés : JPG, PNG ou WebP.',
            'photo.max' => 'La photo ne doit pas dépasser 2 Mo.',
        ]);

        $user = $request->user();
        $this->supprimerFichierPhoto($user->photo, $user->id);

        $fichier = $request->file('photo');
        $url = $this->enregistrerPhoto($fichier, $user->id);
        $user->update(['photo' => $url]);
        $this->audit->log('auth.photo.modifiee', $user, null, ['photo' => $url], $user->id);

        return $this->reponseCompte($user, 'Photo de profil enregistrée.');
    }

    public function supprimerPhoto(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->supprimerFichierPhoto($user->photo, $user->id);
        $user->update(['photo' => null]);
        $this->audit->log('auth.photo.supprimee', $user, null, null, $user->id);

        return $this->reponseCompte($user, 'Photo de profil retirée.');
    }

    private function reponseCompte(User $user, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'user' => $user->fresh()->load('customerProfile', 'ownedPartner'),
        ]);
    }

    /** Range l’image sous public/images/profils, déjà servi par le site sans lien symbolique. */
    private function enregistrerPhoto(UploadedFile $fichier, int $utilisateurId): string
    {
        $dossier = public_path('images/profils');
        if (! is_dir($dossier)) {
            mkdir($dossier, 0755, true);
        }

        $extension = strtolower($fichier->guessExtension() ?: 'jpg');
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        $nom = 'profil-'.$utilisateurId.'-'.Str::uuid().'.'.$extension;
        $fichier->move($dossier, $nom);

        return url('images/profils/'.$nom);
    }

    /** N’efface que le fichier déposé pour ce compte, jamais un chemin arbitraire. */
    private function supprimerFichierPhoto(?string $url, int $utilisateurId): void
    {
        if (! $url) {
            return;
        }

        $chemin = (string) parse_url($url, PHP_URL_PATH);
        $nom = basename($chemin);
        if (! preg_match('/^profil-'.$utilisateurId.'-[A-Za-z0-9\-]+\.(jpe?g|png|webp)$/', $nom)) {
            return;
        }

        $absolu = public_path('images/profils/'.$nom);
        if (is_file($absolu)) {
            unlink($absolu);
        }
    }
}
