<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Lit et écrit un classeur .xlsx simple (première feuille de données).
 * Évite une dépendance tableur : le modèle DOTO n’a que du texte.
 */
class ClasseurExcel
{
    /**
     * @param  array<string, list<list<string>>>  $feuilles  Nom => lignes (première ligne = en-têtes)
     */
    public static function ecrire(array $feuilles): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Le serveur ne peut pas produire de fichier Excel.');
        }

        $chaines = [];
        $indexChaine = [];
        $xmlFeuilles = [];

        foreach ($feuilles as $lignes) {
            $lignesXml = '';
            foreach (array_values($lignes) as $numero => $cellules) {
                $rang = $numero + 1;
                $cellulesXml = '';
                foreach (array_values($cellules) as $colonne => $valeur) {
                    $texte = (string) $valeur;
                    if (! array_key_exists($texte, $indexChaine)) {
                        $indexChaine[$texte] = count($chaines);
                        $chaines[] = $texte;
                    }
                    $ref = self::lettre($colonne).$rang;
                    $cellulesXml .= '<c r="'.$ref.'" t="s"><v>'.$indexChaine[$texte].'</v></c>';
                }
                $lignesXml .= '<row r="'.$rang.'">'.$cellulesXml.'</row>';
            }
            $xmlFeuilles[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetData>'.$lignesXml.'</sheetData></worksheet>';
        }

        $chainesXml = '';
        foreach ($chaines as $chaine) {
            $chainesXml .= '<si><t xml:space="preserve">'.self::echapper($chaine).'</t></si>';
        }
        $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($chaines).'" uniqueCount="'.count($chaines).'">'
            .$chainesXml.'</sst>';

        $noms = array_keys($feuilles);
        $sheets = '';
        $overrides = '';
        $relations = '';
        foreach ($noms as $i => $nom) {
            $id = $i + 1;
            $sheets .= '<sheet name="'.self::echapper($nom).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $relations .= '<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
        }
        $relations .= '<Relationship Id="rId'.(count($noms) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';

        $fichiers = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .$overrides
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'.$sheets.'</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>',
            'xl/sharedStrings.xml' => $shared,
        ];
        foreach ($xmlFeuilles as $i => $xml) {
            $fichiers['xl/worksheets/sheet'.($i + 1).'.xml'] = $xml;
        }

        $temporaire = tempnam(sys_get_temp_dir(), 'doto-xlsx-');
        if ($temporaire === false) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }

        $zip = new ZipArchive;
        if ($zip->open($temporaire, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de préparer le fichier Excel.');
        }
        foreach ($fichiers as $chemin => $contenu) {
            $zip->addFromString($chemin, $contenu);
        }
        $zip->close();

        $binaire = file_get_contents($temporaire);
        @unlink($temporaire);
        if ($binaire === false) {
            throw new RuntimeException('Impossible de lire le fichier Excel généré.');
        }

        return $binaire;
    }

    /**
     * Aperçu de la première feuille qui contient des lignes, sans rien enregistrer.
     * Les numéros de ligne suivent le même décompte que l’import (en-tête = ligne 1).
     *
     * @return array{feuille: string, colonnes: list<string>, lignes: list<array{ligne: int, cellules: list<string>}>, total: int}
     */
    public static function apercu(string $chemin, int $limite = 200): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Le serveur ne peut pas lire les fichiers Excel.');
        }

        $limite = max(1, min($limite, 500));
        $zip = new ZipArchive;
        if ($zip->open($chemin) !== true) {
            throw new RuntimeException('Le fichier Excel est illisible. Enregistrez-le au format .xlsx.');
        }

        $chaines = self::chainesPartagees($zip);
        $choisie = null;
        foreach (self::feuillesNommees($zip) as $feuille) {
            $xml = $zip->getFromName($feuille['chemin']);
            if ($xml === false) {
                continue;
            }
            $lignes = self::lignes($xml, $chaines);
            if ($lignes !== []) {
                $choisie = ['nom' => $feuille['nom'], 'lignes' => $lignes];
                break;
            }
        }
        $zip->close();

        if ($choisie === null) {
            throw new RuntimeException('Le fichier ne contient aucune donnée à afficher.');
        }

        $largeur = 0;
        foreach ($choisie['lignes'] as $ligne) {
            if ($ligne !== []) {
                $largeur = max($largeur, max(array_keys($ligne)) + 1);
            }
        }

        $entete = $choisie['lignes'][0];
        $colonnes = [];
        for ($index = 0; $index < $largeur; $index++) {
            $nom = trim((string) ($entete[$index] ?? ''));
            $colonnes[] = $nom !== '' ? $nom : 'Colonne '.($index + 1);
        }

        $donnees = array_slice($choisie['lignes'], 1);
        $extrait = [];
        foreach (array_slice($donnees, 0, $limite) as $offset => $cellules) {
            $valeurs = [];
            for ($index = 0; $index < $largeur; $index++) {
                $valeurs[] = trim((string) ($cellules[$index] ?? ''));
            }
            if (implode('', $valeurs) === '') {
                continue;
            }
            $extrait[] = ['ligne' => $offset + 2, 'cellules' => $valeurs];
        }

        return [
            'feuille' => $choisie['nom'],
            'colonnes' => $colonnes,
            'lignes' => $extrait,
            'total' => count($donnees),
        ];
    }

    /**
     * Retourne les lignes de la feuille dont l’en-tête contient « liste » et « libellé ».
     *
     * @return list<array<string, string>>
     */
    public static function lireTableau(string $chemin): array
    {
        return self::lireFeuille($chemin, ['liste', 'libelle']);
    }

    /**
     * Indique si une feuille du classeur porte toutes les colonnes demandées.
     *
     * @param  list<string>  $cles
     */
    public static function contientColonnes(string $chemin, array $cles): bool
    {
        if (! class_exists(ZipArchive::class) || $cles === []) {
            return false;
        }

        $zip = new ZipArchive;
        if ($zip->open($chemin) !== true) {
            return false;
        }

        $chaines = self::chainesPartagees($zip);
        $trouve = false;
        foreach (self::cheminsFeuilles($zip) as $feuille) {
            $xml = $zip->getFromName($feuille);
            if ($xml === false) {
                continue;
            }
            $entetes = self::entetes(self::lignes($xml, $chaines)[0] ?? []);
            $complete = true;
            foreach ($cles as $cle) {
                if (! isset($entetes[$cle])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                $trouve = true;
                break;
            }
        }
        $zip->close();

        return $trouve;
    }

    /**
     * Retourne les lignes de la première feuille dont l’en-tête contient toutes les clés demandées.
     *
     * @param  list<string>  $cles
     * @return list<array<string, string>>
     */
    public static function lireFeuille(string $chemin, array $cles): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Le serveur ne peut pas lire les fichiers Excel.');
        }

        $zip = new ZipArchive;
        if ($zip->open($chemin) !== true) {
            throw new RuntimeException('Le fichier Excel est illisible. Enregistrez-le au format .xlsx.');
        }

        $chaines = self::chainesPartagees($zip);
        $feuilles = self::cheminsFeuilles($zip);
        $tableau = null;

        foreach ($feuilles as $feuille) {
            $xml = $zip->getFromName($feuille);
            if ($xml === false) {
                continue;
            }
            $lignes = self::lignes($xml, $chaines);
            $entetes = self::entetes($lignes[0] ?? []);
            $complete = true;
            foreach ($cles as $cle) {
                if (! isset($entetes[$cle])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete && $cles !== []) {
                $tableau = self::associer($lignes, $entetes);
                break;
            }
        }

        $zip->close();

        if ($tableau === null) {
            throw new RuntimeException('Le fichier Excel ne correspond pas au modèle attendu. Téléchargez-le à nouveau.');
        }

        return $tableau;
    }

    /**
     * @return list<string>
     */
    private static function chainesPartagees(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return [];
        }
        $chaines = [];
        foreach ($dom->getElementsByTagName('si') as $bloc) {
            $texte = '';
            foreach ($bloc->getElementsByTagName('t') as $noeud) {
                $texte .= $noeud->textContent;
            }
            $chaines[] = $texte;
        }

        return $chaines;
    }

    /**
     * @return list<array{nom: string, chemin: string}>
     */
    private static function feuillesNommees(ZipArchive $zip): array
    {
        $classeur = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($classeur === false || $relations === false) {
            return [['nom' => 'Feuille 1', 'chemin' => 'xl/worksheets/sheet1.xml']];
        }

        $cibles = [];
        $domRels = new \DOMDocument;
        if (@$domRels->loadXML($relations)) {
            foreach ($domRels->getElementsByTagName('Relationship') as $relation) {
                $cible = $relation->getAttribute('Target');
                $cibles[$relation->getAttribute('Id')] = str_starts_with($cible, '/')
                    ? ltrim($cible, '/')
                    : 'xl/'.ltrim($cible, '/');
            }
        }

        $feuilles = [];
        $dom = new \DOMDocument;
        if (@$dom->loadXML($classeur)) {
            foreach ($dom->getElementsByTagName('sheet') as $feuille) {
                $id = $feuille->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                if ($id !== '' && isset($cibles[$id])) {
                    $nom = trim($feuille->getAttribute('name'));
                    $feuilles[] = [
                        'nom' => $nom !== '' ? $nom : 'Feuille '.(count($feuilles) + 1),
                        'chemin' => $cibles[$id],
                    ];
                }
            }
        }

        return $feuilles !== [] ? $feuilles : [['nom' => 'Feuille 1', 'chemin' => 'xl/worksheets/sheet1.xml']];
    }

    /**
     * @return list<string>
     */
    private static function cheminsFeuilles(ZipArchive $zip): array
    {
        return array_column(self::feuillesNommees($zip), 'chemin');
    }

    /**
     * @param  list<string>  $chaines
     * @return list<array<int, string>>
     */
    private static function lignes(string $xml, array $chaines): array
    {
        $dom = new \DOMDocument;
        if (! @$dom->loadXML($xml)) {
            return [];
        }

        $lignes = [];
        foreach ($dom->getElementsByTagName('row') as $ligne) {
            $cellules = [];
            $colonne = 0;
            foreach ($ligne->getElementsByTagName('c') as $cellule) {
                $ref = $cellule->getAttribute('r');
                if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $correspondance)) {
                    $colonne = self::indexLettre($correspondance[1]);
                }
                $cellules[$colonne] = self::valeurCellule($cellule, $chaines);
                $colonne++;
            }
            if (implode('', $cellules) !== '') {
                $lignes[] = $cellules;
            }
        }

        return $lignes;
    }

    /**
     * @param  list<string>  $chaines
     */
    private static function valeurCellule(\DOMElement $cellule, array $chaines): string
    {
        $type = $cellule->getAttribute('t');
        if ($type === 'inlineStr') {
            $texte = '';
            foreach ($cellule->getElementsByTagName('t') as $noeud) {
                $texte .= $noeud->textContent;
            }

            return $texte;
        }

        $brut = $cellule->getElementsByTagName('v')->item(0)?->textContent ?? '';
        if ($type === 's') {
            return $chaines[(int) $brut] ?? '';
        }
        if ($type === 'b') {
            return $brut === '1' ? 'oui' : 'non';
        }

        return $brut;
    }

    /**
     * @param  array<int, string>  $entete
     * @return array<string, int>
     */
    private static function entetes(array $entete): array
    {
        $colonnes = [];
        foreach ($entete as $index => $nom) {
            $cle = self::cle((string) $nom);
            if ($cle !== '') {
                $colonnes[$cle] = $index;
            }
        }

        return $colonnes;
    }

    /**
     * @param  list<array<int, string>>  $lignes
     * @param  array<string, int>  $entetes
     * @return list<array<string, string>>
     */
    private static function associer(array $lignes, array $entetes): array
    {
        $tableau = [];
        foreach (array_slice($lignes, 1) as $offset => $cellules) {
            $ligne = ['__ligne' => (string) ($offset + 2)];
            foreach ($entetes as $cle => $index) {
                $ligne[$cle] = trim((string) ($cellules[$index] ?? ''));
            }
            if (implode('', array_diff_key($ligne, ['__ligne' => true])) === '') {
                continue;
            }
            $tableau[] = $ligne;
        }

        return $tableau;
    }

    private static function cle(string $nom): string
    {
        $texte = mb_strtolower($nom);
        $texte = strtr($texte, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
            "'" => '', '’' => '',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $texte), '_');
    }

    private static function lettre(int $index): string
    {
        $lettres = '';
        $nombre = $index + 1;
        while ($nombre > 0) {
            $nombre--;
            $lettres = chr(65 + ($nombre % 26)).$lettres;
            $nombre = intdiv($nombre, 26);
        }

        return $lettres;
    }

    private static function indexLettre(string $lettres): int
    {
        $index = 0;
        foreach (str_split($lettres) as $lettre) {
            $index = $index * 26 + (ord($lettre) - 64);
        }

        return $index - 1;
    }

    private static function echapper(string $valeur): string
    {
        return htmlspecialchars($valeur, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
