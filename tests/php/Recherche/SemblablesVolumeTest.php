<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Semblables;
use PHPUnit\Framework\TestCase;

/**
 * Budget de temps des documents semblables (même exigence que PERF-2) : sur un index de 10 000 documents
 * synthétiques, la requête « semblables » d'une facture — y compris le filtrage fin et l'ACL — doit tenir
 * sous une seconde. Mesure prise après une première requête (chauffe du plan FULLTEXT), comme le ferait un
 * écran déjà visité ; la mesure exacte est portée par le message d'assertion.
 *
 * Aucune donnée réelle : les lignes sont insérées dans la table d'index du secteur (reconstruite par
 * `SupportRecherche`), jamais dans une autre base.
 */
final class SemblablesVolumeTest extends TestCase
{
    use SupportRecherche;

    private const DOCUMENTS = 10000;
    private const SEUIL_MS = 1000.0;
    private const LOT = 200;
    /** @var list<string> */
    private const SUJETS = [
        'maintenance des postes de travail', 'livraison de fournitures de bureau', 'contrat de service annuel',
        'rapport annuel d activite', 'facture energie du site', 'attestation de travail interne',
    ];
    /** @var list<string> */
    private const TYPES = ['Facture', 'Contrat', 'Lettre', 'Devis'];

    protected function setUp(): void
    {
        $this->setUpRecherche();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecherche();
    }

    public function testLesSemblablesTiennentUneSecondeSurDixMilleDocuments(): void
    {
        $this->peuplerIndex(self::DOCUMENTS);
        self::assertSame(self::DOCUMENTS, (int) $this->pdo->query('SELECT COUNT(*) FROM recherche_textes')->fetchColumn());

        $moteur = new Semblables($this->pdo);
        $moteur->pour(1, 20);   // chauffe : plan de requête et cache FULLTEXT

        $debut = microtime(true);
        $resultat = $moteur->pour(1, 20);
        $millisecondes = (microtime(true) - $debut) * 1000.0;

        $ids = array_map(static fn ($t): int => $t->id, $resultat->trouvailles);
        self::assertNull($resultat->erreur?->cause);
        self::assertContains(2, $ids, 'la facture sœur du même émetteur est un semblant');
        self::assertSame(2, $ids[0], 'elle passe en tête');
        self::assertNotContains(1, $ids, 'la source est exclue');
        self::assertLessThan(self::SEUIL_MS, $millisecondes, sprintf(
            'documents semblables sur %d documents : %.0f ms (< %.0f ms)', self::DOCUMENTS, $millisecondes, self::SEUIL_MS));
    }

    /** Insère `$total` lignes d'index synthétiques, par lots bornés, sans passer par le fonds. */
    private function peuplerIndex(int $total): void
    {
        $lignes = [];
        for ($i = 1; $i <= $total; $i++) {
            $lignes[] = $this->ligne($i);
        }
        foreach (array_chunk($lignes, self::LOT) as $lot) {
            $valeurs = implode(', ', array_fill(0, count($lot), '(?, ?, ?, ?, ?, ?, ?, ?)'));
            $this->pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, contenu, signature)'
                . ' VALUES ' . $valeurs)->execute(array_merge(...$lot));
        }
    }

    /** @return list<string|int|null> */
    private function ligne(int $i): array
    {
        if ($i === 1) {
            return $this->valeurs($i, 'Facture F-0001 — Atelier Horizon', 'Facture', 'Atelier Horizon SA',
                'Facture F-0001. Maintenance des postes de travail du site principal. Total TTC 1 240.00 CHF.');
        }
        if ($i === 2) {
            return $this->valeurs($i, 'Facture F-0002 — Atelier Horizon', 'Facture', 'Atelier Horizon SA',
                'Facture F-0002. Maintenance annuelle des postes de travail. Total TTC 980.00 CHF.');
        }
        $emetteur = 'Fournisseur ' . ($i % 250);
        $type = self::TYPES[$i % count(self::TYPES)];

        return $this->valeurs($i, $type . ' ' . str_pad((string) $i, 5, '0', STR_PAD_LEFT) . ' — ' . $emetteur, $type, $emetteur,
            'Pièce ' . $i . '. ' . self::SUJETS[$i % count(self::SUJETS)] . '. Total TTC '
            . number_format(100 + ($i % 9000) / 100, 2, '.', '') . ' CHF.');
    }

    /** @return list<string|int|null> */
    private function valeurs(int $id, string $titre, string $type, string $emetteur, string $contenu): array
    {
        $chemin = 'charge/semblables-' . $id . '.txt';

        return [hash('sha256', $chemin), $chemin, $id, $titre, $type, $emetteur, $contenu, hash('sha256', $chemin)];
    }
}
