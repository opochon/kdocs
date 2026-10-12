<?php

declare(strict_types=1);

namespace Ged\Tests\Ingestion\Courrier;

use Ged\Ingestion\Courrier\Compte;
use Ged\Ingestion\Courrier\ErreurCourrier;
use Ged\Ingestion\Courrier\Filtres;
use Ged\Ingestion\Courrier\Message;
use Ged\Ingestion\Courrier\Piece;
use Ged\Ingestion\Courrier\Regle;
use PHPUnit\Framework\TestCase;

/**
 * Règles et filtres : les énumérations du modèle Paperless existent toutes, la validation refuse une règle
 * incomplète, la règle se relit à l'identique, et les filtres (expéditeur / destinataire / objet / corps /
 * pièces / âge / état d'action) tranchent comme Paperless.
 */
final class RegleTest extends TestCase
{
    public function testToutesLesEnumerationsDuModeleExistent(): void
    {
        self::assertSame([1, 2, 3], [Compte::SECURITE_NON, Compte::SECURITE_SSL, Compte::SECURITE_STARTTLS]);
        self::assertSame([1, 2, 3], [Compte::TYPE_IMAP, Compte::TYPE_GMAIL_OAUTH, Compte::TYPE_OUTLOOK_OAUTH]);
        self::assertSame([1, 2, 3, 4, 5], array_keys(Regle::TYPES_ACTION));
        self::assertSame([1, 2, 3], array_keys(Regle::TYPES_TITRE));
        self::assertSame([1, 2, 3, 4], array_keys(Regle::TYPES_EMETTEUR));
        self::assertSame([1, 2], array_keys(Regle::TYPES_PIECES));
        self::assertSame([1, 2, 3], array_keys(Regle::PORTEES));
        self::assertSame([0, 1, 2, 3, 4], array_keys(Regle::MISES_EN_PAGE));
        self::assertSame(Regle::TITRE_OBJET, Regle::TITRE_OBJET);
        self::assertSame(4, Regle::PIECES_JOINTES + Regle::PORTEE_TOUT);
    }

    public function testLaValidationExigeUnParametrePourEtiqueterOuDeplacer(): void
    {
        foreach ([Regle::ACTION_ETIQUETER, Regle::ACTION_DEPLACER] as $action) {
            $regle = Regle::depuisTableau(['nom' => 'r', 'action' => $action, 'parametre_action' => '']);
            try {
                $regle->valider();
                self::fail('règle sans paramètre acceptée');
            } catch (ErreurCourrier) {
                self::assertTrue(true);
            }
        }

        Regle::depuisTableau(['nom' => 'r', 'action' => Regle::ACTION_ETIQUETER, 'parametre_action' => 'traité'])->valider();
        self::assertTrue(true);
    }

    public function testLaValidationRefuseUnNomVideEtUnAgeDeraisonnable(): void
    {
        $this->expectException(ErreurCourrier::class);
        Regle::depuisTableau(['nom' => '', 'action' => Regle::ACTION_MARQUER_LU])->valider();
    }

    public function testLaValidationRefuseUnAgeDePlusDeCentAns(): void
    {
        $this->expectException(ErreurCourrier::class);
        Regle::depuisTableau(['nom' => 'r', 'age_max' => 36501])->valider();
    }

    public function testUneRegleSeRelitALEIdentique(): void
    {
        $regle = Regle::depuisTableau([
            'id' => 3, 'nom' => 'Factures', 'ordre' => 2, 'compte' => 'Boîte', 'actif' => true,
            'dossier' => 'INBOX', 'filtre_de' => 'amazon', 'filtre_a' => 'moi@exemple.test',
            'filtre_objet' => 'Invoice', 'filtre_corps' => 'cables', 'filtre_fichiers_inclure' => '*.pdf',
            'filtre_fichiers_exclure' => 'logo*', 'age_max' => 15, 'type_piece' => Regle::PIECES_TOUT,
            'portee' => Regle::PORTEE_TOUT, 'mise_en_page_pdf' => Regle::PDF_HTML, 'action' => Regle::ACTION_DEPLACER,
            'parametre_action' => 'spam', 'titre_depuis' => Regle::TITRE_FICHIER, 'etiquettes' => ['facture', 'traité'],
            'type_document' => 'Facture', 'emetteur_depuis' => Regle::EMETTEUR_COURRIEL, 'emetteur_choisi' => '',
            'proprietaire_depuis_regle' => false,
        ]);

        self::assertSame($regle->pourTableau(), Regle::depuisTableau($regle->pourTableau())->pourTableau());
    }

    public function testLesFiltresTranschentCommePaperless(): void
    {
        $message = new Message(1, 'Invoice 1', 'amazon@amazon.de', 'Amazon', ['me@exemple.test'],
            'cables', new \DateTimeImmutable('2026-04-10'), '', [], false, false, []);
        $maintenant = new \DateTimeImmutable('2026-04-10');

        self::assertTrue(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r']), $maintenant));
        self::assertTrue(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_objet' => 'Invoice']), $maintenant));
        self::assertFalse(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_objet' => 'Claim']), $maintenant));
        self::assertTrue(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_de' => 'amazon']), $maintenant));
        self::assertTrue(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_corps' => 'cables']), $maintenant));
        self::assertTrue(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_a' => 'me@exemple']), $maintenant));
        self::assertFalse(Filtres::messageRetenu($message, Regle::depuisTableau(['nom' => 'r', 'filtre_a' => 'autre@']), $maintenant));
        // Âge : un message de plus de 30 jours est écarté par le défaut Paperless.
        self::assertFalse(Filtres::messageRetenu(
            new Message(1, '', '', '', [], '', new \DateTimeImmutable('2026-01-01'), '', [], false, false, []),
            Regle::depuisTableau(['nom' => 'r']),
            $maintenant,
        ));
    }

    public function testLesFiltresDEtatDEcarterUnMessageDejaTraite(): void
    {
        $maintenant = new \DateTimeImmutable('2026-04-10');
        $lu = new Message(1, '', '', '', [], '', $maintenant, '', [], true, false, []);
        $signale = new Message(2, '', '', '', [], '', $maintenant, '', [], false, true, []);
        $etiquete = new Message(3, '', '', '', [], '', $maintenant, '', [], false, false, ['traité']);

        self::assertFalse(Filtres::retenuParAction($lu, Regle::depuisTableau(['nom' => 'r', 'action' => Regle::ACTION_MARQUER_LU])));
        self::assertFalse(Filtres::retenuParAction($signale, Regle::depuisTableau(['nom' => 'r', 'action' => Regle::ACTION_DRAPEAU])));
        self::assertFalse(Filtres::retenuParAction($etiquete, Regle::depuisTableau(['nom' => 'r', 'action' => Regle::ACTION_ETIQUETER, 'parametre_action' => 'traité'])));
        self::assertTrue(Filtres::retenuParAction($lu, Regle::depuisTableau(['nom' => 'r', 'action' => Regle::ACTION_DRAPEAU])));
    }

    public function testLesPiecesSontFiltreesParNomDispositionEtType(): void
    {
        $regle = Regle::depuisTableau(['nom' => 'r', 'filtre_fichiers_inclure' => '*.pdf',
            'filtre_fichiers_exclure' => 'f1*', 'type_piece' => Regle::PIECES_JOINTES]);

        self::assertFalse(Filtres::pieceRetenue(new Piece('f1.pdf', 'application/pdf', 'attachment', '%PDF'), $regle));
        self::assertTrue(Filtres::pieceRetenue(new Piece('f2.pdf', 'application/pdf', 'attachment', '%PDF'), $regle));
        self::assertFalse(Filtres::pieceRetenue(new Piece('f3.json', 'application/json', 'attachment', '{}'), $regle));
        self::assertFalse(Filtres::pieceRetenue(new Piece('f3.pdf', 'application/pdf', 'inline', '%PDF'), $regle));
        // Un type inconnu reste écarté même si le nom correspond.
        self::assertFalse(Filtres::pieceSupportee(new Piece('f2.json', 'application/json', 'attachment', '{}')));

        $tout = Regle::depuisTableau(['nom' => 'r', 'type_piece' => Regle::PIECES_TOUT]);
        self::assertTrue(Filtres::pieceRetenue(new Piece('f3.pdf', 'application/pdf', 'inline', '%PDF'), $tout));
    }
}
