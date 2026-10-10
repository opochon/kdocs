<?php

declare(strict_types=1);

namespace Ged\Tests\Recherche;

use Ged\Recherche\Semblables;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Schema as SchemaSecurite;
use PHPUnit\Framework\TestCase;

/**
 * Porte « documents semblables » (parité Paperless « more like this ») : à partir d'un document, le
 * moteur rend les documents qui lui ressemblent — mêmes termes significatifs ou même émetteur — en
 * EXCLUANT le document lui-même, et sans jamais sortir des dossiers lisibles par le compte.
 *
 * Corpus synthétique de `tests/oracles/recherche/semblables.json` : trois factures d'un même émetteur
 * et une lettre sans rapport. Aucune donnée réelle.
 */
final class SemblablesTest extends TestCase
{
    use SupportRecherche;

    private const ORACLE = '/tests/oracles/recherche/semblables.json';

    protected function setUp(): void
    {
        $this->setUpRecherche();
        SchemaSecurite::installer($this->pdo);
    }

    public function testTroisFacturesDuMemeEmetteurSortentEnTeteEtPasLaLettre(): void
    {
        /** @var array{corpus: list<array<string, mixed>>, cas: list<array<string, mixed>>} $oracle */
        $oracle = json_decode((string) file_get_contents(dirname(__DIR__, 3) . self::ORACLE), true, 512, JSON_THROW_ON_ERROR);
        $titreParRef = [];
        foreach ($oracle['corpus'] as $document) {
            $titreParRef[(string) $document['ref']] = (string) $document['titre'];
        }
        $deposes = $this->deposerCorpus($oracle['corpus']);
        $moteur = new Semblables($this->pdo);

        foreach ($oracle['cas'] as $cas) {
            $resultat = $moteur->pour((int) $deposes[(string) $cas['source']]['id'], 20);
            $obtenus = array_map(static fn ($t): string => $t->titre, $resultat->trouvailles);
            $attendus = array_map(static fn (string $ref): string => $titreParRef[$ref], (array) $cas['attendu']);
            $exclus = array_map(static fn (string $ref): string => $titreParRef[$ref], (array) $cas['exclus']);

            self::assertNull($resultat->erreur?->cause, 'cas « ' . $cas['source'] . ' » : ' . ($resultat->erreur?->cause ?? ''));
            self::assertSame($attendus, array_slice($obtenus, 0, count($attendus)),
                'cas « ' . $cas['source'] . ' » : les semblables attendus en tête, dans l’ordre');
            foreach ($exclus as $titreExclu) {
                self::assertNotContains($titreExclu, $obtenus, 'cas « ' . $cas['source'] . ' » : un document sans rapport ne doit pas sortir');
            }
            self::assertLessThan((int) $cas['max_ms'], $resultat->dureeMs, 'cas « ' . $cas['source'] . ' » : moins d’une seconde');
        }
    }

    public function testLeDocumentLuiMemeNEstJamaisDansSesPropresSemblables(): void
    {
        $deposes = $this->deposerCorpus($this->corpusOracle());
        $id = (int) $deposes['contrat-maintenance']['id'];

        $resultat = (new Semblables($this->pdo))->pour($id, 20);
        $ids = array_map(static fn ($t): int => $t->id, $resultat->trouvailles);

        self::assertNull($resultat->erreur?->cause);
        self::assertNotContains($id, $ids, 'le document source est exclu de sa propre liste');
    }

    public function testLesDroitsDeDossierSontAppliquesDansLaRequete(): void
    {
        $suffixe = bin2hex(random_bytes(5));
        $droits = new DroitsDossiers($this->pdo);
        $compte = $this->creerCompte($suffixe);
        $groupe = $droits->creerGroupe('semblables-' . $suffixe, 'utilisateur');
        $droits->ajouterCompteAuGroupe($compte, $groupe);

        $source = $this->insererDocument("semblables-$suffixe/A/facture-1.pdf", 'Facture F-1 Horizon', 'Facture', 'Atelier Horizon SA',
            'Facture F-1. Maintenance des postes de travail. Total TTC 1 000.00 CHF.');
        $voisin = $this->insererDocument("semblables-$suffixe/A/facture-2.pdf", 'Facture F-2 Horizon', 'Facture', 'Atelier Horizon SA',
            'Facture F-2. Maintenance annuelle des postes de travail.');
        $cache = $this->insererDocument("semblables-$suffixe/B/facture-3.pdf", 'Facture F-3 Horizon', 'Facture', 'Atelier Horizon SA',
            'Facture F-3. Maintenance des postes de travail.');

        $dossierA = (int) $droits->assurerDossierPourChemin("semblables-$suffixe/A/facture-1.pdf");
        $droits->poserRegle($dossierA, $groupe, 'lire', true);

        // Un compte sans droit sur B ne doit pas voir la facture cachée dans sa liste de semblables…
        $resultat = (new Semblables($this->pdo, $compte))->pour($source, 20);
        $ids = array_map(static fn ($t): int => $t->id, $resultat->trouvailles);
        self::assertContains($voisin, $ids, 'un semblable lisible reste visible');
        self::assertNotContains($cache, $ids, 'le prédicat de lecture est appliqué DANS la requête');

        // … et ne peut pas non plus partir d'une source qu'il n'a pas le droit de lire.
        self::assertNull((new Semblables($this->pdo, $compte))->source($cache), 'une source hors droits est introuvable, jamais révélée');
        self::assertSame([], (new Semblables($this->pdo, $compte))->pour($cache, 20)->trouvailles);

        // Sans compte (contexte de fonds complet, administrateur), les deux voisins sont là : c'est bien l'ACL qui filtre.
        $complets = array_map(static fn ($t): int => $t->id, (new Semblables($this->pdo))->pour($source, 20)->trouvailles);
        self::assertContains($voisin, $complets);
        self::assertContains($cache, $complets);

        $this->nettoyerDroits($suffixe, $compte, $source, $voisin, $cache);
    }

    public function testUneSourceIntrouvableDonneUneListeVideSansErreur(): void
    {
        $moteur = new Semblables($this->pdo);

        self::assertNull($moteur->source(999999), 'un identifiant inconnu n’est pas une source');
        $resultat = $moteur->pour(999999, 20);
        self::assertNull($resultat->erreur?->cause);
        self::assertSame([], $resultat->trouvailles);
        self::assertSame('semblable', $resultat->mode);
    }

    private function creerCompte(string $suffixe): int
    {
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute(["semblables-$suffixe@gedv2.test", password_hash('Synthétique-' . $suffixe, PASSWORD_DEFAULT), 'utilisateur']);

        return (int) $this->pdo->lastInsertId();
    }

    private function insererDocument(string $chemin, string $titre, string $type, ?string $emetteur, string $contenu): int
    {
        $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, titre, type, empreinte, taille, mime, meta, statut)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$chemin, basename($chemin), $titre, $type, hash('sha256', $chemin), 1, 'application/pdf', '{}', 'valide']);
        $document = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, contenu, signature)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $chemin), $chemin, $document, $titre, $type, $emetteur, $contenu, hash('sha256', $chemin)]);

        return $document;
    }

    private function nettoyerDroits(string $suffixe, int $compte, int ...$documents): void
    {
        foreach ($documents as $document) {
            $this->pdo->prepare('DELETE FROM recherche_textes WHERE document_id = ?')->execute([$document]);
            $this->pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$document]);
        }
        $this->pdo->prepare('DELETE FROM securite_dossiers_droits WHERE dossier_id IN (SELECT id FROM securite_dossiers WHERE chemin_relatif LIKE ?)')
            ->execute(['semblables-' . $suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_dossiers WHERE chemin_relatif LIKE ?')->execute(['semblables-' . $suffixe . '%']);
        $this->pdo->prepare('DELETE FROM securite_groupes_comptes WHERE compte_id = ?')->execute([$compte]);
        $this->pdo->prepare('DELETE FROM securite_groupes WHERE nom = ?')->execute(['semblables-' . $suffixe]);
        $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$compte]);
    }
}
