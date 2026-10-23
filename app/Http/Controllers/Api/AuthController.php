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
use Illuminate\Support\Facades\Hash;
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
            'phone' => ['nullable', 'string', 'max:30', 'unique:utilisateurs,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['nullable', 'in:customer,partner'],
        ], [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L’adresse e-mail est obligatoire.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'email.unique' => 'Cette adresse e-mail est déjà utilisée. Connectez-vous ou choisissez une autre adresse.',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
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

        // Le partenaire complète société, RCCM et adresse dans son espace, pas à l’inscription.
        if ($role === UserRole::Partner->value) {
            Partner::create([
                'owner_user_id' => $user->id,
                'type' => 'individual',
                'company_name' => $data['name'],
                'manager_name' => $data['name'],
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

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
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
}
