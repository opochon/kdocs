<?php

declare(strict_types=1);

namespace Ged\Tests\Socle;

use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Export;
use Ged\Socle\Indexeur;
use Ged\Socle\Nomenclature;
use Ged\Socle\Reconstructeur;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-04 §2 : champs personnalisés typés (texte, nombre, montant CHF, date, liste, oui/non)
 * rattachés à un type de la nomenclature. La valeur vit dans le `.meta.json` (le dossier fait foi),
 * elle est portée par l’index, et la reconstruction (F12) rend toujours le même hash, champs posés.
 */
final class ChampsTest extends TestCase
{
    use SupportCorpus;

    private DepotFichiers $depot;
    private Champs $champs;

    protected function setUp(): void
    {
        $this->setUpCorpus();
        (new Nomenclature($this->config))->assurer();
        $this->depot = new DepotFichiers($this->config);
        $this->champs = new Champs($this->config);
    }

    protected function tearDown(): void
    {
        $this->tearDownCorpus();
    }

    /** Un Contrat rangé dans le fonds de test ; rend [id, chemin_relatif]. */
    private function deposerContrat(string $titre = 'Contrat de maintenance'): array
    {
        $id = $this->depot->deposer($this->pieceADeposer('contrat.pdf', 'contrat contenu'), [
            'titre' => $titre, 'type' => 'Contrat', 'emetteur' => 'Atelier Modele Sàrl', 'date' => '2026-09-01',
        ]);
        $ligne = $this->depot->fiche($id);

        return [$id, (string) $ligne['chemin_relatif']];
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

    public function testUnChampTypéEstRattachéÀUnTypeAvecUneCléSlug(): void
    {
        $echeance = $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        self::assertSame('echeance', $echeance['cle'], 'la clé est le nom plié en minuscules sans accents');
        self::assertSame('date', $echeance['nature']);

        $defs = $this->champs->pourType('Contrat');
        self::assertNotEmpty($defs, 'le champ est lu depuis son type');
        self::assertSame('Échéance', $defs[0]['nom']);
    }

    public function testDeuxChampsHomonymesSurLeMêmeTypeSontRefusésPasSurDeuxTypes(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        try {
            $this->champs->ajouter($this->idDuType('Contrat'), 'échéance ', 'date');
            self::fail('un champ homonyme sur le même type devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('existe', $e->getMessage());
        }

        $autre = $this->champs->ajouter($this->idDuType('Facture'), 'Échéance', 'date');
        self::assertSame('echeance', $autre['cle'], 'la même clé peut vivre sur un autre type');
    }

    public function testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        [$id, $chemin] = $this->deposerContrat();
        $dans90Jours = date('Y-m-d', time() + 45 * 86400);

        $this->champs->poserValeur($chemin, 'echeance', $dans90Jours);

        self::assertSame($dans90Jours, $this->meta($chemin)['champs']['echeance'] ?? null,
            'la valeur est écrite dans le .meta.json, à côté du fichier');
        $index = $this->pdo->query('SELECT meta, chemin_relatif FROM documents WHERE id = ' . $id)->fetch();
        self::assertSame($dans90Jours, (json_decode((string) $index['meta'], true) ?: [])['champs']['echeance'] ?? null,
            'l’index porte la valeur dans sa colonne meta (copie reconstructible du .meta.json)');
        self::assertSame($chemin, (string) $index['chemin_relatif'], 'poser une valeur ne déplace jamais le fichier');
        self::assertFileExists($this->racine . '/fonds/' . $chemin);
    }

    public function testUneValeurVideEffaceLeChampDuMetaJson(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        [, $chemin] = $this->deposerContrat();
        $this->champs->poserValeur($chemin, 'echeance', '2026-12-24');
        $this->champs->poserValeur($chemin, 'echeance', '');

        self::assertArrayNotHasKey('echeance', $this->meta($chemin)['champs'] ?? []);
    }

    public function testChaqueNatureValideEtRefuseCeQuIlFaut(): void
    {
        $types = ['texte' => 'Site', 'nombre' => 'Horaires', 'montant' => 'Forfait', 'liste' => 'Service', 'booleen' => 'Renouvelé'];
        foreach ($types as $nature => $nom) {
            $this->champs->ajouter($this->idDuType('Contrat'), $nom, $nature, $nature === 'liste' ? ['Audit', 'Révision'] : []);
        }
        [$id, $chemin] = $this->deposerContrat();

        $this->champs->poserValeur($chemin, 'site', 'La Chaux-de-Fonds');
        $this->champs->poserValeur($chemin, 'horaires', '12.5');
        $this->champs->poserValeur($chemin, 'forfait', '1234.567');
        $this->champs->poserValeur($chemin, 'service', 'Audit');
        $this->champs->poserValeur($chemin, 'renouvele', 'oui');

        $champs = $this->meta($chemin)['champs'];
        self::assertSame('La Chaux-de-Fonds', $champs['site']);
        self::assertSame(12.5, $champs['horaires'], 'un nombre est stocké comme nombre');
        self::assertSame(1234.57, $champs['forfait'], 'un montant CHF est arrondi au centime');
        self::assertSame('Audit', $champs['service']);
        self::assertTrue($champs['renouvele'], 'un oui/non est stocké comme booléen');

        // date : créée ici pour le cas, avec ses refus nommés
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance2', 'date');
        foreach ([
            'date non ISO' => '24.12.2026', 'date impossible' => '2026-02-30', 'n’importe quoi' => 'bientôt',
        ] as $brute) {
            try {
                $this->champs->poserValeur($chemin, 'echeance2', $brute);
                self::fail("la valeur « $brute » devait être refusée par la nature date");
            } catch (\RuntimeException $e) {
                self::assertStringContainsStringIgnoringCase('date', $e->getMessage(), 'l’erreur se nomme');
            }
        }
        try {
            $this->champs->poserValeur($chemin, 'service', 'Fréquentation');
            self::fail('une valeur hors options devait être refusée par la nature liste');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('options', $e->getMessage());
        }
        try {
            $this->champs->poserValeur($chemin, 'horaires', 'douze');
            self::fail('une chaîne devait être refusée par la nature nombre');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('nombre', $e->getMessage());
        }
        try {
            $this->champs->poserValeur($chemin, 'renouvele', 'peut-être');
            self::fail('une valeur autre qu’oui/non devait être refusée');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('oui', $e->getMessage());
        }
        try {
            $this->champs->poserValeur($chemin, 'inconnu', 'x');
            self::fail('poser la valeur d’un champ qui n’existe pas sur ce type devait être refusé');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('champ', $e->getMessage());
        }
    }

    public function testUnChampSurUnTypeNeSePosePasSurUnAutreType(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        $idFacture = $this->depot->deposer($this->pieceADeposer('facture.pdf', 'facture contenu'), [
            'titre' => 'Facture énergie', 'type' => 'Facture', 'date' => '2026-09-02',
        ]);
        $chemin = (string) $this->depot->fiche($idFacture)['chemin_relatif'];

        try {
            $this->champs->poserValeur($chemin, 'echeance', '2026-12-24');
            self::fail('un champ de Contrat ne se pose pas sur une Facture');
        } catch (\RuntimeException $e) {
            self::assertStringContainsStringIgnoringCase('type', $e->getMessage(), 'l’erreur nomme l’écart de type');
        }
    }

    public function testLaNatureNeSeChangePasAprèsCréation(): void
    {
        $champ = $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        try {
            $this->champs->modifier($champ['id'], nature: 'texte');
            self::fail('changer la nature d’un champ déjà posé devait être refusé (les valeurs vivent dans le dossier)');
        } catch (\Throwable $e) {
            // L'API ne parle même pas de nature : « Unknown named parameter $nature ». Le refus est structurel.
            self::assertStringContainsStringIgnoringCase('nature', $e->getMessage());
        }
        $this->champs->modifier($champ['id'], nom: 'Échéance contractuelle');
        self::assertSame('Échéance contractuelle', $this->champs->pourType('Contrat')[0]['nom']);
        self::assertSame('echeance', $this->champs->pourType('Contrat')[0]['cle'], 'la clé ne bouge pas : les valeurs restent adressées');
    }

    public function testLaReconstructionRendToujoursLeMêmeHashAvecDesChampsPosés(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        $this->champs->ajouter($this->idDuType('Contrat'), 'Renouvellement', 'booleen');
        [, $chemin] = $this->deposerContrat();
        $this->champs->poserValeur($chemin, 'echeance', date('Y-m-d', time() + 30 * 86400));
        $this->champs->poserValeur($chemin, 'renouvellement', 'oui');
        $this->deposerContrat('Contrat de bail');   // un deuxième document, sans champ posé

        $avant = Export::hacher($this->pdo);
        $stats = (new Reconstructeur($this->config))->reconstruire();
        $apres = Export::hacher($this->pdo);

        self::assertSame(2, $stats['documents']);
        self::assertSame($avant, $apres, 'F12 : l’index reconstruit depuis le dossier rend le même hash, champs posés');
    }

    public function testLIndexeurSuitUnMetaJsonÉditéHorsApplication(): void
    {
        $this->champs->ajouter($this->idDuType('Contrat'), 'Échéance', 'date');
        [$id, $chemin] = $this->deposerContrat();

        // Le champ est posé À LA MAIN dans le .meta.json (outil tiers, éditeur de texte).
        $absolu = $this->racine . '/fonds/' . $chemin;
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true) ?: [];
        $meta['champs']['echeance'] = '2026-11-15';
        file_put_contents($absolu . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $indexeur = new Indexeur($this->config);
        $indexeur->passer();
        $stats = $indexeur->passer();

        self::assertGreaterThanOrEqual(1, $stats['metas'], 'l’indexeur a vu le .meta.json changer');
        $index = json_decode((string) $this->pdo->query('SELECT meta FROM documents WHERE id = ' . $id)->fetchColumn(), true) ?: [];
        self::assertSame('2026-11-15', $index['champs']['echeance'] ?? null, 'la valeur posée hors application rejoint l’index');
    }
}
