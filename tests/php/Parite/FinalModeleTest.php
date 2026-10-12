<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Schema as SchemaIngestion;
use Ged\Jonctions\Catalogue;
use Ged\Jonctions\ErreurApi;
use Ged\Securite\Schema as SchemaSecurite;
use Ged\Socle\Etiquettes;
use Ged\Socle\Registre;
use Ged\Socle\RequeteChamps;
use Ged\Socle\Schema as SchemaSocle;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Famille « évolution de modèle » du lot ged-parite-final-1.
 *
 * Vecteurs extraits en LECTURE SEULE de `src/documents/filters.py` et
 * `src/documents/tests/test_api_filter_by_custom_fields.py` (Paperless-ngx v2.20.5) : propriétaire,
 * hiérarchie d'étiquettes (`is_root`), nom d'origine (`original_filename`), numéro d'archive
 * (`archive_serial_number`) et requête JSON sur champs personnalisés (`custom_field_query`). Le rejeu
 * interroge la VRAIE couche de requêtes GEDv2 (`Jonctions\Catalogue`) sur `gedv2_test`. Fonds de test
 * éphémère hors du dépôt : la base de test et un dossier temporaire, jamais un fonds réel.
 */
final class FinalModeleTest extends TestCase
{
    private PDO $pdo;
    private Config $config;
    private string $sel;
    private string $fonds;
    private string|false $storageAvant;
    /** @var array<string, int> */
    private array $ids = [];
    private int $compteA = 0;
    private int $compteB = 0;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);
        SchemaSecurite::installer($this->pdo);
        SchemaIngestion::installer($this->pdo);

        $this->sel = bin2hex(random_bytes(5));
        $this->fonds = sys_get_temp_dir() . '/gedv2-final-modele-' . $this->sel;
        mkdir($this->fonds, 0775, true);
        $this->storageAvant = getenv('STORAGE_ROOT');
        putenv('STORAGE_ROOT=' . $this->fonds);
        Registre::oublier();

        $this->compteA = $this->compte('final-a-' . $this->sel . '@exemple.test');
        $this->compteB = $this->compte('final-b-' . $this->sel . '@exemple.test');
        $this->corpus();
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM ingestion_travaux WHERE chemin LIKE ?')->execute(['%' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ? OR titre LIKE ?')
            ->execute(['fonds-' . $this->sel . '%', '%' . $this->sel . '%']);
        $this->pdo->prepare('DELETE FROM comptes WHERE identifiant LIKE ?')->execute(['final-' . $this->sel . '%']);
        Registre::oublier();
        $this->storageAvant === false ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->supprimer($this->fonds);
    }

    public function testRejoueLesVecteursDeLEvolutionDeModele(): void
    {
        $donnees = Vecteurs::charger('final-modele');
        $catalogue = new Catalogue($this->config, 0, true);
        $passes = 0;
        $echecs = [];

        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $filtre = (string) ($entree['filtre'] ?? '');
            $valeur = $entree['valeur'] ?? null;
            $attendu = is_array($vecteur['sortie']['retenus'] ?? null) ? $vecteur['sortie']['retenus'] : [];
            sort($attendu);
            try {
                $obtenu = $this->rejouer($catalogue, $filtre, $valeur);
                sort($obtenu);
                $ok = $obtenu === array_values(array_map('strval', $attendu));
            } catch (ErreurApi $e) {
                $ok = (int) ($vecteur['sortie']['statut'] ?? 0) === $e->statut;
                $obtenu = ['erreur ' . $e->statut];
            }
            if ($ok) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %s, obtenu %s', $id,
                    (string) ($vecteur['reference'] ?? ''), json_encode($attendu), json_encode($obtenu));
            }
        }

        Vecteurs::ecrireApi('final-modele', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'final-modele',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs de modèle en écart :\n" . implode("\n", $echecs));
    }

    /** Les champs Paperless du Document exposés par la fiche : asn, page_count, storage_type, owner. */
    public function testLaFicheExposeLesChampsPaperlessDuDocument(): void
    {
        $catalogue = new Catalogue($this->config, 0, true);
        $fiche = $catalogue->fiche($this->ids['a'], 'test fiche');

        self::assertSame('42', $fiche['archive_serial_number'] ?? null);
        self::assertSame('unencrypted', $fiche['storage_type'] ?? null);
        self::assertSame(1, $fiche['page_count'] ?? null, 'le PDF de test fait 1 page');
        self::assertSame('final-a-' . $this->sel . '@exemple.test', $fiche['owner'] ?? null);
        self::assertSame('scanner-2024.pdf', $fiche['original_filename'] ?? null);

        // Un document sans propriétaire reste valide : la clé existe, la valeur est null.
        $sans = $catalogue->fiche($this->ids['b'], 'test fiche b');
        self::assertArrayHasKey('owner', $sans);
        self::assertNull($sans['owner']);
    }

    /** La requête JSON refuse une expression invalide d'un message nommé (jamais un filtre ignoré). */
    public function testLaRequeteDeChampsRefuseUneExpressionInvalide(): void
    {
        foreach (['pas-du-json', '["champ"]', '["champ","operateur-inconnu","v"]', '["a;DROP","exact","v"]'] as $invalide) {
            try {
                RequeteChamps::clauses($invalide, 'd');
                self::fail('une expression invalide doit être refusée : ' . $invalide);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('custom_field_query', $e->getMessage());
            }
        }
    }

    /**
     * @return list<string>
     * @throws ErreurApi
     */
    private function rejouer(Catalogue $catalogue, string $filtre, mixed $valeur): array
    {
        if ($filtre === 'is_root') {
            return $catalogue->etiquettes(['is_root' => $valeur === true ? '1' : '0']);
        }
        if ($filtre === 'owner') {
            $criteres = ['owner' => 'final-a-' . $this->sel . '@exemple.test'];
        } elseif ($filtre === 'owner__id') {
            $criteres = ['owner__id' => (string) $this->compteB];
        } elseif ($filtre === 'custom_field_query' && is_array($valeur)) {
            $criteres = ['custom_field_query' => (string) json_encode($valeur)];
        } else {
            $criteres = [$filtre => $valeur];
        }
        $page = $catalogue->lister($criteres, 'rejeu modèle ' . $filtre);

        return array_map(static fn (array $d): string => (string) $d['nom'], $page['documents']);
    }

    private function compte(string $identifiant): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'utilisateur']);

        return (int) $this->pdo->lastInsertId();
    }

    private function corpus(): void
    {
        // a : propriétaire A, champs string_field + url_field, asn 42, étiquettes Parent/Enfant/Orpheline.
        $this->ids['a'] = $this->document('a', 'Alpha ' . $this->sel, $this->compteA,
            ['string_field' => 'paperless', 'url_field' => 'http://example.com/'], '42',
            ['Parent', 'Enfant', 'Orpheline']);
        // b : aucun propriétaire, un champ string_field sans url.
        $this->ids['b'] = $this->document('b', 'Beta ' . $this->sel, null, ['string_field' => 'aut'], null, []);
        // c : propriétaire B, aucun champ.
        $this->ids['c'] = $this->document('c', 'Gamma ' . $this->sel, $this->compteB, [], null, []);

        // Nom d'origine porté par le SUIVI d'ingestion (le document ne le porte pas en meta).
        $this->pdo->prepare('INSERT INTO ingestion_travaux (empreinte, source, nom_origine, chemin, document_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([hash('sha256', 'final-' . $this->sel), 'dossier', 'scanner-2024.pdf',
                $this->fonds . '/ingestion-' . $this->sel . '.pdf', $this->ids['a']]);

        // Hiérarchie d'étiquettes : Parent (racine), Enfant (parent = Parent). « Orpheline » n'a pas de
        // définition : elle est racine par défaut, aucune parenté n'est inventée.
        $etiquettes = new Etiquettes($this->config);
        $parent = $etiquettes->ajouter('Parent')['id'];
        $etiquettes->ajouter('Enfant', Etiquettes::COULEUR_DEFAUT, false, $parent);
    }

    /**
     * @param array<string, mixed> $champs
     * @param list<string> $etiquettes
     */
    private function document(string $cle, string $titre, ?int $proprietaire, array $champs, ?string $asn, array $etiquettes): int
    {
        $meta = ['titre' => $titre, 'champs' => $champs];
        if ($asn !== null) {
            $meta['asn'] = $asn;
        }
        $nom = 'fonds-' . $this->sel . '-' . $cle . '.pdf';
        // Un vrai PDF d'une page pour le document a (page_count), un fichier absent pour les autres.
        if ($cle === 'a') {
            copy(dirname(__DIR__, 2) . '/corpus/apercu/documents/201-facture-an-5001.pdf', $this->fonds . '/' . $nom);
        }
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, etiquettes, statut, empreinte, taille, mime, meta, proprietaire_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nom, $cle, $titre, $etiquettes === [] ? null : (string) json_encode($etiquettes),
                'valide', hash('sha256', $cle . $this->sel), 1, 'application/pdf',
                (string) json_encode($meta, JSON_UNESCAPED_UNICODE), $proprietaire]);

        return (int) $this->pdo->lastInsertId();
    }

    private function supprimer(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($chemin, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($chemin);
    }
}
