<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Client de l’API PVit (Airtel Money et Moov Money au Gabon).
 * Documentation : https://docs.mypvit.pro/fr/intro/integration-guide
 */
class PasserellePvit
{
    private const CLE_SECRET = 'pvit.secret';

    /** Les moyens du référentiel réglés par une demande envoyée sur le téléphone du client. */
    public function codeOperateur(string $moyen): ?string
    {
        return config('services.pvit.operateurs')[$moyen] ?? null;
    }

    public function estActive(): bool
    {
        $pvit = config('services.pvit');

        return filled($pvit['compte'])
            && filled($pvit['mot_de_passe'])
            && filled($pvit['code_secret'])
            && filled($pvit['code_paiement'])
            && filled($pvit['code_statut'])
            && filled($pvit['code_callback']);
    }

    public function montantMaximum(): int
    {
        return (int) config('services.pvit.montant_maximum');
    }

    /**
     * Envoie la demande de paiement sur le téléphone du client.
     *
     * @return array<string, mixed> réponse PVit, le statut y reste PENDING jusqu’au webhook
     */
    public function initierPaiement(int $montant, string $reference, string $numero, string $operateur, string $libelle): array
    {
        $pvit = config('services.pvit');

        $reponse = $this->requete()
            ->withHeaders(['X-Secret' => $this->secret(), 'X-Callback-MediaType' => 'application/json'])
            ->post($this->url('/v2/'.$pvit['code_paiement'].'/rest'), [
                'agent' => 'DOTO',
                'amount' => filled($pvit['montant_test']) ? (int) $pvit['montant_test'] : $montant,
                'product' => 'LOCATION',
                'reference' => $reference,
                'service' => 'RESTFUL',
                'callback_url_code' => $pvit['code_callback'],
                'customer_account_number' => $numero,
                'merchant_operation_account_code' => $pvit['compte'],
                'transaction_type' => 'PAYMENT',
                'owner_charge' => $pvit['frais_a_charge'],
                'owner_charge_operator' => $pvit['frais_a_charge'],
                'free_info' => mb_substr($libelle, 0, 60),
                'operator_code' => $operateur,
            ]);

        if ($reponse->status() === 401) {
            Cache::forget(self::CLE_SECRET);
        }

        if (! $reponse->successful()) {
            throw ValidationException::withMessages([
                'numero' => $reponse->json('message') ?: 'Le paiement n’a pas pu être lancé. Réessayez dans un instant.',
            ]);
        }

        return (array) $reponse->json();
    }

    /**
     * Statut définitif d’une transaction.
     *
     * @return array<string, mixed>|null null si PVit ne répond pas : la transaction reste alors en attente
     */
    public function statutTransaction(string $identifiant): ?array
    {
        $pvit = config('services.pvit');

        try {
            $reponse = $this->requete()
                ->withHeaders(['X-Secret' => $this->secret()])
                ->get($this->url('/'.$pvit['code_statut'].'/status'), [
                    'transactionId' => $identifiant,
                    'accountOperationCode' => $pvit['compte'],
                    'transactionOperation' => 'PAYMENT',
                ]);
        } catch (ConnectionException|ValidationException) {
            return null;
        }

        return $reponse->successful() ? (array) $reponse->json() : null;
    }

    /**
     * Prénom connu de l’opérateur pour ce numéro. Sert à tracer la transaction, jamais renvoyé au navigateur :
     * sinon n’importe qui pourrait découvrir le titulaire d’un numéro.
     */
    public function titulaire(string $numero, string $operateur): ?string
    {
        $code = config('services.pvit.code_kyc');
        if (blank($code)) {
            return null;
        }

        try {
            $reponse = $this->requete()
                ->withHeaders(['X-Secret' => $this->secret()])
                ->get($this->url('/v2/'.$code.'/kyc'), [
                    'customerAccountNumber' => $numero,
                    'operatorCode' => $operateur,
                ]);
        } catch (ConnectionException|ValidationException) {
            return null;
        }

        return $reponse->successful() ? $reponse->json('data.firstname') : null;
    }

    /** La clé PVit expire au bout d’une heure : elle est renouvelée à la demande, un peu avant. */
    private function secret(): string
    {
        $chiffre = Cache::get(self::CLE_SECRET);
        if (is_string($chiffre)) {
            return Crypt::decryptString($chiffre);
        }

        $pvit = config('services.pvit');

        try {
            $reponse = $this->requete()
                ->asForm()
                ->post($this->url('/v2/'.$pvit['code_secret'].'/renew-secret'), [
                    'operationAccountCode' => $pvit['compte'],
                    'password' => $pvit['mot_de_passe'],
                ]);
        } catch (ConnectionException) {
            throw $this->indisponible();
        }

        $secret = $reponse->json('secret');
        if (! $reponse->successful() || ! is_string($secret) || $secret === '') {
            throw $this->indisponible();
        }

        $duree = max(60, (int) $reponse->json('expires_in', 3600) - 120);
        Cache::put(self::CLE_SECRET, Crypt::encryptString($secret), $duree);

        return $secret;
    }

    private function indisponible(): ValidationException
    {
        return ValidationException::withMessages([
            'numero' => 'Le service de paiement ne répond pas. Réessayez dans un instant.',
        ]);
    }

    private function requete(): PendingRequest
    {
        return Http::acceptJson()->timeout(30);
    }

    private function url(string $chemin): string
    {
        return rtrim((string) config('services.pvit.url'), '/').$chemin;
    }
}
