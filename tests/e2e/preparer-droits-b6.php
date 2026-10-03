<?php

declare(strict_types=1);

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Schema as SchemaSocle;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
putenv('DB_NAME=gedv2_test');
putenv('APP_ENV=test');

$racine = dirname(__DIR__, 2);
$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    throw new RuntimeException('B6 : verrou gedv2_test indisponible');
}
try {
    $config = new Config($racine);
    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    SchemaSocle::installer($pdo);
    SchemaSecurite::installer($pdo);
    $suffixe = bin2hex(random_bytes(5));
    $motDePasse = 'MotDePasse-B6-2026!';
    $comptes = [];
    foreach (['utilisateur', 'manager', 'administrateur'] as $role) {
        $identifiant = "b6-$role-$suffixe@gedv2.test";
        $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), $role]);
        $comptes[$role] = ['id' => (int) $pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
    $droits = new DroitsDossiers($pdo);
    $groupeUtilisateurs = $droits->creerGroupe("B6 utilisateurs $suffixe", 'utilisateur');
    $groupeManagers = $droits->creerGroupe("B6 managers $suffixe", 'manager');
    $droits->ajouterCompteAuGroupe($comptes['utilisateur']['id'], $groupeUtilisateurs);
    $droits->ajouterCompteAuGroupe($comptes['manager']['id'], $groupeManagers);
    $documents = [];
    foreach (['A', 'B'] as $nom) {
        $chemin = "b6-$suffixe/$nom/document-$nom.txt";
        $pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, empreinte, taille, mime, meta, statut) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin, "document-$nom.txt", "B6 dossier $nom $suffixe", hash('sha256', $chemin), 1, 'text/plain', '{}', 'a_controler']);
        $documents[$nom] = (int) $pdo->lastInsertId();
    }
    $dossierA = $droits->assurerDossierPourChemin("b6-$suffixe/A/document-A.txt");
    $dossierB = $droits->assurerDossierPourChemin("b6-$suffixe/B/document-B.txt");
    if ($dossierA === null || $dossierB === null) {
        throw new RuntimeException('B6 : dossiers ACL impossibles à préparer.');
    }
    $droits->poserRegle($dossierA, $groupeUtilisateurs, 'deposer', true);
    $droits->poserRegle($dossierA, $groupeManagers, 'gerer', true);
    echo json_encode(['mot_de_passe' => $motDePasse, 'comptes' => $comptes, 'dossiers' => ['A' => $dossierA, 'B' => $dossierB],
        'documents' => $documents, 'groupe_utilisateurs' => $groupeUtilisateurs], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
