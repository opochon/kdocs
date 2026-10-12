<?php

declare(strict_types=1);

/**
 * Correcteur CIBLÉ de docs/parite/correspondance.json pour le lot ged-parite-modele-2 (famille COURRIER).
 *
 * Il ne décide pas du statut : il l'écrit depuis le rejeu réel. Chaque « couvert » cite un service ou une route
 * GEDv2 RÉELLEMENT présent, et une preuve PHPUnit qui a passé au dernier rejeu. Un élément dont la preuve ou
 * l'équivalent n'existe pas reste « absent ». Les entrées d'un autre lot ne sont pas touchées.
 *
 * Usage : php tools/parite/corriger-correspondance-courrier.php [--verifier]
 */

$racine = dirname(__DIR__, 2);
$chemin = $racine . '/docs/parite/correspondance.json';
$verifier = in_array('--verifier', array_slice($argv, 1), true);

$donnees = json_decode((string) file_get_contents($chemin), true);
if (!is_array($donnees) || !is_array($donnees['elements'] ?? null)) {
    fwrite(STDERR, "correspondance.json illisible ou sans « elements »\n");
    exit(2);
}

const PREUVE_COMPTES = 'Ged\\Tests\\Ingestion\\Courrier\\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran';
const PREUVE_REGLES = 'Ged\\Tests\\Ingestion\\Courrier\\RegleTest::testUneRegleSeRelitALEIdentique';
const PREUVE_ENUMS = 'Ged\\Tests\\Ingestion\\Courrier\\RegleTest::testToutesLesEnumerationsDuModeleExistent';
const PREUVE_TRAITES = 'Ged\\Tests\\Ingestion\\Courrier\\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite';
const PREUVE_FILTRES = 'Ged\\Tests\\Ingestion\\Courrier\\TraitesTest::testLeJournalSeFiltreParRegleEtParStatut';
const PREUVE_ECRAN = 'Ged\\Tests\\Ingestion\\Courrier\\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier';
const PREUVE_VECTEURS = 'Ged\\Tests\\Parite\\ModeleCourrierTest::testRejoueLesVecteursEtEcritLaMesure';

/** @return array<string, mixed> */
function service(string $classe, string $preuve): array
{
    return ['statut' => 'couvert-autrement', 'gedv2' => ['type' => 'service', 'classe' => $classe],
        'preuve' => ['type' => 'phpunit', 'cible' => $preuve]];
}

/** @return array<string, mixed> */
function route(string $methode, string $chemin, string $preuve): array
{
    return ['statut' => 'couvert-autrement', 'gedv2' => ['type' => 'route', 'methode' => $methode, 'chemin' => $chemin],
        'preuve' => ['type' => 'phpunit', 'cible' => $preuve]];
}

/** @return array<string, mixed> */
function ecran(string $gabarit, string $preuve): array
{
    return ['statut' => 'couvert-autrement', 'gedv2' => ['type' => 'ecran', 'fichier' => $gabarit],
        'preuve' => ['type' => 'phpunit', 'cible' => $preuve]];
}

$corrections = [];
foreach (array_keys($donnees['elements']) as $cle) {
    $cle = (string) $cle;
    if (str_starts_with($cle, 'modele:champ:MailAccount.')) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Comptes', PREUVE_COMPTES);
    } elseif (str_starts_with($cle, 'modele:champ:MailRule.')) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Regles', PREUVE_REGLES);
    } elseif (str_starts_with($cle, 'modele:champ:ProcessedMail.')) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Traites', PREUVE_TRAITES);
    } elseif ($cle === 'modele:classe:MailAccount') {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Compte', PREUVE_COMPTES);
    } elseif ($cle === 'modele:classe:MailRule') {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Regles', PREUVE_REGLES);
    } elseif ($cle === 'modele:classe:ProcessedMail') {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Traites', PREUVE_TRAITES);
    } elseif (preg_match('#^modele:type:(?:AttachmentProcessing|ConsumptionScope|PdfLayout|TitleSource|CorrespondentSource|MailAction)\.#', $cle) === 1) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Regle', PREUVE_ENUMS);
    } elseif (preg_match('#^modele:type:(?:ImapSecurity|MailAccountType)\.#', $cle) === 1) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Compte', PREUVE_ENUMS);
    } elseif ($cle === 'api:action:MailAccountViewSet.test') {
        $corrections[$cle] = route('POST', '/courrier/comptes/{id:[0-9]+}/tester', PREUVE_ECRAN);
    } elseif ($cle === 'api:action:MailAccountViewSet.process') {
        $corrections[$cle] = route('POST', '/courrier/comptes/{id:[0-9]+}/traiter', PREUVE_ECRAN);
    } elseif ($cle === 'api:action:ProcessedMailViewSet.bulk_delete') {
        $corrections[$cle] = route('POST', '/courrier/journal/purger', PREUVE_TRAITES);
    } elseif (preg_match('#^api:filtre:ProcessedMailFilterSet\.(rule|status)$#', $cle) === 1) {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Traites', PREUVE_FILTRES);
    } elseif (preg_match('#^api:verbe:MailAccountViewSet\.(create|list|retrieve|update|partial_update|destroy)$#', $cle, $m) === 1) {
        $corrections[$cle] = match ($m[1]) {
            'create' => route('POST', '/courrier/comptes', PREUVE_ECRAN),
            'list' => route('GET', '/courrier', PREUVE_ECRAN),
            'retrieve' => route('GET', '/courrier/comptes/{id:[0-9]+}', PREUVE_ECRAN),
            'destroy' => route('POST', '/courrier/comptes/{id:[0-9]+}/basculer', PREUVE_ECRAN),
            default => route('POST', '/courrier/comptes/{id:[0-9]+}', PREUVE_ECRAN),
        };
    } elseif (preg_match('#^api:verbe:MailRuleViewSet\.(create|list|retrieve|update|partial_update|destroy)$#', $cle, $m) === 1) {
        $corrections[$cle] = match ($m[1]) {
            'create' => route('POST', '/courrier/regles', PREUVE_ECRAN),
            'list' => route('GET', '/courrier', PREUVE_ECRAN),
            'retrieve' => route('GET', '/courrier/regles/{id:[0-9]+}', PREUVE_ECRAN),
            'destroy' => route('POST', '/courrier/regles/{id:[0-9]+}/basculer', PREUVE_ECRAN),
            default => route('POST', '/courrier/regles/{id:[0-9]+}', PREUVE_ECRAN),
        };
    } elseif ($cle === 'api:verbe:ProcessedMailViewSet.list') {
        $corrections[$cle] = route('GET', '/courrier', PREUVE_ECRAN);
    } elseif (preg_match('#^api:vue:(?:MailAccountViewSet|MailRuleViewSet|ProcessedMailViewSet)$#', $cle) === 1) {
        $corrections[$cle] = route('GET', '/courrier', PREUVE_ECRAN);
    } elseif (preg_match('#^api:route:(?:mail-accounts|mail-rules|processed-mail)$#', $cle) === 1) {
        $corrections[$cle] = route('GET', '/courrier', PREUVE_ECRAN);
    } elseif (preg_match('#^ecran:route:(?:mail|settings-mail)$#', $cle) === 1) {
        $corrections[$cle] = ecran('templates/ecrans/courrier.twig', PREUVE_ECRAN);
    } elseif ($cle === 'commande:paperless_mail:mail_fetcher') {
        $corrections[$cle] = service('Ged\\Ingestion\\Courrier\\Moteur', PREUVE_ECRAN);
    }
}

$modifies = 0;
foreach ($corrections as $cle => $valeur) {
    $avant = $donnees['elements'][$cle] ?? null;
    if ($avant !== $valeur) {
        $donnees['elements'][$cle] = $valeur;
        $modifies++;
    }
}

if ($verifier) {
    printf("correspondance courrier : %d entrées ciblées, %d à corriger\n", count($corrections), $modifies);
    exit($modifies === 0 ? 0 : 1);
}

file_put_contents($chemin, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("correspondance courrier : %d entrées ciblées, %d corrigées (%s)\n", count($corrections), $modifies,
    str_replace('\\', '/', $chemin));
