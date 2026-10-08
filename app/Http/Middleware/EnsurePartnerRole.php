<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorise uniquement les rôles partenaires (propriétaire ou agent).
 * Empêche un client / admin d’appeler l’API espace partenaire.
 */
class EnsurePartnerRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        $role = $user->role instanceof UserRole
            ? $user->role
            : UserRole::tryFrom((string) $user->role);

        if (! in_array($role, [UserRole::Partner, UserRole::PartnerAgent], true)) {
            return response()->json([
                'message' => 'Accès réservé à l’espace partenaire.',
            ], 403);
        }

        return $next($request);
    }
}
