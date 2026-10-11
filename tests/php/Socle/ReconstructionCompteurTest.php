<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Socle\DepotFichiers;
use Ged\Socle\ReconstructionIndex;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du lot ged-socle-reconstruction (constat du lot ged-personas) : le total annoncé par la
 * reconstruction d'index est EXACTEMENT le périmètre que `synchroniser()` relira. Un document vivant
 * de l'index dont le fichier a disparu du fonds n'est pas fondu dans le total : il est compté à part
 * et NOMMÉ. L'avancement atteint donc 100 % sans tranche fantôme.
 */
final class ReconstructionCompteurTest extends TestCase
{
    use SupportCorpus;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        SchemaRecherche::installer($this->pdo);   // le cache FULLTEXT de la reconstruction
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    /**
     * Un fonds de `$nombre` documents dont UN seul a son fichier retiré du disque (son `.meta.json` et
     * sa ligne d'index restent) : exactement le cas observé par le persona administrateur.
     *
     * @return array{total:int, absent:string} total = documents relisibles, absent = chemin du disparu
     */
    private function fondsAvecUnAbsent(int $nombre): array
    {
        $depot = new DepotFichiers($this->config);
        $identifiants = [];
        for ($i = 1; $i <= $nombre; $i++) {
            $identifiants[] = $depot->deposer($this->pieceADeposer("piece-$i.txt", "contenu synthétique $i"), [
                'titre' => "Pièce $i", 'type' => 'Facture', 'emetteur' => 'Atelier Fictif SA',
                'date' => '2026-10-11', 'texte' => "contenu synthétique $i", 'statut' => 'valide',
            ]);
        }

        $disparu = (string) $depot->fiche($identifiants[$nombre - 1])['chemin_relatif'];
        unlink($this->racine . '/fonds/' . $disparu);
        self::assertFileDoesNotExist($this->racine . '/fonds/' . $disparu, 'le fichier est bien absent du disque');
        self::assertFileExists($this->racine . '/fonds/' . $disparu . '.meta.json', 'ses métadonnées restent dans le fonds');

        return ['total' => $nombre - 1, 'absent' => $disparu];
    }

    public function testLeTotalAnnonceEstLeNombreDeDocumentsRelusEtLesAbsentsSontNommes(): void
    {
        $fonds = $this->fondsAvecUnAbsent(7);
        $job = new ReconstructionIndex($this->config);

        $lance = $job->lancer('admin@exemple.test');

        self::assertSame($fonds['total'], (int) $lance['total'],
            'le total annoncé est le nombre de documents qui seront relus, pas le nombre de lignes d’index');
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        self::assertCount((int) $lance['total'], $index->aRelire(),
            'le périmètre annoncé est celui-là même que la synchronisation parcourt');
        self::assertSame((int) $lance['total'], $index->synchroniser(),
            'une passe complète relit exactement le total annoncé');

        self::assertSame(1, (int) $lance['absents'], 'le document absent est compté à part');
        self::assertContains($fonds['absent'], (array) $lance['absents_noms'], 'le document absent est nommé, jamais tu');
    }

    public function testLesTranchesAtteignentCentPourCentSansResteFantome(): void
    {
        $fonds = $this->fondsAvecUnAbsent(7);
        $job = new ReconstructionIndex($this->config);
        $lance = $job->lancer('admin@exemple.test');
        $lot = 2;

        $etat = $lance;
        $tranches = 0;
        while (($etat['etat'] ?? '') === 'en_cours') {
            $etat = $job->avancer((int) $lance['id'], $lot);
            $tranches++;
            self::assertLessThanOrEqual((int) $lance['total'], (int) $etat['traite'], 'jamais plus que le total');
            self::assertLessThan(10, $tranches, 'la reconstruction ne doit pas boucler sur un reste fantôme');
        }

        self::assertSame('termine', $etat['etat']);
        self::assertSame((int) $lance['total'], (int) $etat['traite'], 'l’avancement atteint exactement 100 %');
        self::assertSame((int) ceil($fonds['total'] / $lot), $tranches,
            'une tranche par paquet du périmètre, aucune tranche supplémentaire pour un reste inexistant');
    }

    public function testUnFichierQuiDisparaitPendantLaReconstructionNeLaBloquePas(): void
    {
        // Constat du juge (11-10) : si un fichier du périmètre disparaît APRÈS le lancement, plus aucune
        // tranche ne relit rien et la tâche restait « en cours » pour toujours. Elle doit se terminer et
        // nommer l'écart.
        $this->fondsAvecUnAbsent(5);
        $job = new ReconstructionIndex($this->config);
        $lance = $job->lancer('admin@exemple.test');
        $index = IndexPleinTexte::depuis($this->config, $this->pdo);
        $premier = $index->aRelire()[0]->chemin;
        unlink($this->racine . '/fonds/' . $premier);

        $etat = $lance;
        $tranches = 0;
        while (($etat['etat'] ?? '') === 'en_cours') {
            $etat = $job->avancer((int) $lance['id'], 2);
            $tranches++;
            self::assertLessThan(10, $tranches, 'la reconstruction ne doit jamais rester bloquée en cours');
        }

        self::assertSame('termine', $etat['etat']);
        self::assertSame((int) $lance['total'] - 1, (int) $etat['traite'], 'les documents encore présents sont tous relus');
        self::assertSame(1, (int) $etat['ecart'], 'le document disparu en cours de route est compté');
    }
}