<?php

declare(strict_types=1);

/**
 * Faux serveur IMAP pour les tests : sert UNE connexion sur 127.0.0.1:<port>, avec les messages *.eml de
 * <dossier> (UID = ordre alphabétique), puis écrit dans <dossier>/journal.json les commandes reçues et
 * l'état final (lus, rangés). Usage : php faux-imap.php <port> <dossier> [sansMOVE]. Port 0 : le système choisit un
 * port libre, annoncé sur la ligne « pret <port> » (plus de collision avec un port déjà pris sur le poste).
 */
[$_, $port, $dossier] = $argv + [null, null, null];
$avecMove = ($argv[3] ?? '') !== 'sansMOVE';
$serveur = stream_socket_server("tcp://127.0.0.1:$port", $code, $msg);
if ($serveur === false) {
    fwrite(STDERR, "écoute impossible : $msg\n");
    exit(1);
}
$adresse = (string) stream_socket_get_name($serveur, false);
fwrite(STDOUT, 'pret ' . substr($adresse, (int) strrpos($adresse, ':') + 1) . "\n");

$messages = [];
$fichiers = glob($dossier . '/*.eml') ?: [];
sort($fichiers);
foreach ($fichiers as $i => $f) {
    $messages[$i + 1] = (string) file_get_contents($f);
}
$lus = [];
$ranges = [];
$commandes = [];

$c = stream_socket_accept($serveur, 20);
if ($c === false) {
    exit(2);
}
$ecrire = static function (string $s) use ($c): void {
    fwrite($c, $s);
};
$ecrire("* OK faux IMAP pret\r\n");
while (($ligne = fgets($c)) !== false) {
    $ligne = rtrim($ligne, "\r\n");
    [$tag, $reste] = explode(' ', $ligne, 2) + [1 => ''];
    $commandes[] = preg_replace('/^LOGIN .*/i', 'LOGIN ***', $reste);
    $mots = explode(' ', $reste);
    $verbe = strtoupper($mots[0]);
    $uid = 0;
    if ($verbe === 'UID') {
        $verbe = 'UID ' . strtoupper($mots[1] ?? '');
        $uid = (int) ($mots[2] ?? 0);
    }
    switch ($verbe) {
        case 'LOGIN':
            str_contains($reste, '"bon-mot"') ? $ecrire("$tag OK connecte\r\n") : $ecrire("$tag NO identifiants refuses\r\n");
            break;
        case 'CAPABILITY':
            $ecrire('* CAPABILITY IMAP4rev1' . ($avecMove ? ' MOVE' : '') . "\r\n$tag OK\r\n");
            break;
        case 'SELECT':
            $ecrire('* ' . count($messages) . " EXISTS\r\n$tag OK [READ-WRITE] selectionne\r\n");
            break;
        case 'UID SEARCH':
            $non = array_diff(array_keys($messages), $lus, array_keys($ranges));
            $ecrire('* SEARCH' . ($non ? ' ' . implode(' ', $non) : '') . "\r\n$tag OK\r\n");
            break;
        case 'UID FETCH':
            if (isset($messages[$uid])) {
                $m = $messages[$uid];
                $ecrire("* $uid FETCH (UID $uid BODY[] {" . strlen($m) . "}\r\n" . $m . ")\r\n");
            }
            $ecrire("$tag OK\r\n");
            break;
        case 'UID STORE':
            $lus[] = $uid;
            $ecrire("$tag OK\r\n");
            break;
        case 'UID MOVE':
        case 'UID COPY':
            $ranges[$uid] = trim($mots[3] ?? '', '"');
            $ecrire("$tag OK\r\n");
            break;
        case 'CREATE':
            $ecrire("$tag NO [ALREADYEXISTS] existe\r\n");
            break;
        case 'LOGOUT':
            $ecrire("* BYE\r\n$tag OK\r\n");
            break 2;
        default:
            $ecrire("$tag BAD commande inconnue\r\n");
    }
}
fclose($c);
file_put_contents($dossier . '/journal.json', json_encode(['commandes' => $commandes, 'lus' => array_values(array_unique($lus)), 'ranges' => $ranges], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
