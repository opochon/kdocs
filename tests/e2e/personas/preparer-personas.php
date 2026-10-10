<?php

declare(strict_types=1);

/**
 * Prépare le fonds et les comptes RÉELS de la couche 5 du harness (Personas).
 *
 *   STORAGE_ROOT=<dossier> php tests/e2e/personas/preparer-personas.php
 *
 * - base gedv2_test seulement, verrou partagé (comme PHPUnit et les autres parcours) ;
 * - six comptes RÉELS, créés comme par l'installateur (mot de passe haché), un par persona ;
 *   le rôle fonctionnel suit le modèle Utilisateur / Manager / Administrateur décidé le 2026-10-03
 *   (docs/secteurs/droits-securite.md) et le droit sur le dossier suit la couche ACL du même modèle ;
 * - un fonds synthétique, un dossier par persona (`personas/<slug>/`), déposé par le VRAI
 *   `DepotFichiers` (fichier + `.meta.json` + index + rattachement ACL) ; aucun nom de client ;
 * - un champ personnalisé « Échéance » (date) sur le type Contrat, posé sur le contrat du comptable :
 *   le geste F9 dépend d'une date et se joue sous horloge figée côté navigateur ;
 * - l'index plein texte est reconstruit HORS requête (comme le planificateur), sans quoi la
 *   recherche des personas ne verrait rien (PERF-1).
 *
 * Sortie JSON : { preparation, mot_de_passe, comptes, documents, dossiers, champ_echeance, fonds }.
 */

$racine = dirname(__DIR__, 3);
require $racine . '/vendor/autoload.php';
require $racine . '/tests/oracles/e2e/EtatBase.php';

use Ged\Base;
use Ged\Config;
use Ged\Recherche\IndexPleinTexte;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;

putenv('DB_NAME=gedv2_test');   // ce script n'écrit que la base de test, quoi qu'en dise l'environnement
putenv('APP_ENV=test');

/** @var array<string, array{role:string, niveau:string, titre:string, type:string, statut:string, date:string, emetteur:string, montant:?float, texte:string}> */
const PERSONAS = [
    'fiduciaire' => ['role' => 'manager', 'niveau' => 'deposer', 'titre' => 'Courrier Fiduciaire ORACLE',
        'type' => 'Courrier', 'statut' => 'valide', 'date' => '2026-03-04', 'emetteur' => 'Etude Fictive Fiduciaire', 'montant' => null,
        'texte' => 'Courrier synthétique de la fiduciaire fictive : consultation de dossier.'],
    'secretaire' => ['role' => 'manager', 'niveau' => 'modifier', 'titre' => 'Facture Secretaire ORACLE',
        'type' => 'Facture', 'statut' => 'a_controler', 'date' => '2026-03-05', 'emetteur' => 'Fournitures Fictives SA', 'montant' => 120.50,
        'texte' => 'Facture synthétique à contrôler par le secrétariat fictif.'],
    'comptable' => ['role' => 'manager', 'niveau' => 'gerer', 'titre' => 'Contrat Maintenance ORACLE',
        'type' => 'Contrat', 'statut' => 'valide', 'date' => '2026-03-06', 'emetteur' => 'Bailleur Fictif SA', 'montant' => null,
        'texte' => 'Contrat synthétique de maintenance, échéance à poser par la comptabilité fictive.'],
    'administrateur' => ['role' => 'administrateur', 'niveau' => 'gerer', 'titre' => 'Rapport Interne ORACLE',
        'type' => 'Courrier', 'statut' => 'valide', 'date' => '2026-03-07', 'emetteur' => 'Direction Fictive', 'montant' => null,
        'texte' => 'Rapport interne synthétique, réservé à l’administration fictive.'],
    'auditeur' => ['role' => 'manager', 'niveau' => 'lire', 'titre' => 'Rapport Audit ORACLE',
        'type' => 'Courrier', 'statut' => 'valide', 'date' => '2026-03-08', 'emetteur' => 'Audit Fictif SA', 'montant' => null,
        'texte' => 'Rapport d’audit synthétique, lecture seule pour l’auditeur fictif.'],
    'debutant' => ['role' => 'manager', 'niveau' => 'deposer', 'titre' => 'Note Debutant ORACLE',
        'type' => 'Courrier', 'statut' => 'valide', 'date' => '2026-03-09', 'emetteur' => 'Accueil Fictif', 'montant' => null,
        'texte' => 'Note synthétique d’un nouvel arrivant fictif, à retrouver dans la GED.'],
];

const MOT_DE_PASSE = 'MotDePasse-Personas-2026!';

$verrou = fopen(sys_get_temp_dir() . '/gedv2_test.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX)) {
    fwrite(STDERR, "préparation personas : verrou gedv2_test indisponible\n");
    exit(1);
}

try {
    $config = new Config($racine);
    if ($config->get('DB_NAME', 'gedv2_test') !== 'gedv2_test') {
        throw new RuntimeException('ce parcours n’écrit que sur gedv2_test');
    }

    $fonds = str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'var/oracles/personas/documents'));
    supprimerDossier($fonds);
    if (!mkdir($fonds, 0775, true) && !is_dir($fonds)) {
        throw new RuntimeException("préparation : impossible de créer $fonds");
    }

    Base::assurerBase($config);
    $pdo = Base::pdo($config);
    foreach (['Socle', 'Securite', 'Ingestion', 'Jonctions', 'Classement', 'Recherche', 'Workflows', 'Archivage'] as $secteur) {
        $classe = 'Ged\\' . $secteur . '\\Schema';
        if (class_exists($classe)) {
            $classe::installer($pdo);
        }
    }

    // Remise à zéro des tables dérivées du fonds, sous le verrou partagé (protocole des autres parcours) :
    // aucun document d'un passage précédent ne peut apparaître dans la bibliothèque d'un persona.
    remettreEtatFondsE2e($pdo);

    // Nomenclature (les six types du plan) vit dans le fonds `.ged/`, recréé à neuf.
    (new Nomenclature($config))->assurer();

    // Champ personnalisé « Échéance » (date) sur le type Contrat : le geste F9 s'y adosse.
    $champs = new Champs($config);
    $cleEcheance = 'echeance';
    $existe = false;
    foreach ($champs->pourType('Contrat') as $definition) {
        if ($definition['cle'] === $cleEcheance) {
            $existe = true;
        }
    }
    if (!$existe) {
        $typeContrat = (new Nomenclature($config))->idDuType('Contrat');
        if ($typeContrat === null) {
            throw new RuntimeException('préparation : le type Contrat manque à la nomenclature');
        }
        $champs->ajouter($typeContrat, 'Échéance', 'date');
    }

    $droits = new DroitsDossiers($pdo);
    $comptes = [];
    $documents = [];
    $dossiers = [];

    $modele = $racine . '/tests/corpus/apercu/documents/201-facture-an-5001.pdf';
    $contenu = is_file($modele) ? (string) file_get_contents($modele) : "%PDF-1.4\n%%EOF\n";
    $depot = new DepotFichiers($config);

    foreach (PERSONAS as $slug => $persona) {
        $identifiant = "persona-$slug@gedv2.test";
        $pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE empreinte = VALUES(empreinte), role = VALUES(role)')
            ->execute([$identifiant, password_hash(MOT_DE_PASSE, PASSWORD_DEFAULT), $persona['role']]);
        $compteId = (int) $pdo->query('SELECT id FROM comptes WHERE identifiant = ' . $pdo->quote($identifiant))->fetchColumn();
        $comptes[$slug] = ['id' => $compteId, 'identifiant' => $identifiant, 'role' => $persona['role']];

        // Groupe fonctionnel (le droit de dossier s'y rattache) : le rôle du groupe porte les gestes.
        $groupeNom = "Personas $slug";
        $pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE role_code = VALUES(role_code)')->execute([$groupeNom, $persona['role']]);
        $groupeId = (int) $pdo->query('SELECT id FROM securite_groupes WHERE nom = ' . $pdo->quote($groupeNom))->fetchColumn();
        $droits->ajouterCompteAuGroupe($compteId, $groupeId);

        // Un document synthétique par persona, déposé par le VRAI chemin (fichier + meta + index + ACL).
        $dossier = 'personas/' . $slug;
        $source = sys_get_temp_dir() . '/gedv2-personas-' . $slug . '.pdf';
        file_put_contents($source, $contenu);
        $meta = [
            'titre' => $persona['titre'], 'type' => $persona['type'], 'emetteur' => $persona['emetteur'],
            'date' => $persona['date'], 'statut' => $persona['statut'], 'source' => 'dossier',
            'dossier' => $dossier, 'texte' => $persona['texte'],
        ];
        if ($persona['statut'] === 'a_controler') {
            $meta['raison'] = 'proposition sous le seuil, à contrôler par un humain';
            $meta['classement'] = ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture » dans le texte'];
        }
        if ($persona['montant'] !== null) {
            $meta['montant'] = $persona['montant'];
        }
        $id = $depot->deposer($source, $meta);
        $depot->mettreAJour($id, ['id' => $id], 'oracle');   // `.meta.json` auto-descriptif (visionneuse)
        @unlink($source);

        $chemin = (string) $pdo->query('SELECT chemin_relatif FROM documents WHERE id = ' . $id)->fetchColumn();
        $documents[$slug] = ['id' => $id, 'chemin' => $chemin, 'titre' => $persona['titre']];

        $dossierId = $droits->assurerDossierPourChemin($chemin);
        if ($dossierId === null) {
            throw new RuntimeException("préparation : dossier de droits introuvable pour $chemin");
        }
        $dossierPersonaId = $droits->dossierPourChemin($dossier) ?? $dossierId;
        $dossiers[$slug] = $dossierPersonaId;
        $droits->poserRegle($dossierPersonaId, $groupeId, $persona['niveau'], true);

        // Le contrat du comptable porte une échéance datée : le geste F9 la relit sous horloge figée.
        if ($slug === 'comptable') {
            $champs->poserValeur($chemin, $cleEcheance, '2026-11-09');
        }
    }

    // Index plein texte hors requête (PERF-1) : la recherche des personas lit cet index.
    IndexPleinTexte::depuis($config, $pdo)->synchroniser(null);

    echo json_encode([
        'preparation' => 'prete',
        'mot_de_passe' => MOT_DE_PASSE,
        'comptes' => $comptes,
        'documents' => $documents,
        'dossiers' => $dossiers,
        'champ_echeance' => $cleEcheance,
        'fonds' => $fonds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'préparation personas : ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}

/** Efface un dossier d'essai créé par ce script (jamais un dossier du corpus versionné). */
function supprimerDossier(string $chemin): void
{
    if (!is_dir($chemin)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($chemin, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($chemin);
}
