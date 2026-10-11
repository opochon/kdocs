<?php

declare(strict_types=1);

use Ged\Securite\DroitsRoutes;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\RegistreSessions;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Jonctions\Jetons;
use Ged\Jonctions\Schema as SchemaJonctions;
use Ged\Base;
use Ged\Config;

/**
 * Sonde offensive V2-07c.
 *
 * Elle démarre un serveur HTTP isolé sur gedv2_test et n'écrit que des sessions éphémères
 * dans var/. Aucune donnée métier ni aucun schéma ne sont modifiés. Un défaut fait sortir 1.
 */
const RACINE_SONDE = __DIR__ . '/../..';

require RACINE_SONDE . '/vendor/autoload.php';
putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

/** @return array{code:int,headers:array<string,list<string>>,body:string} */
function requete(string $base, string $methode, string $chemin, array $entetes = [], ?string $corps = null): array
{
    $curl = curl_init($base . $chemin);
    if ($curl === false) {
        throw new RuntimeException('Sonde : initialisation HTTP impossible.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
        CURLOPT_CUSTOMREQUEST => $methode,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_HTTPHEADER => [...$entetes, 'Connection: close'],
    ]);
    if ($corps !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $corps);
    }
    $brut = curl_exec($curl);
    if ($brut === false) {
        $erreur = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException(sprintf('Sonde HTTP %s %s : %s', $methode, $chemin, $erreur));
    }
    $tailleEntetes = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    $bloc = substr($brut, 0, $tailleEntetes);
    $lignes = preg_split('/\r?\n/', trim($bloc)) ?: [];
    $headers = [];
    foreach ($lignes as $ligne) {
        if (!str_contains($ligne, ':')) {
            continue;
        }
        [$nom, $valeur] = explode(':', $ligne, 2);
        $headers[strtolower(trim($nom))][] = trim($valeur);
    }

    return ['code' => $code, 'headers' => $headers, 'body' => substr($brut, $tailleEntetes)];
}

/** @param array<string,list<string>> $headers */
function cookie(array $headers, string $nom): ?string
{
    foreach ($headers['set-cookie'] ?? [] as $ligne) {
        if (preg_match('/^' . preg_quote($nom, '/') . '=([^;]*)/i', $ligne, $trouve) === 1) {
            return $trouve[1];
        }
    }

    return null;
}

/** @param array<string,list<string>> $headers */
function enteteCookie(array $headers, string $nom): string
{
    foreach ($headers['set-cookie'] ?? [] as $ligne) {
        if (str_starts_with(strtolower($ligne), strtolower($nom) . '=')) {
            return $ligne;
        }
    }

    return '';
}

/** @return resource */
function demarrerServeur(string $dossier, int $port): mixed
{
    $environnement = getenv();
    if (!is_array($environnement)) {
        $environnement = [];
    }
    $environnement['APP_ENV'] = 'test';
    $environnement['DB_NAME'] = 'gedv2_test';
    // Toute mutation de sonde (notamment la tâche Archives) va dans son bac éphémère, jamais dans le
    // corpus versionné d'aperçu. Le dossier est retiré avec le reste de la sonde en fin de passe.
    $environnement['STORAGE_ROOT'] = $dossier . '/documents';
    mkdir($environnement['STORAGE_ROOT'], 0700, true);
    $environnement['SECURITE_DOSSIER'] = $dossier . '/securite';
    // Tableau de commande : proc_terminate vise bien le serveur PHP, pas un shell parent sous Windows.
    $commande = [PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port,
        '-t', RACINE_SONDE . '/public', RACINE_SONDE . '/public/index.php'];
    $processus = proc_open($commande, [
        0 => ['pipe', 'r'],
        1 => ['file', $dossier . '/serveur.log', 'a'],
        2 => ['file', $dossier . '/serveur-erreurs.log', 'a'],
    ], $tuyaux, RACINE_SONDE, $environnement);
    if (!is_resource($processus)) {
        throw new RuntimeException('Sonde : démarrage du serveur de test impossible.');
    }
    fclose($tuyaux[0]);

    return $processus;
}

function portLibre(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $cause);
    if ($socket === false) {
        throw new RuntimeException('Sonde : port de test indisponible : ' . $cause);
    }
    $nom = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) $nom, strrpos((string) $nom, ':') + 1);
    if ($port < 1) {
        throw new RuntimeException('Sonde : port de test invalide.');
    }

    return $port;
}

function supprimerDossier(string $dossier): void
{
    if (!is_dir($dossier)) return;
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterateur as $element) {
        $element->isDir() ? rmdir($element->getPathname()) : unlink($element->getPathname());
    }
    rmdir($dossier);
}

$defauts = [];
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "ECHEC: verrou gedv2_test indisponible\n");
    exit(1);
}
$dossier = RACINE_SONDE . '/var/sonde-securite-' . bin2hex(random_bytes(6));
mkdir($dossier, 0700, true);
$processus = null;
$pdo = null;
$compteUtilisateur = null;

try {
    $port = portLibre();
    $base = 'http://127.0.0.1:' . $port;
    $processus = demarrerServeur($dossier, $port);
    for ($essai = 0; $essai < 50; ++$essai) {
        try {
            if (requete($base, 'GET', '/sante')['code'] === 200) {
                break;
            }
        } catch (RuntimeException) {
            // Le serveur PHP est encore en amorçage.
        }
        usleep(100000);
    }
    if ($essai === 50) {
        throw new RuntimeException('Sonde : le serveur de test ne répond pas.');
    }
    $verifier = static function (bool $condition, string $libelle) use (&$defauts): void {
        if (!$condition) {
            $defauts[] = $libelle;
        }
    };
    $entetesSurs = static function (array $reponse) use ($verifier): void {
        $headers = $reponse['headers'];
        $verifier(($headers['x-content-type-options'][0] ?? '') === 'nosniff', 'en-tête X-Content-Type-Options absent');
        $verifier(($headers['x-frame-options'][0] ?? '') === 'DENY', 'en-tête X-Frame-Options absent');
        $verifier(str_contains($headers['content-security-policy'][0] ?? '', "frame-ancestors 'none'"), 'CSP frame-ancestors absente');
        $verifier(($headers['referrer-policy'][0] ?? '') === 'strict-origin-when-cross-origin', 'Referrer-Policy absente');
    };

    $connexion = requete($base, 'GET', '/connexion');
    $entetesSurs($connexion);
    $cookieCsrf = enteteCookie($connexion['headers'], 'gedv2_csrf');
    $verifier(str_contains($cookieCsrf, 'HttpOnly') && str_contains($cookieCsrf, 'SameSite=Lax'), 'cookie CSRF insuffisamment protégé');
    preg_match('/name="csrf" value="([^"]+)"/', $connexion['body'], $trouveCsrf);
    $csrfConnexion = $trouveCsrf[1] ?? '';
    $verifier($csrfConnexion !== '', 'jeton CSRF de connexion absent');

    $secure = requete($base, 'GET', '/connexion', ['X-Forwarded-Proto: https']);
    $verifier(str_contains(enteteCookie($secure['headers'], 'gedv2_csrf'), 'Secure'), 'cookie CSRF sans Secure derrière HTTPS');

    // Lien externe V2-12d : un jeton faux est refusé sur les deux routes publiques, et un jeton n'ouvre
    // jamais une autre page (paramètre ou cookie sur une fiche sans session).
    foreach (['/partages/jeton_faux_sonde_0000000000000000', '/partages/jeton_faux_sonde_0000000000000000/fichier'] as $cheminPartage) {
        $verifier(requete($base, 'GET', $cheminPartage)['code'] === 403, "$cheminPartage : jeton faux accepté");
    }
    $verifier(requete($base, 'GET', '/documents/204?jeton=jeton_faux_sonde_0000000000000000')['code'] === 403, 'jeton de partage accepté hors de sa route');

    // Toute route déclarée est appelée sans session, puis avec un compte sans permission.
    $sessions = new RegistreSessions($dossier . '/securite/sessions');
    $sansDroit = $sessions->ouvrir('sonde-sans-droit@gedv2.test', []);
    $autreDossier = $sessions->ouvrir('sonde-autre-dossier@gedv2.test', [
        'documents:lire', 'documents:traiter', 'recherche:lire', 'workflows:lire', 'application:administrer',
    ], null, 999999);
    foreach (DroitsRoutes::declarees() as $definition => $permission) {
        [$methode, $chemin] = explode(' ', $definition, 2);
        $chemin = str_replace('/{id:[0-9]+}', '/204', $chemin);
        $chemin = str_replace('/{numero:[0-9]+}', '/1', $chemin);
        $chemin = str_replace('/{jeton:[A-Za-z0-9_-]+}', '/jeton_faux_sonde_0000000000000000', $chemin);
        $chemin = str_replace('/{nom}', '/sonde_inconnue', $chemin);
        $publique = DroitsRoutes::estPublique($permission);
        $corps = in_array($methode, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'sonde=1' : null;
        $entetesMutation = $corps === null ? [] : ['Content-Type: application/x-www-form-urlencoded'];
        $vide = requete($base, $methode, $chemin, $entetesMutation, $corps);
        $verifier($publique ? $vide['code'] < 500 : $vide['code'] === 403, "$definition accessible sans session");
        $aucun = requete($base, $methode, $chemin, [...$entetesMutation, 'Cookie: gedv2_session=' . $sansDroit['id']], $corps);
        $verifier($publique ? $aucun['code'] < 500 : $aucun['code'] === 403, "$definition accessible sans droit");
        $autre = requete($base, $methode, $chemin, [...$entetesMutation, 'Cookie: gedv2_session=' . $autreDossier['id']], $corps);
        if (str_starts_with($chemin, '/documents/')) {
            $verifier($autre['code'] === 403, "$definition accessible depuis un autre dossier");
        } else {
            $verifier($autre['code'] < 500, "$definition échoue avec une session d’un autre dossier");
        }
    }

    // B7 : un véritable compte en base, avec un mot de passe haché et le rôle Utilisateur, ne peut
    // atteindre aucun geste porté seulement par Manager ou Administrateur. La garde doit refuser avant
    // le métier : des identifiants volontairement inexistants ne peuvent donc rien modifier.
    $config = new Config(RACINE_SONDE);
    if ($config->get('DB_NAME') !== 'gedv2_test') {
        throw new RuntimeException('la sonde sécurité n’écrit que sur gedv2_test');
    }
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    SchemaJonctions::installer($pdo);
    // Marqueur réservé aux données éphémères de sonde, supprimées dans le finally sous ce même verrou.
    $identifiantUtilisateur = 'sonde-utilisateur-' . bin2hex(random_bytes(5)) . '@gedv2.test';
    $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
        ->execute([$identifiantUtilisateur, password_hash('Sonde-V213-2026!', PASSWORD_DEFAULT), 'utilisateur']);
    $compteUtilisateur = (int) $pdo->lastInsertId();
    $droitsUtilisateur = (new DroitsDossiers($pdo))->permissionsPourCompte($compteUtilisateur);
    $sessionUtilisateur = $sessions->ouvrir($identifiantUtilisateur, $droitsUtilisateur, null, $compteUtilisateur);
    foreach (DroitsRoutes::declarees() as $definition => $permission) {
        $permissionCanonique = DroitsRoutes::permissionCanonique($permission);
        if (DroitsRoutes::estPublique($permission) || $permissionCanonique === 'session:active'
            || in_array($permissionCanonique, $droitsUtilisateur, true)) {
            continue;
        }
        [$methode, $chemin] = explode(' ', $definition, 2);
        $chemin = str_replace('/{id:[0-9]+}', '/999999999', $chemin);
        $chemin = str_replace('/{numero:[0-9]+}', '/1', $chemin);
        $chemin = str_replace('/{jeton:[A-Za-z0-9_-]+}', '/jeton_faux_sonde_0000000000000000', $chemin);
        $chemin = str_replace('/{nom}', '/sonde_inconnue', $chemin);
        $mutation = in_array($methode, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $entetes = ['Cookie: gedv2_session=' . $sessionUtilisateur['id']];
        if ($mutation) {
            $entetes[] = 'Content-Type: application/x-www-form-urlencoded';
            $entetes[] = 'X-CSRF-Token: ' . $sessionUtilisateur['csrf'];
        }
        $refus = requete($base, $methode, $chemin, $entetes, $mutation ? 'sonde=1' : null);
        $verifier($refus['code'] === 403, "B7 utilisateur réel accepté sur $definition");
    }

    // Lot ged-api-documents : la porte des routes documents est un jeton LIÉ À UN COMPTE, de portée
    // documents_lecture / documents_ecriture. Sans jeton : 401 nommé ; jeton d'appairage (portée depot) : 403.
    foreach ([['GET', '/api/v1/documents'], ['GET', '/api/v1/types'], ['GET', '/api/v1/documents/204'],
        ['GET', '/api/v1/documents/204/fichier'], ['GET', '/api/v1/documents/204/versions/1/fichier'],
        ['POST', '/api/v1/documents'], ['POST', '/api/v1/documents/204']] as [$methodeDocuments, $cheminDocuments]) {
        $entetesDocuments = $methodeDocuments === 'POST' ? ['Content-Type: application/x-www-form-urlencoded'] : [];
        $sansJeton = requete($base, $methodeDocuments, $cheminDocuments, $entetesDocuments, $methodeDocuments === 'POST' ? 'sonde=1' : null);
        $verifier($sansJeton['code'] === 401, "$methodeDocuments $cheminDocuments accessible sans jeton de documents");
    }
    $jetonSondeDepot = (new Jetons($pdo))->creerAppairage('sonde-documents', 'poste-sonde', $compteUtilisateur, $identifiantUtilisateur);
    $mauvaisePortee = requete($base, 'GET', '/api/v1/documents', ['Authorization: Bearer ' . $jetonSondeDepot]);
    $verifier($mauvaisePortee['code'] === 403, 'un jeton de portée depot lit les documents');
    $ecritureSonde = requete($base, 'POST', '/api/v1/documents/204', ['Authorization: Bearer ' . $jetonSondeDepot, 'Content-Type: application/x-www-form-urlencoded'], 'titre=sonde');
    $verifier($ecritureSonde['code'] === 403, 'un jeton de portée depot modifie un document');

    // Injections SQL/XSS : elles ne doivent ni exécuter une requête ni ressortir dans le HTML brut.
    $legitime = $sessions->ouvrir('sonde-lecture@gedv2.test', ['documents:lire', 'recherche:lire']);
    $cookiesLecture = 'Cookie: gedv2_session=' . $legitime['id'];
    foreach ([
        http_build_query(['q' => "' OR 1=1 -- "]),
        http_build_query(['q' => '<script>window.__sonde_xss=1</script>']),
        http_build_query(['type' => "' UNION SELECT 1 -- ", 'emetteur' => '<img src=x onerror=alert(1)>', 'montant_min' => '0 OR 1=1']),
    ] as $query) {
        $reponse = requete($base, 'GET', '/recherche?' . $query, [$cookiesLecture]);
        $verifier($reponse['code'] < 500, 'injection recherche provoque une erreur serveur');
        $verifier(!str_contains($reponse['body'], '<script>window.__sonde_xss=1</script>'), 'XSS reflétée dans la recherche');
        $verifier(!str_contains(strtolower($reponse['body']), 'sql syntax'), 'injection SQL révélée par la recherche');
    }
    $formulaireInjecte = requete($base, 'POST', '/connexion', [
        'Content-Type: application/x-www-form-urlencoded', 'Cookie: gedv2_csrf=' . $csrfConnexion,
    ], http_build_query(['csrf' => $csrfConnexion, 'identifiant' => '<script>alert(1)</script>', 'mot_de_passe' => "' OR 1=1 --"]));
    $verifier($formulaireInjecte['code'] === 403 && !str_contains($formulaireInjecte['body'], '<script>alert(1)</script>'), 'injection dans le formulaire de connexion');
    preg_match('/name="csrf" value="([^"]+)"/', $formulaireInjecte['body'], $trouveCsrfAuthentification);
    $csrfAuthentification = $trouveCsrfAuthentification[1] ?? '';
    $verifier($csrfAuthentification !== '', 'jeton CSRF renouvelé absent après refus de connexion');

    // Traversée, chemin absolu et URI de fichier : aucun endpoint de contenu ne doit les accepter.
    foreach (['../204/apercu', '%2e%2e/%2e%2e/windows/win.ini', 'C:%5CWindows%5Cwin.ini', 'file:%2F%2F%2Fetc%2Fpasswd'] as $charge) {
        foreach (['/apercu', '/apercu/fichier', '/fichier', '/miniature'] as $suffixe) {
            $reponse = requete($base, 'GET', '/documents/' . $charge . $suffixe, [$cookiesLecture]);
            $verifier($reponse['code'] < 200 || $reponse['code'] >= 300, "sortie de racine acceptée ($charge$suffixe)");
        }
    }

    // Les deux mutations enregistrées refusent l'absence de CSRF et le rejeu.
    $sansCsrfConnexion = requete($base, 'POST', '/connexion', ['Content-Type: application/x-www-form-urlencoded'], 'identifiant=x&mot_de_passe=x');
    $verifier($sansCsrfConnexion['code'] === 403, 'connexion sans CSRF acceptée');
    $fixe = str_repeat('a', 43);
    $premiereConnexion = requete($base, 'POST', '/connexion', [
        'Content-Type: application/x-www-form-urlencoded', 'Cookie: gedv2_session=' . $fixe . '; gedv2_csrf=' . $csrfAuthentification,
    ], http_build_query(['csrf' => $csrfAuthentification, 'identifiant' => 'testeur@gedv2.test', 'mot_de_passe' => 'MotDePasse-Test-2026!']));
    $cookieSession = enteteCookie($premiereConnexion['headers'], 'gedv2_session');
    $verifier($premiereConnexion['code'] === 303, 'connexion légitime refusée par la sonde');
    $verifier(str_contains($cookieSession, 'HttpOnly') && str_contains($cookieSession, 'SameSite=Lax'), 'cookie de session insuffisamment protégé');
    $verifier(cookie($premiereConnexion['headers'], 'gedv2_session') !== $fixe, 'fixation de session acceptée');
    $rejeuConnexion = requete($base, 'POST', '/connexion', [
        'Content-Type: application/x-www-form-urlencoded', 'Cookie: gedv2_csrf=' . $csrfAuthentification,
    ], http_build_query(['csrf' => $csrfAuthentification, 'identifiant' => 'testeur@gedv2.test', 'mot_de_passe' => 'MotDePasse-Test-2026!']));
    $verifier($rejeuConnexion['code'] === 403, 'rejeu CSRF de connexion accepté');

    $sessionMutation = $sessions->ouvrir('sonde-mutation@gedv2.test', []);
    $cookiesMutation = 'Cookie: gedv2_session=' . $sessionMutation['id'] . '; gedv2_csrf=' . $sessionMutation['csrf'];
    $sansCsrfDeconnexion = requete($base, 'POST', '/deconnexion', ['Cookie: gedv2_session=' . $sessionMutation['id']]);
    $verifier($sansCsrfDeconnexion['code'] === 403, 'déconnexion sans CSRF acceptée');
    $deconnexion = requete($base, 'POST', '/deconnexion', [$cookiesMutation, 'X-CSRF-Token: ' . $sessionMutation['csrf']]);
    $verifier($deconnexion['code'] === 303, 'déconnexion CSRF légitime refusée');
    $rejeuDeconnexion = requete($base, 'POST', '/deconnexion', [$cookiesMutation, 'X-CSRF-Token: ' . $sessionMutation['csrf']]);
    $verifier($rejeuDeconnexion['code'] === 403, 'rejeu CSRF de déconnexion accepté');

    // Toute mutation métier déclarée reçoit une charge volontairement invalide : le premier POST est
    // traité par le métier sans écrire, le second doit être bloqué par le jeton déjà consommé.
    $chargesMutations = [
        // Charge défensive de l'écran « À traiter » (lot V2-03-écran, Interface) : l'identifiant n'existe
        // pas, le métier refuse proprement ; la sonde ne vérifie que le CSRF absent / rejoué.
        'POST /a-traiter/{id:[0-9]+}/valider' => 'titre=Charge+sonde&type=Facture',
        // V2-12a : retouches défensives (identifiant inexistant, aucune écriture) ; la sonde couvre le CSRF.
        'POST /a-traiter/{id:[0-9]+}/pages/tourner' => 'page=0&degres=90',
        'POST /a-traiter/{id:[0-9]+}/pages/scinder' => 'page=0',
        'POST /a-traiter/{id:[0-9]+}/pages/fusionner' => 'avec=0',
        'POST /a-traiter/{id:[0-9]+}/pages/retirer' => 'page=0',
        'POST /a-traiter/{id:[0-9]+}/pages/annuler' => '',
        // Charge défensive du dépôt par photo (V2-10a, Interface) : sans fichier joint, le métier refuse
        // proprement (PRG nommé) ; la sonde ne vérifie que le CSRF absent / rejoué.
        'POST /deposer' => 'fichier=',
        // Charges défensives du Socle (V2-04) : chaque charge est refusée par le métier sans écrire.
        'POST /metadonnees/type' => 'action=inconnue',
        'POST /metadonnees/champ' => 'type=0&nom=',
        'POST /bibliotheque/vue' => 'nom=',
        // Lot ged-parite-masse : action inconnue et tâche inconnue, refusées par le métier sans écrire.
        'POST /bibliotheque/masse' => 'documents=&action=inconnue',
        'POST /bibliotheque/masse/{id:[0-9]+}/tranche' => '',
        'POST /documents/champ' => 'doc=0',
        // Nouvelle version V2-11 : fichier absent, le métier refuse sans écrire ; la sonde couvre CSRF.
        'POST /documents/{id:[0-9]+}/versions' => 'fichier=',
        // V2-12c : document inexistant, vue sans nom, lot vide ; le metier refuse sans ecrire.
        'POST /documents/{id:[0-9]+}/versions/{numero:[0-9]+}/restaurer' => '',
        'POST /recherche/vue' => 'nom=',
        'POST /a-traiter/valider-lot' => 'documents=',
        // V2-12d : note vide, lien vers 0, action de partage inconnue, tranche d'une tache inexistante ;
        // le lancement de reconstruction n'est jamais atteint (CSRF absent ou rejoue).
        'POST /documents/{id:[0-9]+}/notes' => 'texte=',
        'POST /documents/{id:[0-9]+}/liens' => 'cible=0',
        'POST /documents/{id:[0-9]+}/partages' => 'action=inconnue',
        'POST /documents/{id:[0-9]+}/partages/externe' => 'expiration=',
        'POST /application/reconstruction-index' => 'confirmation=',
        'POST /application/reconstruction-index/{id:[0-9]+}/tranche' => '',
        // Lot ged-pdfa-1 : le lancement du rattrapage PDF/A ouvre une tâche sur le fonds de test (comme
        // la reconstruction) ; la tranche d'une tâche inexistante est refusée par le métier. La sonde ne
        // vérifie ici que l'absence et le rejeu du jeton CSRF.
        'POST /application/pdfa-rattrapage' => 'confirmation=',
        'POST /application/pdfa-rattrapage/{id:[0-9]+}/tranche' => '',
        // Archivage V2-09b : identifiant inexistant, aucun scellement ne peut être écrit ; la sonde
        // n'exerce ici que l'absence et le rejeu CSRF de la route irréversible.
        'POST /documents/{id:[0-9]+}/sceller' => 'confirmation=1',
        // La vérification crée une tâche courte interne au fonds de test, sans aucune mutation de document.
        'POST /archives/verifier' => 'sonde=1',
        // Charges défensives de l'appairage (V2-08b, Jonctions) : le métier refuse ou ne révoque rien,
        // la sonde ne vérifie que le CSRF absent / rejoué.
        'POST /appairage' => 'application=cmm&nom=sonde&retour=http%3A%2F%2F127.0.0.1%2Fretour&etat=sonde',
        // Charges défensives de Mon profil (V2-08c, Jonctions) : adresse vide / appairage sans adresse
        // vérifiée sont refusés par le métier ; la sonde ne vérifie que le CSRF absent / rejoué.
        'POST /profil/mail' => 'adresse=',
        'POST /profil/appairage' => 'sonde=1',
        'POST /application/appareils/{id:[0-9]+}/revoquer' => 'sonde=1',
        // Administration V2-13 : formulaires volontairement incomplets ; aucune création ne peut aboutir,
        // mais l'absence et le rejeu CSRF restent vérifiés pour chaque mutation déclarée.
        'POST /administration/comptes' => 'identifiant=&role=',
        'POST /administration/groupes' => 'nom=&role=',
        // ACL V2-13 : identifiants inexistants, aucune règle ne peut être écrite par cette sonde.
        'POST /dossiers/{id:[0-9]+}/droits' => 'groupe=0&niveau=lire&autorise=0',
        'POST /workflows/regles' => 'nom=&action_type=',
        'POST /workflows/regles/{id:[0-9]+}/basculer' => 'actif=0',
        'POST /workflows/gabarits/{nom}' => 'sonde=1',
        // Workflows V2-06b (designer) : la création sans nom et l'enregistrement sans graphe sont refusés
        // par le métier, sans écrire ; la sonde ne vérifie que le CSRF absent / rejoué.
        'POST /workflows/circuits' => 'nom=',
        'POST /workflows/circuits/{id:[0-9]+}' => 'graphe=',
        'POST /workflows/essai' => 'document_id=0',
        'POST /workflows/evenements' => 'document_id=0',
        'POST /workflows/transitions' => 'document_id=0&vers=invalide',
        // Édition depuis le poste (F13) : document et copie inexistants, le métier refuse sans écrire ;
        // la sonde ne vérifie que l'absence et le rejeu du jeton CSRF.
        'POST /documents/{id:[0-9]+}/secours/recuperer' => 'copie=sonde',
    ];
    foreach (DroitsRoutes::declarees() as $definition => $permission) {
        [$methode, $chemin] = explode(' ', $definition, 2);
        if (in_array($methode, ['GET', 'HEAD', 'OPTIONS'], true) || DroitsRoutes::estPublique($permission) || $chemin === '/deconnexion') {
            continue;
        }
        if (!isset($chargesMutations[$definition])) {
            $defauts[] = "charge CSRF défensive absente pour $definition";
            continue;
        }
        $chemin = str_replace('/{id:[0-9]+}', '/999999999', $chemin);
        $chemin = str_replace('/{numero:[0-9]+}', '/1', $chemin);
        $chemin = str_replace('/{jeton:[A-Za-z0-9_-]+}', '/jeton_faux_sonde_0000000000000000', $chemin);
        $chemin = str_replace('/{nom}', '/sonde_inconnue', $chemin);
        $session = $sessions->ouvrir('sonde-csrf@gedv2.test', [DroitsRoutes::permissionCanonique($permission)]);
        $cookies = 'Cookie: gedv2_session=' . $session['id'] . '; gedv2_csrf=' . $session['csrf'];
        $absent = requete($base, $methode, $chemin, ['Content-Type: application/x-www-form-urlencoded', $cookies], $chargesMutations[$definition]);
        $verifier($absent['code'] === 403, "CSRF absent accepté sur $definition");
        $premier = requete($base, $methode, $chemin, [
            'Content-Type: application/x-www-form-urlencoded', $cookies, 'X-CSRF-Token: ' . $session['csrf'],
        ], $chargesMutations[$definition]);
        $verifier(!str_contains($premier['body'], 'déjà utilisé'), "premier CSRF refusé sur $definition");
        $rejeu = requete($base, $methode, $chemin, [
            'Content-Type: application/x-www-form-urlencoded', $cookies, 'X-CSRF-Token: ' . $session['csrf'],
        ], $chargesMutations[$definition]);
        $verifier($rejeu['code'] === 403 && str_contains($rejeu['body'], 'déjà utilisé'), "rejeu CSRF accepté sur $definition");
    }
} catch (Throwable $erreur) {
    $defauts[] = $erreur->getMessage();
} finally {
    if (is_resource($processus)) {
        proc_terminate($processus);
        for ($essaiArret = 0; $essaiArret < 10 && (proc_get_status($processus)['running'] ?? false); ++$essaiArret) {
            usleep(100000);
        }
        proc_close($processus);
    }
    if ($pdo instanceof PDO && is_int($compteUtilisateur)) {
        // Jamais une donnée utilisateur : ce compte synthétique vit uniquement dans gedv2_test.
        try {
            $pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$compteUtilisateur]);
        } catch (Throwable $erreur) {
            $defauts[] = 'suppression du compte éphémère impossible : ' . $erreur->getMessage();
        }
    }
    if ($pdo instanceof PDO && isset($jetonSondeDepot) && is_string($jetonSondeDepot)) {
        // Le jeton de sonde (portée depot) ne doit pas survivre à la passe : il vit dans gedv2_test seul.
        try {
            $pdo->exec("DELETE FROM jonctions_jetons WHERE application = 'sonde-documents'");
        } catch (Throwable $erreur) {
            error_log('sonde sécurité : nettoyage du jeton de sonde impossible : ' . $erreur->getMessage());
        }
    }
    if (is_dir($dossier)) {
        supprimerDossier($dossier);
    }
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

if ($defauts !== []) {
    foreach ($defauts as $defaut) {
        fwrite(STDERR, "ECHEC: $defaut\n");
    }
    exit(1);
}
echo "OK: sonde sécurité — aucun contournement accepté\n";
