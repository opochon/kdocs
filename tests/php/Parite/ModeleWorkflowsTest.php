<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Workflows\Action;
use Ged\Workflows\Applicateur;
use Ged\Workflows\CourrielFaux;
use Ged\Workflows\Declencheur;
use Ged\Workflows\DepotWorkflows;
use Ged\Workflows\WebhookFaux;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Rejeu de la famille `modele` WORKFLOWS (lot ged-parite-modele-1).
 *
 * Vecteurs extraits en LECTURE SEULE de src/documents/tests/test_workflows.py (Paperless-ngx v2.20.5) :
 * document + déclencheur + actions → « déclenché ou non » et attributs résultants. Le rejeu passe par le
 * VRAI moteur Workflows de GEDv2 (`Declencheur`, `Action`, `Applicateur`) et par la base `gedv2_test` :
 * les actions d'affectation et de retrait écrivent par le contrat `Depot` et sont relues depuis l'index.
 *
 * La correspondance fonctionnelle Paperless -> GEDv2 est explicite (`traduireFiche`) : les noms de champs
 * Paperless (`filter_*`, `assign_*`, `remove_*`) restent tels quels, la fiche GEDv2 porte `titre`,
 * `emetteur`, `dossier`, `etiquettes`, `champs`… Les traits propres à Django/Celery sont listés
 * `non_rejouables` par l'extracteur, avec leur raison et leur référence.
 */
final class ModeleWorkflowsTest extends TestCase
{
    private const MAINTENANT = '2026-04-10';

    private PDO $pdo;
    private Config $config;
    private string $racine;
    private string $storageAvant;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        \Ged\Socle\Schema::installer($this->pdo);
        \Ged\Workflows\Schema::installer($this->pdo);
        \Ged\Recherche\Schema::installer($this->pdo);

        $this->storageAvant = (string) getenv('STORAGE_ROOT');
        $this->racine = sys_get_temp_dir() . '/gedv2-modele-workflows-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/depots', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');
        $this->nettoyerRejeux();
    }

    protected function tearDown(): void
    {
        $this->storageAvant === '' ? putenv('STORAGE_ROOT') : putenv('STORAGE_ROOT=' . $this->storageAvant);
        $this->pdo->exec("DELETE FROM workflow_definitions WHERE nom LIKE 'rejeu-modele-%'");
        $this->nettoyerRejeux();
        $this->supprimer($this->racine);
    }

    /** Retire les documents de rejeu (et leurs annexes) laissés par un passage précédent. */
    private function nettoyerRejeux(): void
    {
        $ids = $this->pdo->query("SELECT id FROM documents WHERE meta LIKE '%\"rejeu_modele\"%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            foreach (['document_versions', 'document_deplacements', 'recherche_textes'] as $table) {
                // `$table` vient d'une liste interne figée : aucune entrée utilisateur dans le SQL.
                $this->pdo->prepare("DELETE FROM $table WHERE document_id = ?")->execute([(int) $id]);
            }
        }
        $this->pdo->exec("DELETE FROM documents WHERE meta LIKE '%\"rejeu_modele\"%'");
    }

    public function testRejoueLesVecteursEtEcritLaMesure(): void
    {
        $donnees = Vecteurs::charger('modele-workflows');
        self::assertSame('modele-workflows', $donnees['categorie']);

        $passes = 0;
        $echecs = [];
        $familles = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $famille = (string) ($vecteur['famille'] ?? '?');
            $familles[$famille] ??= ['total' => 0, 'passes' => 0];
            $familles[$famille]['total']++;
            try {
                $this->rejouer($vecteur);
                $passes++;
                $familles[$famille]['passes']++;
            } catch (\Throwable $e) {
                $echecs[] = sprintf('%s (%s) : %s', (string) ($vecteur['id'] ?? '?'),
                    (string) ($vecteur['reference'] ?? ''), $e->getMessage());
            }
        }

        Vecteurs::ecrireMesure('modele-workflows', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'modele-workflows',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'familles' => $familles,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs de modèles de workflows en écart :\n" . implode("\n", $echecs));
    }

    /** @param array<string, mixed> $vecteur */
    private function rejouer(array $vecteur): void
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
        $famille = (string) ($vecteur['famille'] ?? '');
        $type = (int) ($entree['type'] ?? 2);
        $trigger = (array) ($entree['trigger'] ?? []);
        $document = $this->documentDe((array) ($entree['document'] ?? []));

        if ($famille === 'planification') {
            $derniere = isset($sortie['derniere_execution']) && is_string($sortie['derniere_execution'])
                ? new \DateTimeImmutable($sortie['derniere_execution']) : null;
            $obtenu = Declencheur::planifie($trigger, $document, new \DateTimeImmutable(self::MAINTENANT), $derniere);
            self::assertSame((bool) ($sortie['declenche'] ?? false), $obtenu, (string) ($vecteur['id'] ?? ''));

            return;
        }

        if ($famille === 'declencheurs') {
            $obtenu = Declencheur::correspondType($type, $trigger, $document);
            self::assertSame((bool) ($sortie['declenche'] ?? false), $obtenu, (string) ($vecteur['id'] ?? ''));

            return;
        }

        // Affectation, retrait, courriel/webhook : le VRAI Applicateur, sur un document de gedv2_test.
        $id = $this->deposer($document);
        $fiche = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        $workflow = [
            'id' => 0,
            'nom' => 'rejeu-modele-' . bin2hex(random_bytes(3)),
            'ordre' => 0,
            'actif' => true,
            'triggers' => [$trigger],
            'actions' => (array) ($entree['actions'] ?? []),
        ];
        $courriel = new CourrielFaux();
        $webhook = new WebhookFaux();
        $rapport = (new Applicateur($this->pdo, new DepotFichiers($this->config), null, $courriel, $webhook))
            ->appliquer($workflow, $type, $fiche, 'rejeu');

        self::assertSame((bool) ($sortie['declenche'] ?? false), $rapport['declenche'], (string) ($vecteur['id'] ?? ''));

        if ($famille === 'courriel-webhook') {
            $types = array_map(static fn (array $a): int => (int) ($a['type'] ?? 0), (array) ($entree['actions'] ?? []));
            if (in_array(Action::COURRIEL, $types, true)) {
                self::assertNotEmpty($courriel->envoyes, 'le courriel devait passer par le port FAUX');
            }
            if (in_array(Action::WEBHOOK, $types, true)) {
                self::assertNotEmpty($webhook->appels, 'le webhook devait passer par le port FAUX');
            }

            return;
        }

        $relu = Applicateur::fiche((new DepotFichiers($this->config))->fiche($id));
        foreach ((array) ($sortie['attributs'] ?? []) as $attribut => $attendu) {
            $courant = match ($attribut) {
                'etiquettes_nombre' => count((array) ($relu['etiquettes'] ?? [])),
                default => $relu[$attribut] ?? null,
            };
            self::assertSame($attendu, $courant, (string) ($vecteur['id'] ?? '') . " : attribut « $attribut »");
        }
    }

    /**
     * Traduction fonctionnelle d'un document Paperless vers la fiche GEDv2 (mêmes clés que le moteur).
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentDe(array $document): array
    {
        return [
            'titre' => (string) ($document['titre'] ?? ''),
            'emetteur' => $document['emetteur'] ?? null,
            'type' => $document['type'] ?? null,
            'dossier' => $document['dossier'] ?? null,
            'proprietaire' => $document['proprietaire'] ?? null,
            'etiquettes' => (array) ($document['etiquettes'] ?? []),
            'nom_fichier' => $document['nom_fichier'] ?? null,
            'chemin' => $document['chemin'] ?? null,
            'contenu' => (string) ($document['contenu'] ?? ''),
            'source' => (int) ($document['source'] ?? 0),
            'mailrule' => $document['mailrule'] ?? null,
            'droits' => (array) ($document['droits'] ?? []),
            'champs' => (array) ($document['champs'] ?? []),
            'dates' => (array) ($document['dates'] ?? []),
        ];
    }

    /** @param array<string, mixed> $document */
    private function nomFichier(array $document): string
    {
        $nom = (string) ($document['nom_fichier'] ?? 'piece.pdf');

        return $nom !== '' ? $nom : 'piece.pdf';
    }

    /**
     * Dépose une pièce synthétique portant les attributs du document Paperless, puis rend son id. Les
     * attributs du modèle Paperless vivent dans le `.meta.json` (le dossier fait foi) : c'est ce que lit
     * `Applicateur::fiche`.
     *
     * @param array<string, mixed> $document
     */
    private function deposer(array $document): int
    {
        $nom = $this->nomFichier($document);
        $source = $this->racine . '/depots/' . $nom;
        file_put_contents($source, 'contenu synthetique de rejeu');

        $meta = ['titre' => ((string) ($document['titre'] ?? '') ?: 'Pièce de rejeu') . ' ' . bin2hex(random_bytes(3)),
            'date' => (string) (($document['dates']['created'] ?? '') ?: self::MAINTENANT),
            'etiquettes' => array_values(array_map('strval', (array) ($document['etiquettes'] ?? []))),
            'nom_origine' => $nom,
            'texte' => (string) ($document['contenu'] ?? ''),
            'source_ingestion' => (int) ($document['source'] ?? 0),
            'rejeu_modele' => 1];
        foreach (['type', 'emetteur', 'dossier', 'proprietaire'] as $cle) {
            if (($document[$cle] ?? null) !== null) {
                $meta[$cle] = $document[$cle];
            }
        }
        if (($document['droits'] ?? []) !== []) {
            $meta['droits'] = $document['droits'];
        }
        if (($document['champs'] ?? []) !== []) {
            $meta['champs'] = $document['champs'];
        }

        return (new DepotFichiers($this->config))->deposer($source, $meta);
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
