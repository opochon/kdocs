<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Champs;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Fonds;
use Ged\Socle\Nomenclature;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Socle\Vues;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Garde de PERF-3 : le filtrage se fait désormais CÔTÉ BASE (colonnes et JSON `meta.champs`) pour ne pas
 * lire le fonds. Ce test compare, jeu de filtres par jeu de filtres, le compte et la première page rendus
 * par l'écran avec ceux de `Vues::filtrerDocs` (la sémantique de référence) sur le même fonds.
 *
 * Fonds synthétique dédié (aucune donnée réelle), dans un dossier temporaire : la nomenclature n'est
 * jamais écrite dans `tests/corpus`.
 */
final class BibliothequeFiltresTest extends TestCase
{
    private const DOCUMENTS = 240;

    private PDO $pdo;
    private Config $config;
    private string $bac;
    private string $fonds;
    private string $prefixe;
    private string|false $storageAvant;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'l’oracle n’écrit que sur gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);

        $this->bac = sys_get_temp_dir() . '/gedv2-filtres-' . bin2hex(random_bytes(4));
        $this->fonds = $this->bac . '/documents';
        mkdir($this->fonds, 0775, true);
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->fonds);
        // Recrée la configuration sur le fonds de l'oracle (nomenclature + champs typés).
        $this->config = new Config(dirname(__DIR__, 3));

        $nomenclature = new Nomenclature($this->config);
        $nomenclature->assurer();
        $idContrat = 0;
        foreach ($nomenclature->lister() as $type) {
            if ($type['nom'] === 'Contrat') {
                $idContrat = $type['id'];
            }
        }
        self::assertGreaterThan(0, $idContrat, 'la nomenclature livrée porte le type Contrat');
        $champs = new Champs($this->config);
        $champs->ajouter($idContrat, 'Échéance', 'date');
        $champs->ajouter($idContrat, 'Montant payé', 'montant');
        $champs->ajouter($idContrat, 'Relance', 'booleen');
        $champs->ajouter($idContrat, 'Note', 'texte');

        $this->prefixe = 'filtres-' . bin2hex(random_bytes(4));
        $this->insererFonds(self::DOCUMENTS);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute([$this->prefixe . '/%']);
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        self::supprimerDossier($this->bac);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function jeuxDeFiltres(): array
    {
        return [
            'type' => [['type' => 'Contrat']],
            'émetteur' => [['emetteur' => 'Fournisseur Alpha']],
            'période' => [['de' => '2026-03-01', 'a' => '2026-06-30']],
            'étiquette' => [['etiquette' => ['perf3']]],
            'champ date (jours)' => [['champ_cle' => 'echeance', 'champ_op' => 'avant_jours', 'champ_valeur' => '3650']],
            'champ date avant' => [['champ_cle' => 'echeance', 'champ_op' => 'avant', 'champ_valeur' => '2027-01-01']],
            'champ date après' => [['champ_cle' => 'echeance', 'champ_op' => 'apres', 'champ_valeur' => '2026-01-01']],
            'champ montant min' => [['champ_cle' => 'montant-paye', 'champ_op' => 'min', 'champ_valeur' => '100']],
            'champ montant max' => [['champ_cle' => 'montant-paye', 'champ_op' => 'max', 'champ_valeur' => '100']],
            'champ montant égal' => [['champ_cle' => 'montant-paye', 'champ_op' => 'egal', 'champ_valeur' => '42.5']],
            'champ booléen' => [['champ_cle' => 'relance', 'champ_op' => 'est', 'champ_valeur' => 'oui']],
            'champ texte contient' => [['champ_cle' => 'note', 'champ_op' => 'contient', 'champ_valeur' => 'RAS']],
            'combiné' => [['type' => 'Contrat', 'etiquette' => ['perf3'], 'champ_cle' => 'relance', 'champ_op' => 'est', 'champ_valeur' => 'oui']],
        ];
    }

    /** @param array<string, mixed> $parametres */
    #[DataProvider('jeuxDeFiltres')]
    public function testLeFiltreSqlDonneLeMemeResultatQueLeFiltragePhp(array $parametres): void
    {
        $vues = new Vues($this->config);
        $attendus = Vues::filtrerDocs((new Fonds($this->config))->indexes($this->pdo), $vues->compiler($parametres));
        $totalAttendu = count($attendus);
        usort($attendus, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        $premierePage = array_slice(array_column($attendus, 'id'), 0, EcranBibliotheque::PAGE_TAILLE);

        $donnees = (new EcranBibliotheque($this->config))->donnees($parametres, 'jeton');

        self::assertSame($totalAttendu, $donnees['totalFiltre'], 'le compte SQL doit être exact');
        self::assertSame($premierePage, array_column($donnees['docs'], 'id'), 'la première page doit être identique');
    }

    private function insererFonds(int $n): void
    {
        $types = ['Contrat', 'Facture', 'Courrier'];
        $emetteurs = ['Fournisseur Alpha', 'Fournisseur Beta', 'Client Delta'];
        $insertion = $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, empreinte, taille, mime, meta, '
            . 'titre, type, emetteur, date_document, montant, etiquettes, statut, source) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'valide\', \'dossier\')');

        $this->pdo->beginTransaction();
        try {
            for ($i = 1; $i <= $n; $i++) {
                $type = $types[$i % count($types)];
                $emetteur = $emetteurs[intdiv($i, 11) % count($emetteurs)];
                $date = sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1);
                $nom = sprintf('piece-%05d.pdf', $i);
                $relatif = sprintf('%s/2026/%s/%s', $this->prefixe, $type, $nom);
                $meta = [
                    'titre' => sprintf('%s %05d', $type, $i), 'type' => $type, 'emetteur' => $emetteur,
                    'date' => $date, 'montant' => 100.0 + $i, 'etiquettes' => $i % 2 === 0 ? ['perf3', 'test'] : [],
                    'statut' => 'valide', 'source' => 'dossier',
                ];
                if ($i % 3 === 0) {
                    $meta['champs'] = [
                        'echeance' => sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1),
                        'montant-paye' => ($i % 5 === 0) ? 42.5 : 100.0 + $i,
                        'relance' => $i % 4 === 0,
                        'note' => $i % 2 === 0 ? 'RAS — pièce conforme' : 'à vérifier',
                    ];
                }
                $insertion->execute([$relatif, $nom, hash('sha256', $relatif), 10, 'application/pdf',
                    json_encode($meta, JSON_UNESCAPED_UNICODE), $meta['titre'], $type, $emetteur, $date, 100.0 + $i,
                    $meta['etiquettes'] === [] ? null : json_encode($meta['etiquettes'])]);
            }
            $this->pdo->commit();
        } catch (\Throwable $erreur) {
            $this->pdo->rollBack();

            throw $erreur;
        }
    }

    private static function supprimerDossier(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($chemin);
    }
}
