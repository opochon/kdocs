<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\DepotFichiers;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « mise à jour d'un lien de partage » du lot ged-parite-api-4.
 *
 * Vecteurs transcrits du contrat DRF de `ShareLinkViewSet` (Paperless-ngx v2.20.5 n'a pas de test
 * d'update ; `src/documents/views.py:2602` est un ModelViewSet, PUT/PATCH rendent 200). Côté GEDv2,
 * `PUT/PATCH /api/v1/partages/{id}` modifie l'échéance sans révoquer le lien ; une date passée est
 * refusée par un 400 nommé.
 */
final class ApiQuatrePartageTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'api4-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeMiseAJourDUnLienDePartage(): void
    {
        $donnees = Vecteurs::charger('api-4-partage');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');
        $document = $this->deposer($compte['ecriture'], 'Partage à rééchelonner');
        [$creation, $partage] = $this->appel($app, 'POST', '/api/v1/partages', $jeton, ['document' => $document]);
        self::assertSame(201, $creation);
        $id = (int) $partage['id'];

        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $idVecteur = (string) ($vecteur['id'] ?? '?');
            $methode = (string) ($vecteur['entree']['methode'] ?? 'PATCH');
            $future = (new \DateTimeImmutable('+15 days'))->format('Y-m-d');
            [$statut, $corps] = $this->appel($app, $methode, "/api/v1/partages/$id", $jeton, ['expiration' => $future]);
            if ($statut === 200 && ($corps['expiration'] ?? null) !== null) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu 200, obtenu %d', $idVecteur,
                    (string) ($vecteur['reference'] ?? ''), $statut);
            }
        }

        Vecteurs::ecrireApi('4-partage', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => '4-partage',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => 0,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de partage en écart :\n" . implode("\n", $echecs));

        // Une échéance passée est refusée par un 400 nommé ; le lien reste inchangé.
        [$refus, $corpsRefus] = $this->appel($app, 'PATCH', "/api/v1/partages/$id", $jeton, ['expiration' => '2000-01-01']);
        self::assertSame(400, $refus);
        self::assertSame('expiration future obligatoire (AAAA-MM-JJ)', $corpsRefus['erreur']['cause'] ?? null);

        // Non-accès : sans jeton, la modification refuse et se nomme.
        self::assertSame(401, $this->appel($app, 'PATCH', "/api/v1/partages/$id", null, ['expiration' => '2030-01-01'])[0]);
    }

    private function deposer(string $dossier, string $titre): int
    {
        return (new DepotFichiers($this->config()))->deposer($this->piece($titre . '.pdf', $titre),
            ['titre' => $titre, 'dossier' => $dossier, 'emetteur' => 'Émetteur ' . $titre]);
    }
}
