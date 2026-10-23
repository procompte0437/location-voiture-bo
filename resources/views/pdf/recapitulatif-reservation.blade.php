<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Récapitulatif {{ $reference }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1c1917; font-size: 12px; }
        .marque { font-size: 11px; letter-spacing: 2px; font-weight: bold; color: #8c4205; }
        h1 { margin: 6px 0 0; font-size: 22px; color: #14532d; }
        .reference { margin-top: 4px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        td { padding: 8px 0; border-bottom: 1px solid #e7e5e4; vertical-align: top; }
        .libelle { width: 36%; color: #78716c; font-size: 11px; }
        .valeur { font-weight: bold; }
        .total { margin-top: 16px; font-size: 18px; font-weight: bold; color: #14532d; }
        .note { margin-top: 18px; font-size: 11px; line-height: 1.5; color: #44403c; }
        .pied { margin-top: 28px; font-size: 10px; color: #a8a29e; }
    </style>
</head>
<body>
    <div class="marque">DOTO</div>
    <h1>Récapitulatif de réservation</h1>
    <p class="reference">Référence <strong>{{ $reference }}</strong></p>

    <table>
        @foreach ($lignes as $ligne)
            <tr>
                <td class="libelle">{{ $ligne['libelle'] }}</td>
                <td class="valeur">{{ $ligne['valeur'] }}</td>
            </tr>
        @endforeach
    </table>

    <p class="total">{{ $montant }}</p>
    <p class="note">
        Le loueur doit accepter ou refuser cette demande. Le règlement est suivi par le loueur :
        aucun paiement en ligne n'est encaissé à cette étape. La caution reste distincte du prix de location.
    </p>
    <p class="pied">Document édité le {{ $editeLe }} · DOTO, location de voitures</p>
</body>
</html>
