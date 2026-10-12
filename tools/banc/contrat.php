<?php

declare(strict_types=1);

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Securite\DroitsRoutes;
use Ged\Securite\RegistreSessions;

const RACINE_CONTRAT = __DIR__ . '/../..';

require RACINE_CONTRAT . '/vendor/autoload.php';
chdir(RACINE_CONTRAT);
putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

/** @return array{code:int,body:string} */
function appel(string $base, string $methode, string $chemin, array $entetes = [], array|string|null $corps = null): array
{
    $curl = curl_init($base . $chemin);
    if ($curl === false) throw new RuntimeException('contrat HTTP : curl indisponible');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_TIMEOUT => 5, CURLOPT_FORBID_REUSE => true,
        CURLOPT_HTTPHEADER => [...$entetes, 'Connection: close']]);
    if ($corps !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $corps);
    $brut = curl_exec($curl);
    if ($brut === false) {
        $erreur = curl_error($curl); curl_close($curl);
        throw new RuntimeException("contrat HTTP $methode $chemin : $erreur");
    }
    $taille = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['code' => $code, 'body' => substr($brut, $taille)];
}

/** @return resource */
function demarrer(string $dossier, int $port): mixed
{
    $env = getenv();
    if (!is_array($env)) $env = [];
    $env['APP_ENV'] = 'test'; $env['DB_NAME'] = 'gedv2_test';
    // Les routes de mutation sont aussi sondées ici : leurs tâches éventuelles restent dans le bac HTTP
    // éphémère, jamais dans le corpus versionné d'aperçu.
    $env['STORAGE_ROOT'] = $dossier . '/documents';
    mkdir($env['STORAGE_ROOT'], 0700, true);
    $env['SECURITE_DOSSIER'] = $dossier . '/securite';
    $env['INGESTION_TRAVAIL'] = $dossier . '/ingestion';
    $proc = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-S', "127.0.0.1:$port", '-t', RACINE_CONTRAT . '/public', RACINE_CONTRAT . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $dossier . '/serveur.log', 'a'], 2 => ['file', $dossier . '/serveur-erreurs.log', 'a']], $pipes, RACINE_CONTRAT, $env);
    if (!is_resource($proc)) throw new RuntimeException('contrat HTTP : serveur de test impossible à démarrer');
    fclose($pipes[0]);
    return $proc;
}

function portLibre(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $cause);
    if ($socket === false) throw new RuntimeException("contrat HTTP : port indisponible ($cause)");
    $nom = stream_socket_get_name($socket, false); fclose($socket);
    return (int) substr((string) $nom, strrpos((string) $nom, ':') + 1);
}

function supprimer(string $dossier): void
{
    if (!is_dir($dossier)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dossier);
}

/** @param array<string,mixed> $json */
function verifierSchemaJson(array $json, array $cles, string $libelle, array &$defauts): void
{
    foreach ($cles as $cle) if (!array_key_exists($cle, $json)) $defauts[] = "$libelle : clé JSON absente ($cle)";
}

$defauts = [];
$codeTests = 1;
$sortieTests = [];
exec('"' . PHP_BINARY . '" vendor/bin/phpunit tests/contrats 2>&1', $sortieTests, $codeTests);
if ($codeTests !== 0) $defauts[] = 'contrats de service : ' . implode("\n", $sortieTests);

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
$dossier = sys_get_temp_dir() . '/gedv2-contrat-http-' . bin2hex(random_bytes(5));
$processus = null;
try {
    if ($verrou === false || !flock($verrou, LOCK_EX)) throw new RuntimeException('verrou gedv2_test impossible à prendre');
    mkdir($dossier, 0700, true);
    $config = new Config(RACINE_CONTRAT);
    if ($config->get('DB_NAME') !== 'gedv2_test') throw new RuntimeException('base de test non imposée');
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaIngestion::installer($pdo); SchemaJonctions::installer($pdo);
    $port = portLibre(); $base = "http://127.0.0.1:$port";
    $processus = demarrer($dossier, $port);
    for ($i = 0; $i < 50; ++$i) {
        try { if (appel($base, 'GET', '/sante')['code'] === 200) break; } catch (Throwable) {}
        usleep(100000);
    }
    if ($i === 50) throw new RuntimeException('serveur de test non joignable');

    $sessions = new RegistreSessions($dossier . '/securite/sessions');
    foreach (DroitsRoutes::declarees() as $definition => $permission) {
        [$methode, $chemin] = explode(' ', $definition, 2);
        $chemin = str_replace(['/{id:[0-9]+}', '/{nom}', '/{jeton:[A-Za-z0-9_-]+}'], ['/999999999', '/contrat_synthetique', '/jeton_faux_contrat_0000000000000000'], $chemin);
        $chemin = str_replace('/{numero:[0-9]+}', '/1', $chemin);
        $mutation = $methode === 'POST';
        $corps = $mutation ? http_build_query(['nom' => '', 'action_type' => '', 'document_id' => 0, 'actif' => 0, 'vers' => 'invalide']) : null;
        $entetes = $mutation ? ['Content-Type: application/x-www-form-urlencoded'] : [];
        if (DroitsRoutes::estPublique($permission)) {
            $rep = appel($base, $methode, $chemin, $entetes, $corps);
            $attendu = match ($definition) {
                'GET /sante', 'GET /connexion' => 200,
                'POST /connexion' => 403,
                'POST /api/v1/depots', 'GET /api/v1/depots/{id:[0-9]+}' => 401,
                // Jonctions V2-08b : sans jeton, l'appairage et les destinations refusent (le code
                // d'appairage manquant est un 400 nommé, un jeton absent un 401).
                'POST /api/v1/appairage/jeton' => 400,
                'GET /api/v1/appairage/moi', 'DELETE /api/v1/appairage/moi', 'GET /api/v1/dossiers' => 401,
                // API REST des documents (lot ged-api-documents) : sans jeton, tout refuse 401 nommé.
                'GET /api/v1/documents', 'POST /api/v1/documents',
                'GET /api/v1/documents/{id:[0-9]+}', 'POST /api/v1/documents/{id:[0-9]+}',
                'GET /api/v1/documents/{id:[0-9]+}/fichier',
                'GET /api/v1/documents/{id:[0-9]+}/versions/{numero:[0-9]+}/fichier',
                'GET /api/v1/types', 'GET /api/v1/etiquettes', 'GET /api/v1/emetteurs', 'GET /api/v1/vues' => 401,
                // Lot ged-parite-api-2 : mêmes routes sous jeton (ligne hors secteur Harness, signalée).
                'GET /api/v1/champs', 'GET /api/v1/partages' => 401,
                // V2-12d : lien externe sans jeton valide, refus nommé.
                'GET /partages/{jeton:[A-Za-z0-9_-]+}', 'GET /partages/{jeton:[A-Za-z0-9_-]+}/fichier' => 403,
                // Édition depuis le poste (F13) : sans jeton d'appareil, toute l'API refuse 401 nommé.
                'GET /api/v1/edition/documents/{id:[0-9]+}',
                'GET /api/v1/edition/documents/{id:[0-9]+}/fichier',
                'POST /api/v1/edition/documents/{id:[0-9]+}/versions',
                'POST /api/v1/edition/documents/{id:[0-9]+}/ouverture',
                'POST /api/v1/edition/documents/{id:[0-9]+}/signal',
                'POST /api/v1/edition/documents/{id:[0-9]+}/fermeture',
                'POST /api/v1/edition/documents/{id:[0-9]+}/secours',
                'GET /api/v1/edition/documents/{id:[0-9]+}/secours' => 401,
                default => 0,
            };
            if ($rep['code'] !== $attendu) $defauts[] = "$definition : statut attendu $attendu, reçu {$rep['code']}";
            if ($rep['code'] >= 400 && !str_starts_with($permission, 'publique:api-')
                && (!str_contains($rep['body'], 'cause') || !str_contains($rep['body'], 'etape'))) $defauts[] = "$definition : refus non nommé";
            if (str_starts_with($permission, 'publique:api-')) {
                $json = json_decode($rep['body'], true);
                if (!is_array($json)) $defauts[] = "$definition : refus API non JSON";
                else {
                    verifierSchemaJson($json, ['erreur'], "$definition (erreur)", $defauts);
                    if (is_array($json['erreur'] ?? null)) verifierSchemaJson($json['erreur'], ['etape', 'cause', 'reference'], "$definition (erreur)", $defauts);
                }
            }
            continue;
        }
        $session = $sessions->ouvrir('contrat-http@gedv2.test', [DroitsRoutes::permissionCanonique($permission)]);
        $sessionEntetes = [...$entetes, 'Cookie: gedv2_session=' . $session['id']];
        if ($mutation) $sessionEntetes[] = 'X-CSRF-Token: ' . $session['csrf'];
        $rep = appel($base, $methode, $chemin, $sessionEntetes, $corps);
        if ($rep['code'] >= 500) $defauts[] = "$definition : 500 avec compte synthétique";
        if ($definition === 'POST /deconnexion' && $rep['code'] !== 303) $defauts[] = "$definition : statut attendu 303, reçu {$rep['code']}";
        if ($rep['code'] === 403 || $rep['code'] === 404) {
            if (!str_contains($rep['body'], 'cause') || !str_contains($rep['body'], 'etape')) $defauts[] = "$definition : refus non nommé";
        }
    }

    $jeton = (new Jetons($pdo))->creer('contrat-http-' . bin2hex(random_bytes(4)));
    $fichier = $dossier . '/piece-contrat.pdf'; file_put_contents($fichier, '%PDF-1.4 contrat HTTP synthétique');
    $depot = appel($base, 'POST', '/api/v1/depots', ['Authorization: Bearer ' . $jeton], ['fichier' => new CURLFile($fichier, 'application/pdf', 'piece-contrat.pdf')]);
    if ($depot['code'] !== 202) $defauts[] = 'POST /api/v1/depots : statut attendu 202, reçu ' . $depot['code'];
    $resume = json_decode($depot['body'], true);
    if (!is_array($resume)) $defauts[] = 'POST /api/v1/depots : réponse 202 non JSON';
    else {
        verifierSchemaJson($resume, ['travail', 'etat', 'suivi'], 'POST /api/v1/depots', $defauts);
        if (isset($resume['travail'])) {
            $suivi = appel($base, 'GET', '/api/v1/depots/' . (int) $resume['travail'], ['Authorization: Bearer ' . $jeton]);
            if ($suivi['code'] !== 200) $defauts[] = 'GET /api/v1/depots/{id} : statut attendu 200, reçu ' . $suivi['code'];
            $detail = json_decode($suivi['body'], true);
            if (!is_array($detail)) $defauts[] = 'GET /api/v1/depots/{id} : réponse 200 non JSON';
            else verifierSchemaJson($detail, ['travail', 'source', 'nom_origine', 'etat', 'essais', 'erreur', 'document_id', 'cree_le', 'maj_le', 'application', 'metadonnees', 'suivi'], 'GET /api/v1/depots/{id}', $defauts);
        }
    }
    $yaml = file_get_contents(RACINE_CONTRAT . '/docs/api/depots.yaml');
    foreach (['TravailResume', 'TravailDetail', 'Erreur', "'202'", "'401'", "'404'"] as $preuve) if (!is_string($yaml) || !str_contains($yaml, $preuve)) $defauts[] = "docs/api/depots.yaml : contrat absent ($preuve)";
} catch (Throwable $e) {
    $defauts[] = $e->getMessage();
} finally {
    if (is_resource($processus)) { proc_terminate($processus); proc_close($processus); }
    supprimer($dossier);
    if (is_resource($verrou)) { flock($verrou, LOCK_UN); fclose($verrou); }
}

if ($defauts !== []) {
    foreach ($defauts as $defaut) fwrite(STDERR, "ECHEC: $defaut\n");
    exit(1);
}
echo "OK: contrats de service et HTTP — toutes les routes déclarées sont couvertes\n";
