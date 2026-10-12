<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Ingestion\DechiffreurPdf;
use Ged\Kernel;
use Ged\Securite\PermissionsTaches;
use Ged\Securite\PurgeAudit;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « commandes et droits » du lot ged-parite-final-1 : déchiffrement des PDF (`decrypt_documents`),
 * purge du journal d'audit par rétention (`prune_audit_logs`), droit d'acquittement des tâches
 * (`AcknowledgeTasksPermissions`) et statut temps réel (`ws/status/` → flux SSE léger).
 */
final class FinalCommandesTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'final-cmd-%'");
        $this->tearDownJonctions();
    }

    public function testLeDechiffrementNommeLOutilAbsentPuisDechiffreAvecUnOutilExterne(): void
    {
        $pdf = $this->racine . '/chiffre.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Encrypt 2 0 R >>\n%%EOF");
        self::assertTrue(DechiffreurPdf::estPdf($pdf));
        self::assertTrue(DechiffreurPdf::estChiffre($pdf));

        // Sans outil configuré : refus NOMMÉ, le fichier n'est pas touché.
        $avant = (string) file_get_contents($pdf);
        $sansOutil = new DechiffreurPdf();
        self::assertFalse($sansOutil->disponible());
        self::assertSame(DechiffreurPdf::RAISON_OUTIL, $sansOutil->dechiffrer($pdf));
        self::assertSame($avant, (string) file_get_contents($pdf));

        // Un outil EXTERNE (ici un faux déchiffreur PHP) produit un PDF en clair : succès, fichier remplacé.
        $outil = $this->racine . '/faux-dechiffreur.php';
        file_put_contents($outil, "<?php\n\$f = \$argv[1];\nfile_put_contents(\$f, \"%PDF-1.4\\n1 0 obj << /Type /Catalog >>\\n%%EOF\");\n");
        $dechiffreur = new DechiffreurPdf([PHP_BINARY, $outil]);
        self::assertTrue($dechiffreur->disponible());
        self::assertNull($dechiffreur->dechiffrer($pdf), 'l’outil a produit un PDF en clair');
        self::assertFalse(DechiffreurPdf::estChiffre($pdf));

        // Le PDF en clair n'est plus « à déchiffrer » : la commande est idempotente.
        self::assertSame(DechiffreurPdf::RAISON_PAS_CHIFFRE, $dechiffreur->dechiffrer($pdf));
    }

    public function testLaPurgeDuJournalConserveLesEntreesRecentEtLesIllisibles(): void
    {
        $journal = $this->racine . '/audit.ndjson';
        $maintenant = 1_700_000_000;
        $lignes = [
            json_encode(['horodatage' => gmdate('c', $maintenant - 40 * 86400), 'action' => 'vieux', 'utilisateur' => 'a', 'contexte' => []]),
            json_encode(['horodatage' => gmdate('c', $maintenant - 2 * 86400), 'action' => 'recent', 'utilisateur' => 'a', 'contexte' => []]),
            'ligne-illisible-sans-json',
        ];
        file_put_contents($journal, implode(PHP_EOL, $lignes) . PHP_EOL);

        $bilan = (new PurgeAudit())->purger($journal, 30, $maintenant);
        self::assertSame(['avant' => 3, 'apres' => 2, 'supprimes' => 1], $bilan);
        $restant = (string) file_get_contents($journal);
        self::assertStringNotContainsString('vieux', $restant);
        self::assertStringContainsString('recent', $restant);
        self::assertStringContainsString('ligne-illisible-sans-json', $restant, 'une ligne illisible est conservée');
    }

    public function testLAcquittementDesTachesEstReserveAuxAdministrateursEtLeStatutEstUnSse(): void
    {
        self::assertTrue(PermissionsTaches::peutAcquitter(true));
        self::assertFalse(PermissionsTaches::peutAcquitter(false));
        self::assertTrue(PermissionsTaches::peutAcquitter(false, [PermissionsTaches::ACQUITTER]));

        $app = Kernel::creer(dirname(__DIR__, 3));
        $gestionnaire = $this->compteFinal('gestionnaire');
        $jetonGestion = $this->jetonDocuments($gestionnaire['compte'], $gestionnaire['identifiant'], 'documents_ecriture');
        $admin = $this->compteFinal('administrateur');
        $jetonAdmin = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');

        // Un gestionnaire n'acquitte pas : refus nommé 403 (PermissionsTaches).
        [$refus, $corps] = $this->appel($app, 'POST', '/api/v1/taches/acknowledge', $jetonGestion, ['tasks' => [1]]);
        self::assertSame(403, $refus);
        self::assertArrayHasKey('erreur', $corps);

        // L'administrateur acquitte ; sans tâche listée, il se nomme 400.
        [$vide] = $this->appel($app, 'POST', '/api/v1/taches/acknowledge', $jetonAdmin, ['tasks' => []]);
        self::assertSame(400, $vide);

        // Le statut temps réel est un flux SSE : un événement, puis fermeture (appel court).
        $requete = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/api/v1/taches/statut/evenements')
            ->withHeader('Authorization', 'Bearer ' . $jetonAdmin);
        $reponse = $app->handle($requete);
        self::assertSame(200, $reponse->getStatusCode());
        self::assertStringContainsString('text/event-stream', $reponse->getHeaderLine('Content-Type'));
        self::assertStringContainsString('event: statut', (string) $reponse->getBody());

        // Sans jeton, le flux refuse et se nomme.
        [$sans] = $this->appel($app, 'GET', '/api/v1/taches/statut/evenements');
        self::assertSame(401, $sans);
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteFinal(string $role): array
    {
        $identifiant = 'final-cmd-' . $role . '-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), $role]);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}
