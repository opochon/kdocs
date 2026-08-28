<?php
/**
 * Oracle SV-14 — D-GED-05 : la liaison ERP a une decision tranchee et tracee.
 *
 * Olivier, demande D-GED-05 : « Trancher comment traiter la liaison ERP ».
 * Ce point ne mesure PAS du code — il mesure qu'un ARBITRAGE existe, est
 * attribuable a Olivier (jamais a un agent, regle 9 EcosystemK : « un agent
 * produit un ecart, seul Olivier adopte une nouvelle valeur »), et porte un
 * choix redige. Rien a executer cote application : la sonde lit
 * recette/arbitrages.json, la seule source de verite pour ce genre de
 * decision (meme convention que A-GED-02).
 *
 * Trois issues distinctes, jamais confondues :
 *   - VERT   : un arbitrage bloquant D-GED-05 existe, tranche_le et
 *              tranche_par sont poses, choix est non vide.
 *   - ABSENT : aucun arbitrage ne mentionne D-GED-05 dans son `bloque`.
 *   - INCOMPLET : l'arbitrage existe mais tranche_par ou choix manque —
 *              c'est le cas trouve le 2026-08-28 (A-GED-01 portait
 *              tranche_le et choix sans tranche_par, contrairement a
 *              A-GED-02) : ne JAMAIS le lire comme VERT sans verifier les
 *              trois champs ensemble.
 *
 * Usage: php tests/integration/test_liaison_erp_tranchee.php
 */
declare(strict_types=1);

echo "\n";
echo "+==============================================================+\n";
echo "|   K-DOCS - LIAISON ERP TRANCHEE (SV-14, D-GED-05)             |\n";
echo "+==============================================================+\n\n";

$passed = 0;
$failed = 0;

function test(string $name, bool $ok, string $detail = ''): bool
{
    global $passed, $failed;
    echo $ok ? "\033[32m[OK]\033[0m $name" : "\033[31m[KO]\033[0m $name";
    $ok ? $passed++ : $failed++;
    if ($detail !== '') {
        echo " - $detail";
    }
    echo "\n";
    return $ok;
}

$path = __DIR__ . '/../../recette/arbitrages.json';

if (!test('recette/arbitrages.json existe', file_exists($path))) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);
$arbitrages = is_array($data['arbitrages'] ?? null) ? $data['arbitrages'] : [];

test('Le registre se decode et porte au moins un arbitrage', $arbitrages !== [], count($arbitrages) . ' entree(s)');

// L'arbitrage doit se designer lui-meme comme bloquant D-GED-05 (champ
// `bloque`) : chercher par contenu, pas par id fige, pour ne pas dependre
// d'un identifiant qui pourrait changer.
$candidat = null;
foreach ($arbitrages as $a) {
    $bloque = is_array($a['bloque'] ?? null) ? $a['bloque'] : [];
    foreach ($bloque as $b) {
        if (str_contains((string) $b, 'D-GED-05')) {
            $candidat = $a;
            break 2;
        }
    }
}

if (!test('Un arbitrage bloquant D-GED-05 existe (issue ABSENT sinon)', $candidat !== null,
    $candidat !== null ? "id={$candidat['id']}" : 'aucune entree ne cite D-GED-05 dans `bloque`')) {
    echo "\n" . str_repeat('=', 66) . "\nRESUME: $passed reussis, $failed echoues\n" . str_repeat('=', 66) . "\n";
    echo "\n\033[33mABSENT — la liaison ERP n'a pas de decision deposee.\033[0m\n";
    exit(1);
}

$trancheLe = trim((string) ($candidat['tranche_le'] ?? ''));
$tranchePar = trim((string) ($candidat['tranche_par'] ?? ''));
$choix = trim((string) ($candidat['choix'] ?? ''));

// Les trois champs ensemble, jamais un seul : c'est exactement l'ecart
// trouve sur A-GED-01 le 2026-08-28 (tranche_le + choix poses, tranche_par
// absent — impossible de distinguer une decision d'Olivier d'un agent qui
// se serait attribue la fermeture, la faute que la regle 9 EcosystemK
// interdit).
$complet = $trancheLe !== '' && $tranchePar !== '' && $choix !== '';

test('tranche_le est pose', $trancheLe !== '', $trancheLe !== '' ? $trancheLe : 'manquant');
test('tranche_par identifie Olivier, pas un agent', $tranchePar !== '' && !str_starts_with(strtolower($tranchePar), 'claude') && !str_starts_with(strtolower($tranchePar), 'codex'),
    $tranchePar !== '' ? $tranchePar : 'manquant');
test('choix est redige', $choix !== '', $choix !== '' ? substr($choix, 0, 60) . '…' : 'manquant');

echo "\n" . str_repeat('=', 66) . "\n";
echo "RESUME: $passed reussis, $failed echoues\n";
echo str_repeat('=', 66) . "\n";

if ($failed > 0) {
    echo "\n\033[31mINCOMPLET — la decision existe mais n'est pas tracable jusqu'a Olivier.\033[0m\n";
    exit(1);
}

echo "\n\033[32mLa liaison ERP a une decision tranchee et tracee : {$candidat['id']}, $trancheLe, par $tranchePar.\033[0m\n";
exit(0);
