<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Workflows\Action;
use Ged\Workflows\CourrielFaux;
use Ged\Workflows\WebhookFaux;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Actions du moteur Paperless de GEDv2 : affectation et retrait des attributs du document, courriel et
 * webhook. Les ports (courriel, webhook) sont injectés en FAUX : aucun réseau, aucun secret.
 *
 * Chaque cas porte le NOM du champ Paperless qu'il prouve.
 */
final class ActionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'id' => 42,
            'titre' => 'Facture AN-7001',
            'type' => 'Facture',
            'emetteur' => 'Atelier Fictif Exemple Sàrl',
            'dossier' => '2026/Facture',
            'proprietaire' => 'alice',
            'etiquettes' => ['urgent', 'à payer'],
            'nom_fichier' => 'facture-an-7001.pdf',
            'chemin' => 'depot/facture-an-7001.pdf',
            'contenu' => 'Facture AN-7001',
            'source' => 1,
            'mailrule' => 3,
            'droits' => [
                'lecture' => ['utilisateurs' => ['bob'], 'groupes' => ['compta']],
                'modification' => ['utilisateurs' => ['bob'], 'groupes' => ['compta']],
            ],
            'champs' => ['IBAN' => 'CH93', 'Centre' => 'Lausanne'],
            'dates' => ['added' => '2026-04-01', 'created' => '2020-02-29', 'modified' => '2026-04-02'],
        ];
    }

    /**
     * @param list<string> $lectureUtilisateurs
     * @param list<string> $lectureGroupes
     * @param list<string> $modifUtilisateurs
     * @param list<string> $modifGroupes
     * @return array<string, array<string, list<string>>>
     */
    private static function droits(array $lectureUtilisateurs, array $lectureGroupes, array $modifUtilisateurs, array $modifGroupes): array
    {
        return [
            'lecture' => ['utilisateurs' => $lectureUtilisateurs, 'groupes' => $lectureGroupes],
            'modification' => ['utilisateurs' => $modifUtilisateurs, 'groupes' => $modifGroupes],
        ];
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>, list<string>}> */
    public static function affectations(): iterable
    {
        yield 'WorkflowAction.type (AFFECTATION)' => [['type' => Action::AFFECTATION], [], []];
        yield 'WorkflowAction.assign_title' => [['assign_title' => 'Titre imposé'], ['titre' => 'Titre imposé'], []];
        yield 'WorkflowAction.assign_title (gabarit)' => [['assign_title' => 'Doc {{correspondent}}'], ['titre' => 'Doc Atelier Fictif Exemple Sàrl'], []];
        yield 'WorkflowAction.assign_title (année de création)' => [['assign_title' => 'Créé en {{created_year}}'], ['titre' => 'Créé en 2020'], []];
        yield 'WorkflowAction.assign_title (placeholder inconnu : repli)' => [['assign_title' => 'Doc {{inconnu}}'], ['titre' => 'Facture AN-7001'], ['titre_refuse']];
        yield 'WorkflowAction.assign_correspondent' => [['assign_correspondent' => 'Nouvel Émetteur'], ['emetteur' => 'Nouvel Émetteur'], []];
        yield 'WorkflowAction.assign_document_type' => [['assign_document_type' => 'Contrat'], ['type' => 'Contrat'], []];
        yield 'WorkflowAction.assign_storage_path' => [['assign_storage_path' => '2026/Contrat'], ['dossier' => '2026/Contrat'], []];
        yield 'WorkflowAction.assign_owner' => [['assign_owner' => 'carol'], ['proprietaire' => 'carol'], []];
        yield 'WorkflowAction.assign_tags' => [['assign_tags' => ['vip']], ['etiquettes' => ['urgent', 'à payer', 'vip']], ['etiquettes']];
        yield 'WorkflowAction.assign_view_users' => [['assign_view_users' => ['dave']], ['droits' => self::droits(['bob', 'dave'], ['compta'], ['bob'], ['compta'])], []];
        yield 'WorkflowAction.assign_view_groups' => [['assign_view_groups' => ['direction']], ['droits' => self::droits(['bob'], ['compta', 'direction'], ['bob'], ['compta'])], []];
        yield 'WorkflowAction.assign_change_users' => [['assign_change_users' => ['dave']], ['droits' => self::droits(['bob'], ['compta'], ['bob', 'dave'], ['compta'])], []];
        yield 'WorkflowAction.assign_change_groups' => [['assign_change_groups' => ['direction']], ['droits' => self::droits(['bob'], ['compta'], ['bob'], ['compta', 'direction'])], []];
        yield 'WorkflowAction.assign_custom_fields' => [['assign_custom_fields' => ['IBAN'], 'assign_custom_fields_values' => ['IBAN' => 'CH00']], ['champs' => ['IBAN' => 'CH00', 'Centre' => 'Lausanne']], []];
        yield 'WorkflowAction.assign_custom_fields_values (nouveau champ)' => [['assign_custom_fields' => ['TVA'], 'assign_custom_fields_values' => ['TVA' => 8.1]], ['champs' => ['IBAN' => 'CH93', 'Centre' => 'Lausanne', 'TVA' => 8.1]], []];
    }

    /**
     * @param array<string,mixed> $action
     * @param array<string,mixed> $attendu
     * @param list<string>        $effets
     */
    #[DataProvider('affectations')]
    public function testChaqueChampDAffectationEstApplique(array $action, array $attendu, array $effets): void
    {
        $action['type'] ??= Action::AFFECTATION;
        $resultat = Action::appliquer($action, $this->document());
        self::assertArrayHasKey('document', $resultat);

        foreach ($attendu as $cle => $valeur) {
            self::assertSame($valeur, $resultat['document'][$cle], "attribut « $cle »");
        }
        foreach ($effets as $effet) {
            self::assertContains($effet, $resultat['effets']);
        }
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>}> */
    public static function retraits(): iterable
    {
        yield 'WorkflowAction.type (RETRAIT)' => [['type' => Action::RETRAIT], []];
        yield 'WorkflowAction.remove_tags' => [['remove_tags' => ['à payer']], ['etiquettes' => ['urgent']]];
        yield 'WorkflowAction.remove_all_tags' => [['remove_all_tags' => true], ['etiquettes' => []]];
        yield 'WorkflowAction.remove_correspondents' => [['remove_correspondents' => ['Atelier Fictif Exemple Sàrl']], ['emetteur' => null]];
        yield 'WorkflowAction.remove_all_correspondents' => [['remove_all_correspondents' => true], ['emetteur' => null]];
        yield 'WorkflowAction.remove_document_types' => [['remove_document_types' => ['Facture']], ['type' => null]];
        yield 'WorkflowAction.remove_all_document_types' => [['remove_all_document_types' => true], ['type' => null]];
        yield 'WorkflowAction.remove_storage_paths' => [['remove_storage_paths' => ['2026/Facture']], ['dossier' => null]];
        yield 'WorkflowAction.remove_all_storage_paths' => [['remove_all_storage_paths' => true], ['dossier' => null]];
        yield 'WorkflowAction.remove_owners' => [['remove_owners' => ['alice']], ['proprietaire' => null]];
        yield 'WorkflowAction.remove_all_owners' => [['remove_all_owners' => true], ['proprietaire' => null]];
        yield 'WorkflowAction.remove_view_users' => [['remove_view_users' => ['bob']], ['droits' => self::droits([], ['compta'], ['bob'], ['compta'])]];
        yield 'WorkflowAction.remove_view_groups' => [['remove_view_groups' => ['compta']], ['droits' => self::droits(['bob'], [], ['bob'], ['compta'])]];
        yield 'WorkflowAction.remove_change_users' => [['remove_change_users' => ['bob']], ['droits' => self::droits(['bob'], ['compta'], [], ['compta'])]];
        yield 'WorkflowAction.remove_change_groups' => [['remove_change_groups' => ['compta']], ['droits' => self::droits(['bob'], ['compta'], ['bob'], [])]];
        yield 'WorkflowAction.remove_all_permissions' => [['remove_all_permissions' => true], ['droits' => ['lecture' => ['utilisateurs' => [], 'groupes' => []], 'modification' => ['utilisateurs' => [], 'groupes' => []]]]];
        yield 'WorkflowAction.remove_custom_fields' => [['remove_custom_fields' => ['IBAN']], ['champs' => ['Centre' => 'Lausanne']]];
        yield 'WorkflowAction.remove_all_custom_fields' => [['remove_all_custom_fields' => true], ['champs' => []]];
    }

    /** @param array<string,mixed> $action @param array<string,mixed> $attendu */
    #[DataProvider('retraits')]
    public function testChaqueChampDeRetraitEstApplique(array $action, array $attendu): void
    {
        $action['type'] ??= Action::RETRAIT;
        $resultat = Action::appliquer($action, $this->document());
        self::assertArrayHasKey('document', $resultat);

        foreach ($attendu as $cle => $valeur) {
            self::assertSame($valeur, $resultat['document'][$cle], "attribut retiré « $cle »");
        }
    }

    public function testLesChampsCourrielDeWorkflowActionEmail(): void
    {
        $faux = new CourrielFaux();
        $action = [
            'type' => Action::COURRIEL,
            'email' => [
                'subject' => 'Notification {{title}}',
                'body' => 'Pièce {{filename}}',
                'to' => 'a@exemple.test, b@exemple.test',
                'include_document' => true,
            ],
        ];

        $resultat = Action::appliquer($action, $this->document(), $faux);

        self::assertCount(1, $faux->envoyes);
        self::assertSame(['a@exemple.test', 'b@exemple.test'], $faux->envoyes[0]['destinataires']);
        self::assertSame('Notification Facture AN-7001', $faux->envoyes[0]['sujet']);
        self::assertSame('Pièce facture-an-7001.pdf', $faux->envoyes[0]['corps']);
        self::assertTrue($faux->envoyes[0]['inclure_document']);
        self::assertSame(42, $faux->envoyes[0]['document_id']);
        self::assertContains('courriel', $resultat['effets']);
        self::assertSame('Notification Facture AN-7001', $resultat['courriel']['sujet']);
    }

    public function testLesChampsWebhookDeWorkflowActionWebhook(): void
    {
        $faux = new WebhookFaux();
        $action = [
            'type' => Action::WEBHOOK,
            'webhook' => [
                'url' => 'http://exemple.test/hook',
                'use_params' => true,
                'as_json' => true,
                'params' => ['titre' => '{{title}}'],
                'headers' => ['X-Test' => 'oui'],
                'include_document' => false,
            ],
        ];

        $resultat = Action::appliquer($action, $this->document(), null, $faux);

        self::assertCount(1, $faux->appels);
        self::assertSame('http://exemple.test/hook', $faux->appels[0]['url']);
        self::assertSame(['titre' => 'Facture AN-7001'], $faux->appels[0]['donnees']);
        self::assertTrue($faux->appels[0]['json']);
        self::assertSame(['X-Test' => 'oui'], $faux->appels[0]['entetes']);
        self::assertContains('webhook', $resultat['effets']);
    }

    public function testLeCorpsDeWebhookRemplaceLesParametres(): void
    {
        $faux = new WebhookFaux();
        $action = [
            'type' => Action::WEBHOOK,
            'webhook' => ['url' => 'http://exemple.test/hook', 'use_params' => false, 'body' => 'Corps {{title}}'],
        ];

        Action::appliquer($action, $this->document(), null, $faux);

        self::assertSame('Corps Facture AN-7001', $faux->appels[0]['donnees']);
        self::assertFalse($faux->appels[0]['json']);
    }

    public function testUnWebhookSansUrlEstRefuseEtNomme(): void
    {
        $this->expectException(\Ged\Workflows\ErreurWorkflow::class);
        $this->expectExceptionMessageMatches('/URL vide/');
        Action::appliquer(['type' => Action::WEBHOOK, 'webhook' => ['url' => '']], $this->document(), null, new WebhookFaux());
    }

    public function testLOrdreDesActionsEstPorte(): void
    {
        $premier = Action::appliquer(['type' => Action::AFFECTATION, 'order' => 2, 'assign_title' => 'Deux'], $this->document());
        $second = Action::appliquer(['type' => Action::AFFECTATION, 'order' => 1, 'assign_title' => 'Un'], $premier['document']);

        self::assertSame('Un', $second['document']['titre']);
    }

    public function testLesEnumerationsDeActionSontCellesDuModele(): void
    {
        self::assertSame([1, 2, 3, 4, 5], array_keys(Action::TYPES));
        self::assertSame('affectation', Action::TYPES[Action::AFFECTATION]);
        self::assertSame('retrait', Action::TYPES[Action::RETRAIT]);
        self::assertSame('courriel', Action::TYPES[Action::COURRIEL]);
        self::assertSame('webhook', Action::TYPES[Action::WEBHOOK]);
    }
}
