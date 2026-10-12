<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\GabaritChemin;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu du gabarit de nom de fichier (famille « gabarit » du lot ged-parite-chaine-1).
 *
 * Vecteurs extraits de src/documents/tests/test_file_handling.py (TestFilenameGeneration) : gabarit,
 * document, option « retirer none », archive → chemin relatif attendu. Les cas à expressions Jinja de
 * Paperless (`{% if %}`, arithmétique, `localize_date` multilingue) ne sont pas dans les vecteurs : ils
 * sont listés comme écarts assumés dans la note de lot, jamais contournés.
 */
final class ChaineGabaritTest extends TestCase
{
    public function testRejoueLesVecteursDuGabarit(): void
    {
        $donnees = Vecteurs::charger('chaine-gabarit');
        $echecs = [];
        $passes = 0;

        foreach ($donnees['vecteurs'] as $vecteur) {
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
            $document = is_array($entree['document'] ?? null) ? $entree['document'] : [];
            $gabarit = is_string($entree['gabarit'] ?? null) ? $entree['gabarit'] : null;
            $obtenu = GabaritChemin::chemin(
                $gabarit,
                $document,
                (bool) ($entree['retirer_none'] ?? false),
                (bool) ($entree['archive'] ?? false),
            );
            $attendu = (string) ($sortie['chemin'] ?? '');
            if ($obtenu === $attendu) {
                $passes++;
            } else {
                $echecs[] = sprintf(
                    '%s (%s) : attendu %s, obtenu %s',
                    (string) ($vecteur['id'] ?? '?'),
                    (string) ($vecteur['reference'] ?? ''),
                    var_export($attendu, true),
                    var_export($obtenu, true),
                );
            }
        }

        Vecteurs::ecrire('gabarit', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'gabarit',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
        ]);

        self::assertSame([], $echecs, "vecteurs de gabarit en écart :\n" . implode("\n", $echecs));
    }

    public function testContexteDeDatesEtDeMetadonnees(): void
    {
        $document = [
            'titre' => 'The Title',
            'mime' => 'application/pdf',
            'pk' => 2,
            'asn' => 25,
            'date_creation' => '1989-12-02',
            'date_ajout' => '1984-08-21 07:36:51',
            'etiquettes' => ['Bravo', 'Alpha'],
            'champs' => ['Facture' => ['type' => 'int', 'value' => 1234]],
        ];
        $contexte = GabaritChemin::contexte($document);
        self::assertSame('1989-12-02', $contexte['created']);
        self::assertSame('89', $contexte['created_year_short']);
        self::assertSame('December', $contexte['created_month_name']);
        self::assertSame('Dec', $contexte['created_month_name_short']);
        self::assertSame('1984-08-21 07:36:51', $contexte['added']);
        self::assertSame('84', $contexte['added_year_short']);
        self::assertSame('August', $contexte['added_month_name']);
        self::assertSame('25', $contexte['asn']);
        self::assertSame('0000002', $contexte['doc_pk']);
        // Étiquettes triées pour un nom stable, et champs personnalisés assainis.
        self::assertSame('Alpha,Bravo', $contexte['tag_list']);
        self::assertSame('1234', $contexte['custom_fields']['Facture']['value']);
        // Sans valeur : le placeholder vide, jamais une chaîne vide qui collerait deux segments.
        $vide = GabaritChemin::contexte(['titre' => 'x', 'pk' => 1]);
        self::assertSame(GabaritChemin::VIDE, $vide['correspondent']);
        self::assertSame(GabaritChemin::VIDE, $vide['asn']);
    }

    public function testCheminRelatifSurRefuseAbsoluEtRemontee(): void
    {
        self::assertTrue(GabaritChemin::cheminRelatifSur(''));
        self::assertTrue(GabaritChemin::cheminRelatifSur('a/b/c.pdf'));
        self::assertFalse(GabaritChemin::cheminRelatifSur('/etc/passwd'));
        self::assertFalse(GabaritChemin::cheminRelatifSur('C:/Windows/system32'));
        self::assertFalse(GabaritChemin::cheminRelatifSur('../../secret'));
    }

    public function testUnGabaritInvalideRetombeSurLeNomParDefaut(): void
    {
        $document = ['titre' => 'The Title', 'mime' => 'application/pdf', 'pk' => 2];
        // Placeholder inconnu : rendu impossible, nom par défaut (même comportement qu'un gabarit absent).
        self::assertSame('0000002.pdf', GabaritChemin::chemin('{{ inconnu }}/{{ titre }}', $document));
        self::assertSame('0000002.pdf', GabaritChemin::chemin(null, $document));
        // Un gabarit qui produirait un chemin absolu est refusé de même.
        self::assertSame('0000002.pdf', GabaritChemin::chemin('/absolu/{{ titre }}', $document));
    }

    public function testLeDocumentTemoinPorteTousLesPlaceholders(): void
    {
        $temoin = GabaritChemin::documentTemoin();
        foreach (['titre', 'correspondant', 'type_document', 'asn', 'proprietaire', 'nom_origine',
            'date_creation', 'date_ajout', 'etiquettes', 'champs'] as $cle) {
            self::assertArrayHasKey($cle, $temoin, 'le document témoin doit porter ' . $cle);
        }
        $contexte = GabaritChemin::contexte($temoin);
        self::assertSame('sample-user', $contexte['owner_username']);
        self::assertSame('original_file', $contexte['original_name']);
        self::assertSame('Sample Correspondent', $contexte['correspondent']);
    }
}
