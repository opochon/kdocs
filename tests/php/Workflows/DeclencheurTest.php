<?php

declare(strict_types=1);

namespace Ged\Tests\Workflows;

use Ged\Classement\Correspondance;
use Ged\Workflows\Declencheur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Déclencheurs du moteur Paperless de GEDv2 : type, sources, filtres de chemin/nom/source/règle de
 * courrier, correspondance de contenu (les modes de `Ged\Classement\Correspondance`), étiquettes, type,
 * émetteur, dossier, requête de champs personnalisés et planification.
 *
 * Chaque cas porte le NOM du champ Paperless qu'il prouve : la correspondance de parité s'y adosse.
 */
final class DeclencheurTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(array $surcharge = []): array
    {
        return array_merge([
            'id' => 1,
            'titre' => 'Facture AN-7001',
            'type' => 'Facture',
            'emetteur' => 'Atelier Fictif Exemple Sàrl',
            'dossier' => '2026/Facture',
            'proprietaire' => 'alice',
            'etiquettes' => ['urgent'],
            'nom_fichier' => 'facture-an-7001.pdf',
            'chemin' => 'depot/facture-an-7001.pdf',
            'contenu' => 'Facture AN-7001 prestation fictive fournisseur',
            'source' => Declencheur::SOURCE_DOSSIER,
            'mailrule' => 3,
            'droits' => ['lecture' => ['utilisateurs' => [], 'groupes' => []],
                'modification' => ['utilisateurs' => [], 'groupes' => []]],
            'champs' => ['IBAN' => 'CH9300762011623852957'],
            'dates' => ['added' => '2026-04-01', 'created' => '2026-03-01', 'modified' => '2026-04-02'],
        ], $surcharge);
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>, bool}> */
    public static function filtresDeConsommation(): iterable
    {
        yield 'sources : présente' => [['sources' => [1, 2]], [], true];
        yield 'sources : absente' => [['sources' => [2, 3]], [], false];
        yield 'filter_mailrule : égale' => [['filter_mailrule' => 3], [], true];
        yield 'filter_mailrule : différente' => [['filter_mailrule' => 9], [], false];
        yield 'filter_filename : correspond' => [['filter_filename' => '*facture*'], [], true];
        yield 'filter_filename : ne correspond pas' => [['filter_filename' => '*foobar*'], [], false];
        yield 'filter_path : correspond' => [['filter_path' => 'depot/*'], [], true];
        yield 'filter_path : ne correspond pas' => [['filter_path' => '*foo/bar*'], [], false];
    }

    /** @param array<string,mixed> $trigger @param array<string,mixed> $surcharge */
    #[DataProvider('filtresDeConsommation')]
    public function testLesFiltresDeConsommation(array $trigger, array $surcharge, bool $attendu): void
    {
        self::assertSame($attendu, Declencheur::correspondConsommation($trigger, $this->document($surcharge)));
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>, bool}> */
    public static function correspondanceDeContenu(): iterable
    {
        yield 'MATCH_NONE : aucun filtre de contenu' => [['matching_algorithm' => Declencheur::MATCH_NONE, 'match' => 'facture'], [], true];
        yield 'MATCH_ANY : un mot' => [['matching_algorithm' => Declencheur::MATCH_ANY, 'match' => 'facture'], [], true];
        yield 'MATCH_ALL : tous les mots' => [['matching_algorithm' => Declencheur::MATCH_ALL, 'match' => 'facture prestation'], [], true];
        yield 'MATCH_ALL : un mot manque' => [['matching_algorithm' => Declencheur::MATCH_ALL, 'match' => 'facture absente'], [], false];
        yield 'MATCH_LITERAL : chaîne encadrée' => [['matching_algorithm' => Declencheur::MATCH_LITERAL, 'match' => 'prestation fictive'], [], true];
        yield 'MATCH_REGEX : expression' => [['matching_algorithm' => Declencheur::MATCH_REGEX, 'match' => 'AN-\\d{4}'], [], true];
        yield 'MATCH_FUZZY : proche' => [['matching_algorithm' => Declencheur::MATCH_FUZZY, 'match' => 'prestation fictive'], [], true];
        yield 'MATCH_AUTO : jamais en direct' => [['matching_algorithm' => Declencheur::MATCH_AUTO, 'match' => 'facture'], [], false];
        yield 'is_insensitive : casse ignorée' => [['matching_algorithm' => Declencheur::MATCH_LITERAL, 'match' => 'FACTURE AN-7001', 'is_insensitive' => true], [], true];
        yield 'sensible : casse respectée' => [['matching_algorithm' => Declencheur::MATCH_LITERAL, 'match' => 'FACTURE AN-7001', 'is_insensitive' => false], [], false];
    }

    /** @param array<string,mixed> $trigger @param array<string,mixed> $surcharge */
    #[DataProvider('correspondanceDeContenu')]
    public function testLesModesDeCorrespondanceDeContenu(array $trigger, array $surcharge, bool $attendu): void
    {
        self::assertSame($attendu, Declencheur::correspondExistant($trigger, $this->document($surcharge)));
    }

    /** @return iterable<string, array{array<string,mixed>, array<string,mixed>, bool}> */
    public static function filtresDExistence(): iterable
    {
        yield 'filter_has_tags : une présente' => [['filter_has_tags' => ['urgent', 'vip']], [], true];
        yield 'filter_has_tags : aucune' => [['filter_has_tags' => ['vip']], [], false];
        yield 'filter_has_all_tags : toutes' => [['filter_has_all_tags' => ['urgent']], [], true];
        yield 'filter_has_all_tags : une manque' => [['filter_has_all_tags' => ['urgent', 'vip']], [], false];
        yield 'filter_has_not_tags : exclue présente' => [['filter_has_not_tags' => ['urgent']], [], false];
        yield 'filter_has_not_tags : exclue absente' => [['filter_has_not_tags' => ['vip']], [], true];
        yield 'filter_has_correspondent : égale' => [['filter_has_correspondent' => 'Atelier Fictif Exemple Sàrl'], [], true];
        yield 'filter_has_correspondent : différente' => [['filter_has_correspondent' => 'Autre Émetteur'], [], false];
        yield 'filter_has_not_correspondents : exclu' => [['filter_has_not_correspondents' => ['Atelier Fictif Exemple Sàrl']], [], false];
        yield 'filter_has_document_type : égale' => [['filter_has_document_type' => 'Facture'], [], true];
        yield 'filter_has_not_document_types : exclu' => [['filter_has_not_document_types' => ['Facture']], [], false];
        yield 'filter_has_storage_path : égale' => [['filter_has_storage_path' => '2026/Facture'], [], true];
        yield 'filter_has_not_storage_paths : exclu' => [['filter_has_not_storage_paths' => ['2026/Facture']], [], false];
        yield 'filter_filename sur document existant' => [['filter_filename' => '*an-7001*'], [], true];
        yield 'filter_filename non concordant' => [['filter_filename' => '*autre*'], [], false];
    }

    /** @param array<string,mixed> $trigger @param array<string,mixed> $surcharge */
    #[DataProvider('filtresDExistence')]
    public function testLesFiltresDExistenceSurUnDocumentExistant(array $trigger, array $surcharge, bool $attendu): void
    {
        self::assertSame($attendu, Declencheur::correspondExistant($trigger, $this->document($surcharge)));
    }

    public function testLaRequeteDeChampsPersonnalises(): void
    {
        $document = $this->document();
        self::assertTrue(Declencheur::correspondExistant(['filter_custom_field_query' => 'IBAN=contient:CH93'], $document));
        self::assertFalse(Declencheur::correspondExistant(['filter_custom_field_query' => 'IBAN=egal:FR76'], $document));
        self::assertFalse(Declencheur::correspondExistant(['filter_custom_field_query' => 'n’importe quoi'], $document));
        self::assertTrue(Declencheur::correspondExistant(['filter_custom_field_query' => ''], $document));
    }

    public function testLaPlanificationSurLeChampDeDateEtLeDecalage(): void
    {
        $document = $this->document();
        $maintenant = new \DateTimeImmutable('2026-04-10');

        self::assertTrue(Declencheur::planifie(['schedule_date_field' => Declencheur::DATE_AJOUT], $document, $maintenant));
        self::assertFalse(Declencheur::planifie(['schedule_date_field' => Declencheur::DATE_AJOUT, 'schedule_offset_days' => 30], $document, $maintenant));
        self::assertTrue(Declencheur::planifie(['schedule_date_field' => Declencheur::DATE_AJOUT, 'schedule_offset_days' => -5], $document, $maintenant));
        self::assertTrue(Declencheur::planifie(['schedule_date_field' => Declencheur::DATE_CREATION], $document, $maintenant));
        self::assertTrue(Declencheur::planifie(['schedule_date_field' => Declencheur::DATE_MODIFICATION], $document, $maintenant));
        self::assertFalse(Declencheur::planifie([
            'schedule_date_field' => Declencheur::DATE_CHAMP, 'schedule_date_custom_field' => 'IBAN',
        ], $document, $maintenant), 'un champ non daté ne planifie pas');
        self::assertTrue(Declencheur::planifie([
            'schedule_date_field' => Declencheur::DATE_CHAMP, 'schedule_date_custom_field' => 'Échéance',
        ], $this->document(['champs' => ['Échéance' => '2026-03-15']]), $maintenant));
    }

    public function testLaRecurrenceRespecteLaDerniereExecution(): void
    {
        $trigger = ['schedule_date_field' => Declencheur::DATE_AJOUT, 'schedule_is_recurring' => true,
            'schedule_recurring_interval_days' => 7];
        $document = $this->document();
        $maintenant = new \DateTimeImmutable('2026-04-10');

        self::assertTrue(Declencheur::planifie($trigger, $document, $maintenant, null));
        self::assertFalse(Declencheur::planifie($trigger, $document, $maintenant, new \DateTimeImmutable('2026-04-08')));
        self::assertTrue(Declencheur::planifie($trigger, $document, $maintenant, new \DateTimeImmutable('2026-03-01')));
    }

    public function testLesEnumerationsSontCellesDuModelePaperless(): void
    {
        self::assertSame([1, 2, 3, 4], array_keys(Declencheur::TYPES));
        self::assertSame('consommation', Declencheur::TYPES[Declencheur::CONSOMMATION]);
        self::assertSame('document_ajoute', Declencheur::TYPES[Declencheur::AJOUT]);
        self::assertSame('document_mis_a_jour', Declencheur::TYPES[Declencheur::MISE_A_JOUR]);
        self::assertSame('planifie', Declencheur::TYPES[Declencheur::PLANIFIE]);
        self::assertSame([0, 1, 2, 3, 4, 5, 6], array_keys(Declencheur::MATCHING));
        self::assertSame('MATCH_ANY', Declencheur::MATCHING[Declencheur::MATCH_ANY]);
        self::assertSame('MATCH_LITERAL', Declencheur::MATCHING[Declencheur::MATCH_LITERAL]);
        self::assertSame([1, 2, 3, 4], array_keys(Declencheur::SOURCES));
        self::assertSame('consume_folder', Declencheur::SOURCES[Declencheur::SOURCE_DOSSIER]);
        self::assertSame('mail_fetch', Declencheur::SOURCES[Declencheur::SOURCE_COURRIEL]);
        self::assertSame(['added', 'created', 'modified', 'custom_field'], array_keys(Declencheur::DATES));
        // La correspondance délègue bien au service de ged-parite-comportement-1.
        self::assertTrue(Correspondance::correspond('MATCH_LITERAL', 'prestation fictive', 'Facture prestation fictive'));
    }
}
