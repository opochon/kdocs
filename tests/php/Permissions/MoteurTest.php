<?php

declare(strict_types=1);

namespace Ged\Tests\Permissions;

use Ged\Permissions\Moteur;
use Ged\Permissions\Regles;
use Ged\Socle\Registre;
use PHPUnit\Framework\TestCase;

/**
 * Oracle unitaire de la famille M-Files « permissions dynamiques » : règles nommées portables
 * (`.ged/permissions.json`), décision par classe / métadonnée / état, refus qui l'emporte, clause SQL
 * de visibilité pour la liste et la recherche, résolution du contexte d'un compte.
 */
final class MoteurTest extends TestCase
{
    use SupportPermissions;

    private Moteur $moteur;
    private Regles $regles;

    protected function setUp(): void
    {
        $this->setUpPermissions();
        Registre::oublier();
        $this->regles = new Regles($this->config);
        $this->moteur = new Moteur($this->config, $this->pdo);
    }

    protected function tearDown(): void
    {
        $this->tearDownPermissions();
    }

    public function testUneRegleNommeeEstPortableDansLeFonds(): void
    {
        $id = $this->regles->enregistrer([
            'nom' => 'Factures comptables', 'conditions' => ['classe' => 'Facture'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'groupe', 'valeur' => 10]],
        ], 'alice@gedv2.test');

        self::assertFileExists($this->racine . '/fonds/.ged/permissions.json');
        Registre::oublier();
        $relue = (new Regles($this->config))->parId($id);
        self::assertNotNull($relue);
        self::assertSame('Factures comptables', $relue['nom']);
        self::assertSame('Facture', $relue['conditions']['classe']);
        self::assertTrue($relue['actif']);
    }

    public function testRefusNommeDUneRegleSansCible(): void
    {
        try {
            $this->regles->enregistrer([
                'nom' => 'Sans cible', 'conditions' => ['classe' => 'Facture'],
                'voir' => true, 'modifier' => false, 'cibles' => [],
            ], 'alice@gedv2.test');
            self::fail('une règle sans cible ne doit pas être acceptée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cible', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->racine . '/fonds/.ged/permissions.json');
    }

    public function testUneRegleNeFaitQueRestreindreEtLeRefusLEmporte(): void
    {
        $this->regles->enregistrer([
            'nom' => 'Factures comptables', 'conditions' => ['classe' => 'Facture'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'groupe', 'valeur' => 10]],
        ], 'alice@gedv2.test');
        $this->regles->enregistrer([
            'nom' => 'Interdit aux secrétaires', 'conditions' => ['classe' => 'Facture'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'role', 'valeur' => 'comptable']],
        ], 'alice@gedv2.test');

        // Le lecteur est dans le groupe autorisé mais porte le rôle secrétaire : une seule règle le refuse.
        $decision = $this->moteur->decision(['classe' => 'Facture', 'champs' => [], 'etat' => null],
            ['compte' => 1, 'administrateur' => false, 'groupes' => [10], 'roles' => ['secretaire']]);
        self::assertFalse($decision['voir']);
        self::assertContains('Interdit aux secrétaires', $decision['appliquees']);
    }

    public function testUnDocumentHorsConditionResteIntact(): void
    {
        $this->regles->enregistrer([
            'nom' => 'Factures comptables', 'conditions' => ['classe' => 'Facture'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'groupe', 'valeur' => 10]],
        ], 'alice@gedv2.test');
        $decision = $this->moteur->decision(['classe' => 'Contrat', 'champs' => [], 'etat' => null],
            ['compte' => 2, 'administrateur' => false, 'groupes' => [], 'roles' => ['utilisateur']]);
        self::assertTrue($decision['voir']);
        self::assertTrue($decision['modifier']);
    }

    public function testClauseSqlParClasseEtParMetadonnee(): void
    {
        self::assertSame([null, []], $this->moteur->clauseSql('d', ['compte' => 1, 'administrateur' => false, 'groupes' => [], 'roles' => []]));

        $this->regles->enregistrer([
            'nom' => 'Factures comptables', 'conditions' => ['classe' => 'Facture'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'groupe', 'valeur' => 10]],
        ], 'alice@gedv2.test');
        [$clause, $params] = $this->moteur->clauseSql('d', ['compte' => 1, 'administrateur' => false, 'groupes' => [20], 'roles' => []]);
        self::assertNotNull($clause);
        self::assertStringContainsString('d.type = ?', $clause);
        self::assertSame(['Facture'], $params);

        // L'administrateur n'est jamais restreint.
        self::assertSame([null, []], $this->moteur->clauseSql('d', ['compte' => 1, 'administrateur' => true, 'groupes' => [], 'roles' => []]));
    }

    public function testClauseSqlParEtatUtiliseLaTableDuMoteurDeWorkflows(): void
    {
        $this->regles->enregistrer([
            'nom' => 'Validation en cours', 'conditions' => ['etat' => 'a-valider'],
            'voir' => true, 'modifier' => false, 'cibles' => [['type' => 'role', 'valeur' => 'controleur']],
        ], 'alice@gedv2.test');
        [$clause, $params] = $this->moteur->clauseSql('d', ['compte' => 2, 'administrateur' => false, 'groupes' => [], 'roles' => ['secretaire']]);
        self::assertNotNull($clause);
        self::assertStringContainsString('workflow_instances', (string) $clause);
        self::assertSame(['a-valider'], $params);
    }

    public function testLeContexteDUneCompteVientDeLaBase(): void
    {
        $groupe = $this->creerGroupe('comptables', 'comptable');
        $compte = $this->creerCompte('bob@gedv2.test', 'utilisateur');
        $this->ajouterAuGroupe($compte, $groupe);

        $contexte = $this->moteur->contextePourCompte($compte, false);
        self::assertSame([$groupe], $contexte['groupes']);
        self::assertContains('comptable', $contexte['roles']);
        self::assertContains('utilisateur', $contexte['roles']);
        self::assertFalse($contexte['administrateur']);
    }
}
