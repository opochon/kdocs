<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Vues;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-04 §3 : une vue enregistrée est un FILTRE TYPÉ COMPILÉ (type, émetteur, période,
 * étiquette, champ), jamais un déplacement de fichier. La vue « Contrats à échéance < 90 j »
 * est l'exemple de porte : un champ date, un opérateur relatif, aucun fichier ne bouge.
 */
final class VuesTest extends TestCase
{
    use SupportCorpus;

    private Vues $vues;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        (new Nomenclature($this->config))->assurer();
        (new Champs($this->config))->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        $this->vues = new Vues($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    private function idDuType(string $nom): int
    {
        foreach ((new Nomenclature($this->config))->lister() as $t) {
            if ($t['nom'] === $nom) {
                return (int) $t['id'];
            }
        }
        self::fail("type « $nom » absent de la nomenclature");
    }

    /** @param array<string, mixed> $supplements */
    private function doc(string $titre, string $type, array $supplements = []): array
    {
        return array_merge([
            'id' => 0, 'chemin' => '2026/' . strtolower($type) . '/x.pdf', 'nom' => 'x.pdf', 'titre' => $titre,
            'type' => $type, 'emetteur' => null, 'date' => '2026-09-01', 'montant' => null, 'etiquettes' => [],
            'champs' => [],
        ], $supplements);
    }

    public function testCompilerDesFiltresTypés(): void
    {
        $filtres = $this->vues->compiler([
            'type' => 'Contrat', 'emetteur' => 'Atelier Modele Sàrl', 'de' => '2026-01-01', 'a' => '2026-12-31',
            'etiquette' => 'bureau', 'champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => '90',
        ]);
        self::assertSame('Contrat', $filtres['type']);
        self::assertSame('Atelier Modele Sàrl', $filtres['emetteur']);
        self::assertSame('2026-01-01', $filtres['de']);
        self::assertSame('2026-12-31', $filtres['a']);
        self::assertSame(['bureau'], $filtres['etiquettes']);
        self::assertSame(['cle' => 'echeance', 'op' => 'avant_jours', 'valeur' => 90], $filtres['champ'],
            'l’opérateur relatif est compilé en entier (90 jours), pas laissé en chaîne');
    }

    public function testCompilerIgnoreLesFiltresVides(): void
    {
        $filtres = $this->vues->compiler(['type' => '', 'emetteur' => null, 'champ_cle' => '', 'champ_valeur' => '  ']);
        self::assertNull($filtres['type']);
        self::assertNull($filtres['champ']);
        self::assertSame([], $filtres['etiquettes']);
    }

    /** @return list<array<string, array{0:string, 1:string}>> */
    public static function incoherences(): array
    {
        return [
            'période : date impossible' => [['de' => '2026-13-01'], 'de'],
            'période : date non ISO' => [['a' => '31.12.2026'], 'a'],
            'champ inconnu' => [['champ_cle' => 'inconnu', 'champ_op' => 'egal', 'champ_valeur' => 'x'], 'champ'],
            'opérateur inadapté à la nature' => [['champ_cle' => 'echeance', 'champ_op' => 'contient', 'champ_valeur' => 'mars'], 'opérateur'],
            'jours non numériques' => [['champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => 'bientôt'], 'jours'],
            'jours négatifs' => [['champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => '-5'], 'jours'],
            'valeur de date du champ invalide' => [['champ_cle' => 'echeance', 'champ_op' => 'avant', 'champ_valeur' => '32.13.2026'], 'date'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incoherences')]
    public function testCompilerRefuseEtNomme(array $brut, string $motAttendu): void
    {
        try {
            $this->vues->compiler($brut);
            self::fail('ces filtres incohérents devaient être refusés : ' . json_encode($brut));
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase($motAttendu, $e->getMessage(), 'l’erreur se nomme');
        }
    }

    public function testContratsÀÉchéanceMoinsDe90Jours(): void
    {
        $proche = $this->doc('Contrat proche', 'Contrat', ['champs' => ['echeance' => date('Y-m-d', time() + 30 * 86400)]]);
        $limite = $this->doc('Contrat à la limite', 'Contrat', ['champs' => ['echeance' => date('Y-m-d', time() + 89 * 86400)]]);
        $loin = $this->doc('Contrat loin', 'Contrat', ['champs' => ['echeance' => date('Y-m-d', time() + 400 * 86400)]]);
        $sansValeur = $this->doc('Contrat sans échéance', 'Contrat');
        $facture = $this->doc('Facture avec échéance posée par erreur', 'Facture', ['champs' => ['echeance' => '2026-11-15']]);
        $passe = $this->doc('Contrat échue hier', 'Contrat', ['champs' => ['echeance' => date('Y-m-d', time() - 86400)]]);

        $filtres = $this->vues->compiler(['type' => 'Contrat', 'champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => '90']);
        $gardes = array_column(Vues::filtrerDocs([$proche, $limite, $loin, $sansValeur, $facture, $passe], $filtres), 'titre');

        self::assertSame(['Contrat proche', 'Contrat à la limite', 'Contrat échue hier'], $gardes,
            'la vue garde les contrats dont l’échéance tombe dans moins de 90 jours (une échéance déjà passée compte), '
            . 'jamais une facture ni un contrat sans valeur');
    }

    public function testLesAutresFiltresTypés(): void
    {
        $docs = [
            $this->doc('Facture AN', 'Facture', ['emetteur' => 'Atelier Modele Sàrl', 'montant' => 250.0, 'etiquettes' => ['urgent', 'bureau'], 'date' => '2026-03-15']),
            $this->doc('Facture PS', 'Facture', ['emetteur' => 'Papeterie des Sapins', 'montant' => 90.0, 'etiquettes' => ['bureau'], 'date' => '2025-11-02']),
            $this->doc('Contrat PS', 'Contrat', ['emetteur' => 'Papeterie des Sapins', 'date' => '2026-01-20']),
        ];

        $parEtiquette = Vues::filtrerDocs($docs, $this->vues->compiler(['etiquette' => 'urgent']));
        self::assertSame(['Facture AN'], array_column($parEtiquette, 'titre'));

        $parPeriode = Vues::filtrerDocs($docs, $this->vues->compiler(['de' => '2026-01-01', 'a' => '2026-12-31']));
        self::assertSame(['Facture AN', 'Contrat PS'], array_column($parPeriode, 'titre'), 'la période filtre la date du document');

        $parEmetteur = Vues::filtrerDocs($docs, $this->vues->compiler(['emetteur' => 'Papeterie des Sapins']));
        self::assertSame(['Facture PS', 'Contrat PS'], array_column($parEmetteur, 'titre'));
    }

    public function testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier(): void
    {
        $depot = new DepotFichiers($this->config);
        $id1 = $depot->deposer($this->pieceADeposer('c1.pdf', 'contrat 1'), [
            'titre' => 'Contrat proche', 'type' => 'Contrat', 'emetteur' => 'Atelier Modele Sàrl', 'date' => '2026-09-01',
        ]);
        $id2 = $depot->deposer($this->pieceADeposer('f1.pdf', 'facture 1'), [
            'titre' => 'Facture AN', 'type' => 'Facture', 'date' => '2026-09-02',
        ]);
        $chemin1 = (string) $depot->fiche($id1)['chemin_relatif'];
        (new Champs($this->config))->poserValeur($chemin1, 'echeance', date('Y-m-d', time() + 20 * 86400));

        $idVue = $this->vues->enregistrer('Contrats à échéance < 90 j', [
            'type' => 'Contrat', 'champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => '90',
        ], 'olivia');

        $vue = $this->vues->lire($idVue);
        self::assertSame('Contrats à échéance < 90 j', $vue['nom']);
        self::assertSame('olivia', $vue['auteur']);
        self::assertSame('Contrat', $vue['filtres']['type']);

        // La vue est relue par son nom plié (liens du gabarit, entrées de menu).
        self::assertSame($idVue, $this->vues->parSlug('contrats-a-echeance-90-j')['id'] ?? null);

        // Jamais de déplacement de fichier : le contrat est toujours à SA place, la facture aussi.
        self::assertFileExists($this->racine . '/fonds/' . $chemin1);
        self::assertSame($chemin1, (string) $depot->fiche($id1)['chemin_relatif']);
        self::assertFileExists($this->racine . '/fonds/' . (string) $depot->fiche($id2)['chemin_relatif']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements')->fetchColumn(),
            'enregistrer une vue ne trace aucun déplacement');
    }

    public function testUnNomDeVueVideEstRefusé(): void
    {
        try {
            $this->vues->enregistrer('  ', ['type' => 'Contrat'], 'olivia');
            self::fail('une vue sans nom devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('nom', $e->getMessage());
        }
    }
}
