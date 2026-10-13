<?php

declare(strict_types=1);

/**
 * Juge de parite M-Files / GEDv2 (lot ged-mfiles-oracle-1).
 *
 * Meme methode que `tools/parite/juge.php` (Paperless), sur l'inventaire construit depuis la
 * DOCUMENTATION PUBLIQUE M-Files (`docs/parite/inventaire-mfiles.json`, regenere depuis la capture
 * versionnee `docs/parite/mfiles-rest-api.capture.json` par `tools/parite/extraire-mfiles.py`) :
 *  (a) regenere l'inventaire et le compare au fichier versionne ;
 *  (b) ROUGE si un element de l'inventaire n'a pas de statut dans correspondance.json ;
 *  (c) pour chaque « couvert », verifie que la route/l'ecran/le service GEDv2 existe ET que la preuve
 *      citee a reussi au dernier rejeu (var/phpunit.xml, rapport Playwright) ; sinon reclasse « non-prouve » ;
 *  (d) ecrit recette/parite-mfiles.json et docs/parite/PARITE-MFILES.md.
 *
 * Aucune exclusion n'est posee par l'agent : un element sans equivalent reste `absent` et est propose
 * au juge. Le pourcentage n'est pas un critere de sortie : la couche est verte si l'inventaire est
 * complet et rejoue a l'identique.
 *
 * Usage : php tools/parite/juge-mfiles.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ged\Parite\InterpreteurPython;
use Ged\Parite\Juge;

$racine = dirname(__DIR__, 2);
$erreurs = [];

/** @return array<string,mixed> */
function chargerJson(string $chemin): array
{
    if (!is_file($chemin)) {
        return [];
    }
    $donnees = json_decode((string) file_get_contents($chemin), true);

    return is_array($donnees) ? $donnees : [];
}

// ---------------------------------------------------------------------------
// (a) Regeneration de l'inventaire M-Files depuis la capture versionnee.
// ---------------------------------------------------------------------------

$capture = $racine . '/docs/parite/mfiles-rest-api.capture.json';
$versionne = chargerJson($racine . '/docs/parite/inventaire-mfiles.json');
$identique = false;
$detailIdentique = 'inventaire versionne absent';

if ($versionne === []) {
    $erreurs[] = 'docs/parite/inventaire-mfiles.json absent ou illisible';
} elseif (!is_file($capture)) {
    $erreurs[] = 'capture publique M-Files absente : docs/parite/mfiles-rest-api.capture.json';
    $detailIdentique = 'capture absente';
} else {
    $interpreteur = InterpreteurPython::resoudre();
    if ($interpreteur === []) {
        $erreurs[] = 'aucun interpreteur Python 3 : inventaire M-Files non regenerable (poser GED_PYTHON)';
        $detailIdentique = 'interpreteur Python 3 introuvable';
    } else {
        $sortie = $racine . '/var/parite/inventaire-mfiles-rejoue.json';
        if (!is_dir(dirname($sortie))) {
            mkdir(dirname($sortie), 0777, true);
        }
        $morceaux = array_merge($interpreteur, [
            $racine . '/tools/parite/extraire-mfiles.py',
            '--capture', $capture,
            '--sortie', $sortie,
        ]);
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tuyaux = [];
        $processus = @proc_open($morceaux, $descripteurs, $tuyaux);
        if (!is_resource($processus)) {
            $erreurs[] = 'execution de l\'extracteur M-Files impossible';
            $detailIdentique = 'extracteur non executable';
        } else {
            $stdout = (string) stream_get_contents($tuyaux[1]);
            $stderr = (string) stream_get_contents($tuyaux[2]);
            fclose($tuyaux[1]);
            fclose($tuyaux[2]);
            $code = proc_close($processus);
            if ($code !== 0) {
                $erreurs[] = 'extracteur M-Files en echec (code ' . $code . ') : ' . trim($stderr . ' ' . $stdout);
                $detailIdentique = 'extraction en echec';
            } else {
                $rejoue = chargerJson($sortie);
                $identique = isset($rejoue['elements'], $versionne['elements'])
                    && $rejoue['elements'] === $versionne['elements']
                    && ($rejoue['comptage'] ?? null) === ($versionne['comptage'] ?? null);
                $detailIdentique = $identique
                    ? 'inventaire rejoue a l\'identique (' . (int) ($versionne['total'] ?? 0) . ' elements)'
                    : 'inventaire rejoue DIFFERENT du fichier versionne';
                if (!$identique) {
                    $erreurs[] = 'l\'inventaire M-Files rejoue differe du fichier versionne';
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Correspondance M-Files (ids `mfiles:*`) et environnement GEDv2.
// ---------------------------------------------------------------------------

$correspondance = chargerJson($racine . '/docs/parite/correspondance.json');
$toutes = is_array($correspondance['elements'] ?? null) ? $correspondance['elements'] : [];
$entrees = [];
foreach ($toutes as $id => $entree) {
    if (str_starts_with((string) $id, 'mfiles:')) {
        $entrees[(string) $id] = $entree;
    }
}

/** @var array{routes:list<string>, ecrans:list<string>, services:list<string>} $connu */
$connu = ['routes' => [], 'ecrans' => [], 'services' => []];
$iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($iterateur as $fichier) {
    if (!$fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($fichier->getPathname());
    if (preg_match_all('/\$app->(get|post|put|patch|delete|any)\(\s*\'([^\']+)\'/', $source, $trouves, PREG_SET_ORDER) > 0) {
        foreach ($trouves as $trouve) {
            $connu['routes'][] = strtoupper($trouve[1]) . ' ' . $trouve[2];
        }
    }
}
$connu['routes'] = array_values(array_unique($connu['routes']));

$gabarits = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/templates', FilesystemIterator::SKIP_DOTS));
foreach ($gabarits as $fichier) {
    if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'twig') {
        $connu['ecrans'][] = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
    }
}
$connu['ecrans'] = array_values(array_unique($connu['ecrans']));

foreach ($entrees as $entree) {
    if (is_array($entree) && ($entree['gedv2']['type'] ?? '') === 'service') {
        $classe = (string) ($entree['gedv2']['classe'] ?? '');
        if ($classe !== '' && class_exists($classe)) {
            $connu['services'][] = $classe;
        }
    }
}
$connu['services'] = array_values(array_unique($connu['services']));

// ---------------------------------------------------------------------------
// Preuves du dernier rejeu (PHPUnit + Playwright), comme le juge Paperless.
// ---------------------------------------------------------------------------

$preuvesReussies = [];
$preuvesEchouees = [];
$junit = $racine . '/var/phpunit.xml';
if (is_file($junit)) {
    $xml = @simplexml_load_file($junit);
    if ($xml !== false) {
        /** @var array<string, array{reussis:int, echoues:int}> $parBase */
        $parBase = [];
        foreach ($xml->xpath('//testcase') ?: [] as $test) {
            $classe = (string) $test['class'];
            $nom = (string) $test['name'];
            if ($classe === '' || $nom === '') {
                continue;
            }
            $identifiant = $classe . '::' . $nom;
            $echoue = isset($test->failure) || isset($test->error);
            if ($echoue) {
                $preuvesEchouees[] = $identifiant;
            } else {
                $preuvesReussies[] = $identifiant;
            }
            if (str_contains($nom, ' with data set ')) {
                $base = $classe . '::' . substr($nom, 0, (int) strpos($nom, ' with data set '));
                $parBase[$base] ??= ['reussis' => 0, 'echoues' => 0];
                $parBase[$base][$echoue ? 'echoues' : 'reussis']++;
            }
        }
        foreach ($parBase as $base => $compte) {
            if ($compte['echoues'] === 0 && $compte['reussis'] > 0) {
                $preuvesReussies[] = $base;
            } else {
                $preuvesEchouees[] = $base;
            }
        }
    } else {
        $erreurs[] = 'var/phpunit.xml illisible : preuves PHPUnit non verifiables';
    }
} else {
    $erreurs[] = 'var/phpunit.xml absent : preuves PHPUnit non verifiables (lancer la couche 1)';
}

$preuvesReussies = array_values(array_unique($preuvesReussies));
$preuvesEchouees = array_values(array_unique($preuvesEchouees));

// ---------------------------------------------------------------------------
// Jugement.
// ---------------------------------------------------------------------------

$juge = new Juge();
$rapport = $juge->analyser(
    is_array($versionne['elements'] ?? null) ? $versionne['elements'] : [],
    $entrees,
    $preuvesReussies,
    $preuvesEchouees,
    $connu,
);
$erreurs = array_values(array_unique(array_merge($erreurs, is_array($rapport['erreurs']) ? $rapport['erreurs'] : [])));

// Comportement : mesures ecrites par tests/php/Parite/MfilesVecteursTest.php.
$comportement = [];
foreach (glob($racine . '/var/parite/mfiles-*.json') ?: [] as $fichierMesure) {
    $mesure = chargerJson($fichierMesure);
    if ($mesure !== [] && isset($mesure['categorie'])) {
        $comportement[(string) $mesure['categorie']] = $mesure;
    }
}

$resultat = [
    'version' => 1,
    'date' => (new DateTimeImmutable())->format('c'),
    'reference' => $versionne['reference'] ?? [],
    'inventaire_identique' => $identique,
    'inventaire_detail' => $detailIdentique,
    'preuves_phpunit_reussies' => count($preuvesReussies),
    'preuves_phpunit_echouees' => count($preuvesEchouees),
    'total' => $rapport['total'],
    'global' => $rapport['global'],
    'categories' => $rapport['categories'],
    'comportement' => array_map(static fn (array $m): array => ['total' => $m['total'] ?? 0, 'passes' => $m['passes'] ?? 0], $comportement),
    'erreurs' => $erreurs,
    'ecarts' => $rapport['ecarts'],
    'exclusions' => $rapport['exclusions'],
];
if (!is_dir($racine . '/recette')) {
    mkdir($racine . '/recette', 0777, true);
}
file_put_contents(
    $racine . '/recette/parite-mfiles.json',
    json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

// ---------------------------------------------------------------------------
// Rapport lisible docs/parite/PARITE-MFILES.md (deterministe : pas d'horodatage).
// ---------------------------------------------------------------------------

$lignes = [];
$lignes[] = '# Parite M-Files / GEDv2';
$lignes[] = '';
$lignes[] = 'Mesure mecanique du lot `ged-mfiles-oracle-1`, calculee depuis la DOCUMENTATION PUBLIQUE M-Files';
$lignes[] = '(' . (string) ($versionne['reference']['source'] ?? '') . '). Aucune ligne de code M-Files n\'est lue :';
$lignes[] = 'l\'inventaire ne contient que des titres, des chemins et des verbes HTTP publics.';
$lignes[] = '';
$lignes[] = 'Un element n\'est couvert que si l\'equivalent GEDv2 cite existe ET si la preuve citee a reussi au dernier';
$lignes[] = 'rejeu. Aucune exclusion n\'est posee par l\'agent : un element sans equivalent reste `absent` et est propose';
$lignes[] = 'au juge.';
$lignes[] = '';
$lignes[] = 'Inventaire : ' . ($identique ? 'rejoue a l\'identique' : '**DIFFERENT du fichier versionne**') . ' — ' . $detailIdentique . '.';
$lignes[] = '';
$global = $rapport['global'];
$lignes[] = '## Synthese';
$lignes[] = '';
$lignes[] = '| Total | Couverts | Absents | Non prouves | Exclus | Parite stricte | Parite hors exclusions |';
$lignes[] = '| ---: | ---: | ---: | ---: | ---: | ---: | ---: |';
$lignes[] = sprintf(
    '| %d | %d | %d | %d | %d | %s %% | %s %% |',
    (int) $global['total'],
    (int) $global['couverts'],
    (int) $global['absents'],
    (int) $global['non_prouves'],
    (int) $global['exclus'],
    number_format((float) $global['strict_pct'], 2, ',', ' '),
    number_format((float) $global['hors_exclusions_pct'], 2, ',', ' '),
);
$lignes[] = '';

$index = [];
foreach (is_array($versionne['elements'] ?? null) ? $versionne['elements'] : [] as $element) {
    if (is_array($element)) {
        $index[(string) $element['id']] = $element;
    }
}
$ecartParId = [];
foreach ($rapport['ecarts'] as $ecart) {
    $ecartParId[(string) $ecart['id']] = $ecart;
}

$categories = array_keys($rapport['categories']);
sort($categories);
foreach ($categories as $categorie) {
    $lignes[] = '## ' . $categorie;
    $lignes[] = '';
    $lignes[] = '| Fonction M-Files | Reference publique | Statut | Equivalent GEDv2 | Preuve / harness | Lot |';
    $lignes[] = '| --- | --- | --- | --- | --- | --- |';
    foreach ($index as $id => $element) {
        if ((string) ($element['categorie'] ?? '') !== $categorie) {
            continue;
        }
        $entree = is_array($entrees[$id] ?? null) ? $entrees[$id] : [];
        $statut = (string) ($ecartParId[$id]['statut'] ?? ($entree['statut'] ?? 'sans-statut'));
        $gedv2 = $entree['gedv2'] ?? null;
        $equivalent = '-';
        if (is_array($gedv2)) {
            $equivalent = match ($gedv2['type'] ?? '') {
                'route' => 'route ' . strtoupper((string) ($gedv2['methode'] ?? '')) . ' ' . (string) ($gedv2['chemin'] ?? ''),
                'ecran' => 'ecran ' . (string) ($gedv2['fichier'] ?? ''),
                'service' => 'service ' . (string) ($gedv2['classe'] ?? ''),
                default => '-',
            };
        }
        $preuve = is_array($entree['preuve'] ?? null)
            ? (string) ($entree['preuve']['type'] ?? '') . ' ' . (string) ($entree['preuve']['cible'] ?? '')
            : '-';
        if (in_array($statut, ['couvert', 'couvert-autrement'], true)) {
            $preuve .= ' (couche ' . (($entree['preuve']['type'] ?? '') === 'playwright' ? '4' : '1') . ')';
        } else {
            $preuve = '-';
        }
        $raison = (string) ($ecartParId[$id]['raison'] ?? '');
        $lot = (string) ($ecartParId[$id]['lot'] ?? ($entree['lot'] ?? ''));
        $lignes[] = sprintf(
            '| %s | %s | %s%s | %s | %s | %s |',
            str_replace('|', '\\|', (string) ($element['libelle'] ?? '')),
            (string) ($element['reference'] ?? ''),
            $statut,
            $raison !== '' ? ' (' . str_replace('|', '\\|', $raison) . ')' : '',
            str_replace('|', '\\|', $equivalent),
            str_replace('|', '\\|', $preuve),
            $lot,
        );
    }
    $lignes[] = '';
}

// Comportement (vecteurs) : $comportement est déjà calculé pour le JSON ci-dessus.
$lignes[] = '## Comportement (vecteurs)';
$lignes[] = '';
if ($comportement === []) {
    $lignes[] = 'Aucune mesure de comportement disponible (lancer tests/php/Parite/MfilesVecteursTest.php).';
} else {
    $lignes[] = 'Vecteurs de comportement derives de la documentation PUBLIQUE M-Files (versions, historique,';
    $lignes[] = 'corbeille, fichiers), rejoues contre le vrai service `Ged\\DepotFileserver`. Un ecart fait echouer';
    $lignes[] = 'le rejeu PHPUnit.';
    $lignes[] = '';
    $lignes[] = '| Famille | Fichier de vecteurs | Passes | Total | Taux |';
    $lignes[] = '| --- | --- | ---: | ---: | ---: |';
    ksort($comportement);
    foreach ($comportement as $nomMesure => $mesure) {
        $total = (int) ($mesure['total'] ?? 0);
        $passes = (int) ($mesure['passes'] ?? 0);
        $lignes[] = sprintf(
            '| %s | tests/parite/vecteurs/%s.json | %d | %d | %s %% |',
            (string) $nomMesure,
            (string) $nomMesure,
            $passes,
            $total,
            number_format($total > 0 ? round($passes / $total * 100, 2) : 0.0, 2, ',', ' '),
        );
    }
}
$lignes[] = '';

$lignes[] = '## Carnet de commandes (ecarts)';
$lignes[] = '';
$lignes[] = 'Chaque ecart (absent, non prouve, exclu) est listé ci-dessous avec sa reference publique.';
$lignes[] = '';
$lignes[] = '| Id | Reference publique | Statut | Raison | Lot |';
$lignes[] = '| --- | --- | --- | --- | --- |';
foreach ($rapport['ecarts'] as $ecart) {
    $lignes[] = sprintf(
        '| %s | %s | %s | %s | %s |',
        str_replace('|', '\\|', (string) $ecart['id']),
        (string) $ecart['reference'],
        (string) $ecart['statut'],
        str_replace('|', '\\|', (string) $ecart['raison']),
        (string) $ecart['lot'],
    );
}
$lignes[] = '';

file_put_contents($racine . '/docs/parite/PARITE-MFILES.md', implode("\n", $lignes) . "\n");

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------

$ok = $identique && $erreurs === [];
printf(
    "parite M-Files : %d elements | couverts %d | strict %s %% | hors exclusions %s %% | ecarts %d | %s\n",
    (int) $global['total'],
    (int) $global['couverts'],
    number_format((float) $global['strict_pct'], 2, ',', ' '),
    number_format((float) $global['hors_exclusions_pct'], 2, ',', ' '),
    count($rapport['ecarts']),
    $ok ? 'VERT' : 'ROUGE',
);
foreach ($erreurs as $erreur) {
    fwrite(STDERR, 'ERREUR parite M-Files : ' . $erreur . "\n");
}

exit($ok ? 0 : 1);
