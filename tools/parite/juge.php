<?php

declare(strict_types=1);

/**
 * Couche 9 du harness : juge de parite Paperless-ngx / GEDv2 (lot ged-oracle-paperless-1).
 *
 * (a) regenere l'inventaire depuis les sources de reference et le compare au fichier versionne ;
 * (b) ROUGE si un element de l'inventaire n'a pas de statut, ou si une correspondance cite un id inconnu ;
 * (c) pour chaque « couvert », verifie que la route/l'ecran/le service GEDv2 existe ET que la preuve
 *     citee a reussi au dernier rejeu (var/phpunit.xml, rapport Playwright) ; sinon reclasse « non-prouve » ;
 * (d) ecrit recette/parite-paperless.json et docs/parite/PARITE-PAPERLESS.md.
 *
 * Code de sortie 0 seulement si l'inventaire est complet et rejoue a l'identique. Le pourcentage n'est
 * pas un critere de sortie : il est un resultat.
 *
 * Usage : php tools/parite/juge.php [--depot=C:\ged\ref\paperless-ngx]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ged\Parite\InterpreteurPython;
use Ged\Parite\Juge;

$racine = dirname(__DIR__, 2);
$depot = 'C:\\ged\\ref\\paperless-ngx';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--depot=')) {
        $depot = substr($argument, 8);
    }
}
$depotEnv = getenv('PAPERLESS_DEPOT');
if (is_string($depotEnv) && $depotEnv !== '') {
    $depot = $depotEnv;
}

$erreurs = [];

/** @return array<string,mixed> */
function chargerJson(string $chemin): array
{
    if (!is_file($chemin)) {
        return [];
    }
    $contenu = (string) file_get_contents($chemin);
    $donnees = json_decode($contenu, true);

    return is_array($donnees) ? $donnees : [];
}

// ---------------------------------------------------------------------------
// (a) Regeneration de l'inventaire et comparaison au fichier versionne.
// ---------------------------------------------------------------------------

$versionne = chargerJson($racine . '/docs/parite/inventaire-paperless.json');
$identique = false;
$detailIdentique = 'inventaire versionne absent';

if ($versionne === []) {
    $erreurs[] = 'docs/parite/inventaire-paperless.json absent ou illisible';
} else {
    $interpreteur = InterpreteurPython::resoudre();
    if ($interpreteur === []) {
        $erreurs[] = 'aucun interpreteur Python 3 : inventaire non regenerable (poser GED_PYTHON)';
        $detailIdentique = 'interpreteur Python 3 introuvable';
    } else {
        $sortie = $racine . '/var/parite/inventaire-rejoue.json';
        if (!is_dir(dirname($sortie))) {
            mkdir(dirname($sortie), 0777, true);
        }
        $morceaux = array_merge(
            $interpreteur,
            [$racine . '/tools/parite/extraire-paperless.py', '--depot', $depot, '--sortie', $sortie],
        );
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tuyaux = [];
        $processus = @proc_open($morceaux, $descripteurs, $tuyaux);
        if (!is_resource($processus)) {
            $erreurs[] = 'execution de l\'extracteur impossible';
            $detailIdentique = 'extracteur non executable';
        } else {
            $stdout = (string) stream_get_contents($tuyaux[1]);
            $stderr = (string) stream_get_contents($tuyaux[2]);
            fclose($tuyaux[1]);
            fclose($tuyaux[2]);
            $code = proc_close($processus);
            if ($code !== 0) {
                $erreurs[] = 'extracteur en echec (code ' . $code . ') : ' . trim($stderr . ' ' . $stdout);
                $detailIdentique = 'extraction en echec';
            } else {
                $rejoue = chargerJson($sortie);
                $identique =
                    isset($rejoue['elements'], $versionne['elements'])
                    && $rejoue['elements'] === $versionne['elements']
                    && ($rejoue['reference']['commit'] ?? '') === ($versionne['reference']['commit'] ?? '');
                $detailIdentique = $identique
                    ? 'inventaire rejoue a l\'identique (commit ' . (string) ($versionne['reference']['commit_court'] ?? '') . ')'
                    : 'inventaire rejoue DIFFERENT du fichier versionne';
                if (!$identique) {
                    $erreurs[] = 'l\'inventaire rejoue differe du fichier versionne (depot ' . $depot . ')';
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Correspondance et environnement GEDv2.
// ---------------------------------------------------------------------------

$correspondance = chargerJson($racine . '/docs/parite/correspondance.json');
$entrees = is_array($correspondance['elements'] ?? null) ? $correspondance['elements'] : [];

/** @var array{routes:list<string>, ecrans:list<string>, services:list<string>} $connu */
$connu = ['routes' => [], 'ecrans' => [], 'services' => []];

// Routes GEDv2 : tous les modules + Kernel.
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

// Ecrans : gabarits Twig, chemins relatifs a la racine du depot.
$gabarits = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/templates', FilesystemIterator::SKIP_DOTS));
foreach ($gabarits as $fichier) {
    if ($fichier instanceof SplFileInfo && $fichier->getExtension() === 'twig') {
        $connu['ecrans'][] = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
    }
}
$connu['ecrans'] = array_values(array_unique($connu['ecrans']));

// Services : classes citees par la correspondance et reellement chargeables.
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
// Preuves du dernier rejeu.
// ---------------------------------------------------------------------------

$preuvesReussies = [];
$preuvesEchouees = [];

// PHPUnit : var/phpunit.xml (JUnit).
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
            // Une preuve cite souvent le nom de methode sans le jeu de donnees.
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

// Playwright : rapport JSON du dernier rejeu, s'il existe.
$playwright = $racine . '/var/e2e-latest.json';
if (is_file($playwright)) {
    $rapport = chargerJson($playwright);
    $collecter = static function (array $suites, array $prefixe, callable $collecter) use (&$preuvesReussies, &$preuvesEchouees): void {
        foreach ($suites as $suite) {
            if (!is_array($suite)) {
                continue;
            }
            $titre = is_string($suite['title'] ?? null) ? $suite['title'] : '';
            $chemin = $prefixe;
            if ($titre !== '') {
                $chemin[] = $titre;
            }
            foreach (is_array($suite['specs'] ?? null) ? $suite['specs'] : [] as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $titreSpec = is_string($spec['title'] ?? null) ? $spec['title'] : '';
                $identifiant = implode(' > ', array_merge($chemin, [$titreSpec]));
                $statuts = [];
                foreach (is_array($spec['tests'] ?? null) ? $spec['tests'] : [] as $test) {
                    foreach (is_array($test['results'] ?? null) ? $test['results'] : [] as $resultat) {
                        $statuts[] = (string) ($resultat['status'] ?? '');
                    }
                }
                if ($statuts !== [] && in_array('failed', $statuts, true)) {
                    $preuvesEchouees[] = $identifiant;
                } elseif ($statuts !== [] && in_array('passed', $statuts, true)) {
                    $preuvesReussies[] = $identifiant;
                }
            }
            foreach (is_array($suite['suites'] ?? null) ? $suite['suites'] : [] as $sousSuite) {
                $collecter([$sousSuite], $chemin, $collecter);
            }
        }
    };
    $collecter(is_array($rapport['suites'] ?? null) ? $rapport['suites'] : [], [], $collecter);
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

// Phase 2 (parite d'effet) : mesure ecrite par tests/php/Parite/ComportementTest.php.
$comportement = chargerJson($racine . '/var/parite/comportement.json');

$resultat = [
    'version' => 1,
    'date' => (new DateTimeImmutable())->format('c'),
    'reference' => $versionne['reference'] ?? [],
    'depot' => $depot,
    'inventaire_identique' => $identique,
    'inventaire_detail' => $detailIdentique,
    'preuves_phpunit_reussies' => count($preuvesReussies),
    'preuves_phpunit_echouees' => count($preuvesEchouees),
    'total' => $rapport['total'],
    'global' => $rapport['global'],
    'categories' => $rapport['categories'],
    'comportement' => $comportement['categories'] ?? [],
    'erreurs' => $erreurs,
    'ecarts' => $rapport['ecarts'],
    'exclusions' => $rapport['exclusions'],
];

if (!is_dir($racine . '/recette')) {
    mkdir($racine . '/recette', 0777, true);
}
file_put_contents(
    $racine . '/recette/parite-paperless.json',
    json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
);

// ---------------------------------------------------------------------------
// Rapport lisible docs/parite/PARITE-PAPERLESS.md (deterministe : pas d'horodatage).
// ---------------------------------------------------------------------------

$lignes = [];
$lignes[] = '# Parite Paperless-ngx / GEDv2';
$lignes[] = '';
$lignes[] = 'Mesure mecanique du lot `ged-oracle-paperless-1`, calculee depuis les sources de Paperless-ngx '
    . (string) ($versionne['reference']['tag'] ?? '') . ' (commit ' . (string) ($versionne['reference']['commit_court'] ?? '') . ').';
$lignes[] = '';
$lignes[] = 'Un element n\'est couvert que si l\'equivalent GEDv2 existe et si la preuve citee a reussi au dernier rejeu. '
    . 'Une exclusion n\'est jamais comptee comme couverte.';
$lignes[] = '';
$lignes[] = 'Inventaire : ' . ($identique ? 'rejoue a l\'identique' : '**DIFFERENT du fichier versionne**')
    . ' — ' . (string) $detailIdentique . '.';
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
$lignes[] = '### Par categorie';
$lignes[] = '';
$lignes[] = '| Categorie | Total | Couverts | Absents | Non prouves | Exclus | Stricte | Hors exclusions |';
$lignes[] = '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |';
foreach ($rapport['categories'] as $categorie => $valeurs) {
    $lignes[] = sprintf(
        '| %s | %d | %d | %d | %d | %d | %s %% | %s %% |',
        (string) $categorie,
        (int) $valeurs['total'],
        (int) $valeurs['couverts'],
        (int) $valeurs['absents'],
        (int) $valeurs['non_prouves'],
        (int) $valeurs['exclus'],
        number_format((float) $valeurs['strict_pct'], 2, ',', ' '),
        number_format((float) $valeurs['hors_exclusions_pct'], 2, ',', ' '),
    );
}
$lignes[] = '';

// Phase 2 : parite d'effet (vecteurs extraits des tests Paperless, rejoues cote GEDv2).
$lignes[] = '## Comportement (phase 2)';
$lignes[] = '';
if ($comportement === [] || !isset($comportement['categories'])) {
    $lignes[] = 'Aucune mesure de comportement disponible (lancer tests/php/Parite/ComportementTest.php).';
} else {
    $lignes[] = 'Vecteurs {entree, sortie} extraits mecaniquement des tests Paperless, rejoues par l\'equivalent GEDv2. '
        . 'Un ecart de comportement est mesure, jamais corrige dans ce lot.';
    $lignes[] = '';
    $lignes[] = '| Categorie Paperless | Reference vecteurs | Passes | Total | Taux |';
    $lignes[] = '| --- | --- | ---: | ---: | ---: |';
    foreach ((array) $comportement['categories'] as $nom => $mesure) {
        if (!is_array($mesure)) {
            continue;
        }
        $total = (int) ($mesure['total'] ?? 0);
        $passes = (int) ($mesure['passes'] ?? 0);
        $taux = $total > 0 ? round($passes / $total * 100, 2) : 0.0;
        $lignes[] = sprintf(
            '| %s | src/documents/tests/%s | %d | %d | %s %% |',
            (string) $nom,
            $nom === 'dates' ? 'test_date_parsing.py' : ($nom === 'correspondance' ? 'test_matchables.py' : '-'),
            $passes,
            $total,
            number_format($taux, 2, ',', ' '),
        );
        if (isset($mesure['par_algorithme']) && is_array($mesure['par_algorithme'])) {
            foreach ($mesure['par_algorithme'] as $algorithme => $compte) {
                if (!is_array($compte)) {
                    continue;
                }
                $lignes[] = sprintf(
                    '| — %s | — | %d | %d | %s %% |',
                    (string) $algorithme,
                    (int) ($compte['passes'] ?? 0),
                    (int) ($compte['total'] ?? 0),
                    (int) ($compte['total'] ?? 0) > 0
                        ? number_format(round((int) ($compte['passes'] ?? 0) / (int) $compte['total'] * 100, 2), 2, ',', ' ')
                        : '0,00',
                );
            }
        }
    }
}
$lignes[] = '';

// Phase 3 : parite d'effet de la chaine de consommation (vecteurs extraits des tests Paperless, rejoues
// cote GEDv2 par tests/php/Parite/Chaine*Test.php). Additif : ne change pas le verdict de la couche.
$lignes[] = '## Chaine de consommation (phase 3)';
$lignes[] = '';
$chaine = [];
foreach (glob($racine . '/var/parite/chaine-*.json') ?: [] as $fichierChaine) {
    $mesure = chargerJson($fichierChaine);
    if ($mesure !== [] && isset($mesure['categorie'])) {
        $chaine[(string) $mesure['categorie']] = $mesure;
    }
}
if ($chaine === []) {
    $lignes[] = 'Aucune mesure de chaine disponible (lancer tests/php/Parite/ChaineRegistreTest.php, ChaineOcrTest, ChaineCodeBarresTest, ChaineGabaritTest).';
} else {
    $lignes[] = 'Vecteurs {entree, sortie} extraits des tests Paperless (registre des parseurs, post-traitement OCR,';
    $lignes[] = 'codes-barres, gabarit de nom de fichier), rejoues par l\'equivalent GEDv2. Un ecart de comportement';
    $lignes[] = 'reste un ecart ; le test de rejeu echoue si un vecteur s\'ecarte.';
    $lignes[] = '';
    $lignes[] = '| Famille | Fichier de vecteurs | Passes | Total | Taux |';
    $lignes[] = '| --- | --- | ---: | ---: | ---: |';
    ksort($chaine);
    foreach ($chaine as $nomChaine => $mesureChaine) {
        $totalChaine = (int) ($mesureChaine['total'] ?? 0);
        $passesChaine = (int) ($mesureChaine['passes'] ?? 0);
        $lignes[] = sprintf(
            '| %s | tests/parite/vecteurs/chaine-%s.json | %d | %d | %s %% |',
            (string) $nomChaine,
            (string) $nomChaine,
            $passesChaine,
            $totalChaine,
            number_format($totalChaine > 0 ? round($passesChaine / $totalChaine * 100, 2) : 0.0, 2, ',', ' '),
        );
    }
}
$lignes[] = '';

// Phase 4 : parite d'effet de l'API (vecteurs extraits des tests Paperless, rejoues cote GEDv2 par
// tests/php/Parite/Api*Test.php). Additif : ne change pas le verdict de la couche.
$lignes[] = '## API — filtres de liste (phase 4)';
$lignes[] = '';
$api = [];
foreach (glob($racine . '/var/parite/api-*.json') ?: [] as $fichierApi) {
    $mesure = chargerJson($fichierApi);
    if ($mesure !== [] && isset($mesure['categorie'])) {
        $api[(string) $mesure['categorie']] = $mesure;
    }
}
if ($api === []) {
    $lignes[] = 'Aucune mesure d\'API disponible (lancer tests/php/Parite/ApiFiltresDocumentsTest.php).';
} else {
    $lignes[] = 'Vecteurs {corpus, requete, documents retenus} extraits des tests Paperless (filtres de liste),';
    $lignes[] = 'rejoues contre la vraie couche de requetes de GEDv2 sur gedv2_test. Le test de rejeu echoue si un';
    $lignes[] = 'vecteur s\'ecarte ; les vecteurs propres a Django/DRF sont listes `non_rejouables` avec leur raison.';
    $lignes[] = '';
    $lignes[] = '| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |';
    $lignes[] = '| --- | --- | ---: | ---: | ---: | ---: |';
    ksort($api);
    foreach ($api as $nomApi => $mesureApi) {
        $totalApi = (int) ($mesureApi['total'] ?? 0);
        $passesApi = (int) ($mesureApi['passes'] ?? 0);
        $lignes[] = sprintf(
            '| %s | tests/parite/vecteurs/api-%s.json | %d | %d | %d | %s %% |',
            (string) $nomApi,
            (string) $nomApi,
            $passesApi,
            $totalApi,
            (int) ($mesureApi['non_rejouables'] ?? 0),
            number_format($totalApi > 0 ? round($passesApi / $totalApi * 100, 2) : 0.0, 2, ',', ' '),
        );
    }
}
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
    $lignes[] = '| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |';
    $lignes[] = '| --- | --- | --- | --- | --- | --- |';
    foreach ((array) $index as $id => $element) {
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
            $preuve .= ' (couche 1)';
        } else {
            $preuve = '-';
        }
        $raison = (string) ($ecartParId[$id]['raison'] ?? '');
        if ($statut === 'exclu') {
            $raison = 'exclu : ' . $raison;
        }
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

$lignes[] = '## Carnet de commandes (ecarts)';
$lignes[] = '';
$lignes[] = 'Chaque ecart (absent, non prouve, exclu) est listé ci-dessous avec sa reference Paperless.';
$lignes[] = '';
$lignes[] = '| Id | Categorie | Reference Paperless | Statut | Raison | Lot |';
$lignes[] = '| --- | --- | --- | --- | --- | --- |';
foreach ($rapport['ecarts'] as $ecart) {
    $lignes[] = sprintf(
        '| %s | %s | %s | %s | %s | %s |',
        str_replace('|', '\\|', (string) $ecart['id']),
        (string) $ecart['categorie'],
        (string) $ecart['reference'],
        (string) $ecart['statut'],
        str_replace('|', '\\|', (string) $ecart['raison']),
        (string) $ecart['lot'],
    );
}
$lignes[] = '';

file_put_contents($racine . '/docs/parite/PARITE-PAPERLESS.md', implode("\n", $lignes) . "\n");

// ---------------------------------------------------------------------------
// Verdict.
// ---------------------------------------------------------------------------

$ok = $identique && $erreurs === [];
printf(
    "parite : %d elements | couverts %d | strict %s %% | hors exclusions %s %% | ecarts %d | %s\n",
    (int) $global['total'],
    (int) $global['couverts'],
    number_format((float) $global['strict_pct'], 2, ',', ' '),
    number_format((float) $global['hors_exclusions_pct'], 2, ',', ' '),
    count($rapport['ecarts']),
    $ok ? 'VERT' : 'ROUGE',
);
foreach ($erreurs as $erreur) {
    fwrite(STDERR, 'ERREUR parite : ' . $erreur . "\n");
}

exit($ok ? 0 : 1);
