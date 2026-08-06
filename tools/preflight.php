<?php
/**
 * GEDv1 - preflight : l environnement est-il en etat d etre teste ?
 * Lecon K-Time (06-08) : sur ~20 rouges de session, aucun n etait une regression ;
 * tous etaient outillage absent ou base non semee. Ce controle-la, pas les tests,
 * empeche de perdre une heure a "reparer" du code sain.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$checks = [];

$checks[] = ['vendor composer', is_file("$root/vendor/autoload.php"), 'php bin/composer.phar install'];
$checks[] = ['playwright', is_dir("$root/tests/visual/node_modules"), 'cd tests/visual && npm install && npx playwright install chromium'];
$checks[] = ['storage inscriptible', is_dir("$root/storage") && is_writable("$root/storage"), 'droits sur storage/'];
$checks[] = ['.env present', is_file("$root/.env"), 'copier .env.example vers .env'];

// .env : lecture tolerante (parse_ini_file casse sur les commentaires libres)
$env = [];
if (is_file("$root/.env")) {
    foreach (file("$root/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#' || !str_contains($ln, '=')) { continue; }
        [$k, $v] = explode('=', $ln, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

$dbOk = false; $dbMsg = 'config/config.php illisible';
if (is_file("$root/config/config.php")) {
    $cfg = require "$root/config/config.php";
    $d = $cfg['database'] ?? [];
    try {
        $pdo = new PDO(
            "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4",
            $d['username'] ?? $d['user'] ?? 'root',
            $d['password'] ?? $d['pass'] ?? ''
        );
        $n = (int) $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
        $dbOk = true; $dbMsg = "{$d['name']} sur {$d['port']} — $n document(s)";
    } catch (Throwable $e) { $dbMsg = substr($e->getMessage(), 0, 100); }
}
$checks[] = ['base de donnees', $dbOk, $dbMsg];
if ($dbOk) { echo ''; }

// K-Time : l aller-retour REEL. Les tests ErpConnect sont tous a transport moque
// ("aucun acces reseau") ; sans ce controle, rien ne prouve que l integration marche.
$kt  = $env['KTIME_URL'] ?? 'http://127.0.0.1:8090';
$key = $env['KTIME_GED_API_KEY'] ?? '';
$ch = curl_init(rtrim($kt, '/') . '/api/ged/health');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_HTTPHEADER => $key !== '' ? ['X-Api-Key: ' . $key, 'Accept: application/json'] : ['Accept: application/json'],
]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// Extrait de reponse serveur affiche tel quel : masquage defensif de la cle au cas ou
// K-Time la reverberait dans un message d'erreur (jamais la cle en clair sur ce terminal).
$bodySnippet = substr($body, 0, 60);
if ($key !== '' && str_contains($bodySnippet, $key)) {
    $bodySnippet = str_replace($key, '***MASQUE***', $bodySnippet);
}
$ktMsg = $code === 200
    ? 'aller-retour reel disponible'
    : ($code === 0 ? "injoignable sur $kt" : "HTTP $code" . ($key === '' ? ' (KTIME_GED_API_KEY absente du .env)' : ' malgre la cle') . " — " . $bodySnippet);
$checks[] = ['K-Time /api/ged/health', $code === 200, $ktMsg];

echo 'GEDv1 preflight', PHP_EOL, str_repeat('-', 66), PHP_EOL;
$bloq = 0;
foreach ($checks as [$nom, $ok, $info]) {
    printf("%-24s %-9s %s%s", $nom, $ok ? 'OK' : 'BLOQUANT', $ok && $nom === 'base de donnees' ? $info : ($ok ? '' : ''), PHP_EOL);
    if (!$ok) { $bloq++; echo str_repeat(' ', 26), $info, PHP_EOL; }
}
echo str_repeat('-', 66), PHP_EOL;
if ($bloq) { echo "VERDICT  NON EVALUABLE - $bloq prerequis manquant(s).", PHP_EOL; exit(1); }
echo 'VERDICT  EVALUABLE.', PHP_EOL;
exit(0);