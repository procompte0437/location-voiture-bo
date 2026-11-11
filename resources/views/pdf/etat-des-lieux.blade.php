<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>État des lieux {{ $reservation->reference }}</title>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
  h1 { font-size: 18px; margin: 0 0 4px; color: #7c2d12; }
  .sous-titre { color: #6b7280; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; vertical-align: top; }
  th { background: #f3f4f6; width: 30%; }
  .colonnes td { width: 50%; }
  .signature { height: 60px; }
  .vide { color: #9ca3af; font-style: italic; }
</style>
</head>
<body>
@php
  $carburant = ['vide' => 'Vide', 'un_quart' => '1/4', 'moitie' => '1/2', 'trois_quarts' => '3/4', 'plein' => 'Plein'];
  $ligne = fn ($etat, $champ) => $etat ? ($champ === 'niveau_carburant' ? ($carburant[$etat->niveau_carburant] ?? $etat->niveau_carburant) : ($etat->{$champ} ?? '—')) : null;
@endphp
<h1>État des lieux</h1>
<p class="sous-titre">Réservation {{ $reservation->reference }} · édité le {{ now()->format('d/m/Y H:i') }}</p>

<table>
  <tr><th>Véhicule</th><td>{{ $reservation->vehicle?->brand }} {{ $reservation->vehicle?->model }} — {{ $reservation->vehicle?->plate_number }}</td></tr>
  <tr><th>Client</th><td>{{ $client }}</td></tr>
  <tr><th>Période prévue</th><td>du {{ $reservation->pickup_at?->format('d/m/Y H:i') }} au {{ $reservation->return_at?->format('d/m/Y H:i') }}</td></tr>
</table>

<table class="colonnes">
  <tr><th style="width:auto"></th><th>Départ</th><th>Retour</th></tr>
  <tr><th>Date</th>
    <td>{!! $depart ? e($depart->created_at->format('d/m/Y H:i')) : '<span class="vide">non renseigné</span>' !!}</td>
    <td>{!! $retour ? e($retour->created_at->format('d/m/Y H:i')) : '<span class="vide">non renseigné</span>' !!}</td></tr>
  <tr><th>Conducteur</th><td>{{ $ligne($depart, 'conducteur') ?? '—' }}</td><td>—</td></tr>
  <tr><th>Kilométrage</th>
    <td>{{ $depart ? number_format($depart->kilometrage, 0, ',', ' ').' km' : '—' }}</td>
    <td>{{ $retour ? number_format($retour->kilometrage, 0, ',', ' ').' km' : '—' }}</td></tr>
  <tr><th>Carburant</th><td>{{ $ligne($depart, 'niveau_carburant') ?? '—' }}</td><td>{{ $ligne($retour, 'niveau_carburant') ?? '—' }}</td></tr>
  <tr><th>Observations</th><td>{{ $depart?->notes ?: '—' }}</td><td>{{ $retour?->notes ?: '—' }}</td></tr>
  @if ($depart && $retour)
    <tr><th>Distance parcourue</th><td colspan="2">{{ number_format($retour->kilometrage - $depart->kilometrage, 0, ',', ' ') }} km</td></tr>
  @endif
</table>

<table class="colonnes">
  <tr><th style="width:auto">Signatures</th><th>Loueur</th><th>Client</th></tr>
  <tr><th>Départ</th><td class="signature"></td><td class="signature"></td></tr>
  <tr><th>Retour</th><td class="signature"></td><td class="signature"></td></tr>
</table>
</body>
</html>
