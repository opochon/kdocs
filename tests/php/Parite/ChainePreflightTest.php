<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\ControleAvant;
use Ged\Ingestion\ErreurConsommation;
use Ged\Ingestion\GabaritTitre;
use Ged\Ingestion\MessageStatut;
use Ged\Ingestion\Surcharges;
use PHPUnit\Framework\TestCase;

/**
 * Famille « preflight et surcharges » du lot ged-parite-chaine-1.
 *
 * test_consumer.py est un test d'intégration Django/Celery (base, mocks de tâches, fichiers temporaires) :
 * il ne fournit pas de couples {entree, sortie} purs. Ces cas sont donc listés dans la note de lot, et les
 * équivalents GEDv2 sont prouvés directement ici : erreur nommée, messages de statut, contrôles avant
 * consommation (fichier, doublon, dossiers, numéro d'archive), surcharges de métadonnées et placeholders
 * de titre.
 */
final class ChainePreflightTest extends TestCase
{
    public function testErreurDeConsommationNommeLeFichierEtLaCause(): void
    {
        $erreur = new ErreurConsommation('facture.pdf', 'fichier introuvable');
        self::assertSame('facture.pdf : fichier introuvable', $erreur->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $erreur);
    }

    public function testMessagesDeStatutSontCeuxDePaperless(): void
    {
        self::assertSame('document_already_exists', MessageStatut::DOCUMENT_ALREADY_EXISTS);
        self::assertSame('asn_already_exists', MessageStatut::ASN_ALREADY_EXISTS);
        self::assertSame('asn_value_out_of_range', MessageStatut::ASN_RANGE);
        self::assertSame('file_not_found', MessageStatut::FILE_NOT_FOUND);
        self::assertSame('new_file', MessageStatut::NEW_FILE);
        self::assertSame('finished', MessageStatut::FINISHED);
        self::assertCount(14, MessageStatut::tous());
        self::assertSame(MessageStatut::tous(), array_values(array_unique(MessageStatut::tous())));
    }

    public function testEtapeNommeEchoueAvecLeFichier(): void
    {
        $etape = new ControleAvant('courrier.eml');
        self::assertSame('courrier.eml', $etape->nomFichier());
        self::assertSame(
            ['statut' => MessageStatut::NEW_FILE, 'message' => MessageStatut::NEW_FILE, 'courant' => 0, 'total' => 100],
            $etape->progression(0, 100, MessageStatut::NEW_FILE),
        );
        $this->expectException(ErreurConsommation::class);
        $this->expectExceptionMessage('courrier.eml : panne (failed)');
        $etape->echouer(MessageStatut::FAILED, 'panne');
    }

    public function testSurchargesNappliquentQueCeQuiEstFourni(): void
    {
        $document = ['titre' => 'deviné', 'correspondant' => 'Ancien', 'etiquettes' => ['a'], 'statut' => 'a_controler'];
        $resultat = Surcharges::appliquer($document, [
            'correspondant' => 'Nouveau',
            'etiquettes' => ['b', 'a'],
            'asn' => 123,
        ]);
        self::assertSame('Nouveau', $resultat['correspondant']);
        self::assertSame(['a', 'b'], $resultat['etiquettes'], 'les étiquettes s’ajoutent sans doublon');
        self::assertSame(123, $resultat['asn']);
        self::assertSame('deviné', $resultat['titre'], 'une surcharge absente n’efface rien');
        self::assertSame('a_controler', $resultat['statut']);
        // Une valeur nulle ne surcharge pas non plus.
        $resultat2 = Surcharges::appliquer($document, ['correspondant' => null]);
        self::assertSame('Ancien', $resultat2['correspondant']);
    }

    public function testControleDesNumerosArchive(): void
    {
        $etape = new ControleAvant('x.pdf');
        // Valide : rien ne se passe.
        $etape->verifierAsn(null);
        $etape->verifierAsn(0);
        $etape->verifierAsn(ControleAvant::ASN_MAX);
        self::assertTrue(true, 'un numéro absent ou dans les bornes est accepté');

        try {
            $etape->verifierAsn(-1);
            self::fail('un ASN négatif doit être refusé');
        } catch (ErreurConsommation $e) {
            self::assertStringContainsString(MessageStatut::ASN_RANGE, $e->getMessage());
            self::assertStringContainsString('x.pdf', $e->getMessage());
        }

        try {
            $etape->verifierAsn(123, 123);
            self::fail('un ASN déjà pris doit être refusé');
        } catch (ErreurConsommation $e) {
            self::assertStringContainsString(MessageStatut::ASN_ALREADY_EXISTS, $e->getMessage());
        }

        try {
            $etape->verifierAsn(123, 123, true);
            self::fail('un ASN de la corbeille doit être nommé');
        } catch (ErreurConsommation $e) {
            self::assertStringContainsString(MessageStatut::ASN_ALREADY_EXISTS_IN_TRASH, $e->getMessage());
        }
    }

    public function testControleFichierEtDoublon(): void
    {
        $etape = new ControleAvant('x.pdf');
        $this->expectException(ErreurConsommation::class);
        $this->expectExceptionMessage('x.pdf : fichier introuvable');
        $etape->verifierFichier('C:/nulle-part/x.pdf');
    }

    public function testDoublonDeLaCorbeilleEstNomme(): void
    {
        $etape = new ControleAvant('x.pdf');
        try {
            $etape->verifierDoublon('abc', 'abc', 'Facture', true);
            self::fail('un doublon doit être refusé');
        } catch (ErreurConsommation $e) {
            self::assertStringContainsString(MessageStatut::DOCUMENT_ALREADY_EXISTS_IN_TRASH, $e->getMessage());
            self::assertStringContainsString('Facture', $e->getMessage());
        }
        $etape->verifierDoublon('abc', null, '', false);
        self::assertTrue(true, 'sans doublon, le contrôle passe');
    }

    public function testExecutionDuControleRendLesEtapes(): void
    {
        $fichier = (string) tempnam(sys_get_temp_dir(), 'gedv2_');
        $dossier = sys_get_temp_dir() . '/gedv2_controle_' . bin2hex(random_bytes(4));
        try {
            $etape = new ControleAvant(basename($fichier));
            $evenements = $etape->executer([
                'fichier' => $fichier,
                'empreinte' => 'abc',
                'empreinte_existante' => null,
                'asn' => 7,
                'dossiers' => [$dossier],
            ]);
            self::assertSame(MessageStatut::NEW_FILE, $evenements[0]['statut']);
            self::assertSame(MessageStatut::FINISHED, $evenements[array_key_last($evenements)]['statut']);
            self::assertDirectoryExists($dossier);
        } finally {
            @unlink($fichier);
            if (is_dir($dossier)) {
                @rmdir($dossier);
            }
        }
    }

    public function testTitreAPlaceholders(): void
    {
        $document = [
            'titre' => 'x',
            'correspondant' => 'Acme',
            'date_creation' => '2020-06-25',
            'date_ajout' => '2024-10-01 07:36:51',
            'nom_origine' => 'scan.pdf',
        ];
        self::assertSame('Acme-2024', GabaritTitre::rendreTitre('{{ correspondent }}-{{ added_year }}', $document));
        self::assertSame(
            'Acme / 01-10-2024',
            GabaritTitre::rendreTitre('{{ correspondent }} / {{ added | datetime(\'%d-%m-%Y\') }}', $document),
        );
        // Placeholder inconnu : null, pour que l'appelant retombe sur le titre d'origine.
        self::assertNull(GabaritTitre::rendreTitre('{{ inconnu }}-{{ correspondent }}', $document));
    }
}
