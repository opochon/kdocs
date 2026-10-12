<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Courrier\BoiteFausse;
use Ged\Ingestion\Courrier\Compte;
use Ged\Ingestion\Courrier\ErreurCourrier;
use Ged\Ingestion\Courrier\Filtres;
use Ged\Ingestion\Courrier\Message;
use Ged\Ingestion\Courrier\Moteur;
use Ged\Ingestion\Courrier\Piece;
use Ged\Ingestion\Courrier\Regle;
use Ged\Ingestion\Courrier\Traites;
use Ged\Socle\DepotFichiers;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu de la famille `modele` COURRIER (lot ged-parite-modele-2).
 *
 * Vecteurs extraits en LECTURE SEULE de src/paperless_mail/tests/test_mail.py (Paperless-ngx v2.20.5) :
 * courriel + règle → « retenu ou non », attributs résultants, action appliquée. Le rejeu passe par le VRAI
 * moteur courrier de GEDv2 (`Filtres`, `Regle`, `Moteur`) et par la base `gedv2_test` : les pièces sont
 * déposées par le contrat `Depot`, le journal des courriels traités vit dans `ingestion_messages`.
 *
 * La correspondance fonctionnelle Paperless -> GEDv2 est explicite (`Regle::depuisVecteur`) : les noms de
 * champs Paperless (`filter_*`, `assign_*`, `action`) restent tels quels, la règle GEDv2 porte des noms
 * français. Les traits propres à Django/Celery/OAuth sont listés `non_rejouables` par l'extracteur.
 */
final class ModeleCourrierTest extends TestCase
{
    private const MAINTENANT = '2026-04-10';

    private PDO $pdo;
    private Config $config;
    private string $racine;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        \Ged\Socle\Schema::installer($this->pdo);
        \Ged\Ingestion\Schema::installer($this->pdo);

        $this->racine = sys_get_temp_dir() . '/gedv2-modele-courrier-' . bin2hex(random_bytes(4));
        mkdir($this->racine . '/fonds', 0775, true);
        mkdir($this->racine . '/travail', 0775, true);
        putenv('STORAGE_ROOT=' . $this->racine . '/fonds');
        $this->pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
        $this->nettoyerDocuments();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM ingestion_messages WHERE message_id LIKE 'courrier:%'");
        $this->nettoyerDocuments();
        putenv('STORAGE_ROOT');
        $this->supprimer($this->racine);
    }

    private function nettoyerDocuments(): void
    {
        $ids = $this->pdo->query("SELECT id FROM documents WHERE meta LIKE '%rejeu-courrier%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            foreach (['document_versions', 'document_deplacements', 'recherche_textes'] as $table) {
                // `$table` vient d'une liste interne figée : aucune entrée utilisateur dans le SQL.
                $this->pdo->prepare("DELETE FROM $table WHERE document_id = ?")->execute([(int) $id]);
            }
        }
        $this->pdo->exec("DELETE FROM documents WHERE meta LIKE '%rejeu-courrier%'");
    }

    public function testRejoueLesVecteursEtEcritLaMesure(): void
    {
        $donnees = Vecteurs::charger('modele-courrier');
        self::assertSame('modele-courrier', $donnees['categorie']);

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

        Vecteurs::ecrireMesure('modele-courrier', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'modele-courrier',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'familles' => $familles,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);

        self::assertSame([], $echecs, "vecteurs du modèle courrier en écart :\n" . implode("\n", $echecs));
    }

    /** @param array<string, mixed> $vecteur */
    private function rejouer(array $vecteur): void
    {
        $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
        $sortie = is_array($vecteur['sortie'] ?? null) ? $vecteur['sortie'] : [];
        $famille = (string) ($vecteur['famille'] ?? '');
        $identifiant = (string) ($vecteur['id'] ?? '');

        match ($famille) {
            'filtres-fichiers' => $this->rejouerFiltresFichiers($entree, $sortie, $identifiant),
            'filtres' => $this->rejouerFiltres($entree, $sortie, $identifiant),
            'titre' => $this->rejouerTitre($entree, $sortie, $identifiant),
            'emetteur' => $this->rejouerEmetteur($entree, $sortie, $identifiant),
            'pieces' => $this->rejouerPieces($entree, $sortie, $identifiant),
            'portee' => $this->rejouerPortee($entree, $sortie, $identifiant),
            'actions', 'regles' => $this->rejouerActions($entree, $sortie, $identifiant),
            'validation' => $this->rejouerValidation($entree, $identifiant),
            'journal' => $this->rejouerJournal($entree, $sortie, $identifiant),
            default => throw new \RuntimeException("famille inconnue : $famille"),
        };
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerFiltresFichiers(array $entree, array $sortie, string $id): void
    {
        $noms = array_values(array_map('strval', (array) ($entree['noms'] ?? [])));
        $retenus = Filtres::fichiersRetenus($noms, (string) ($entree['include'] ?? ''), (string) ($entree['exclude'] ?? ''));
        self::assertSame($sortie['retenus'] ?? [], $retenus, $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerFiltres(array $entree, array $sortie, string $id): void
    {
        $regle = Regle::depuisVecteur([
            'filter_from' => $entree['filter_from'] ?? '',
            'filter_to' => $entree['filter_to'] ?? '',
            'filter_subject' => $entree['filter_subject'] ?? '',
            'filter_body' => $entree['filter_body'] ?? '',
        ]);
        $retenus = 0;
        foreach ((array) ($entree['messages'] ?? []) as $brut) {
            if (is_array($brut) && Filtres::messageRetenu($this->message($brut), $regle, $this->maintenant())) {
                $retenus++;
            }
        }
        self::assertSame((int) ($sortie['retenus'] ?? 0), $retenus, $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerTitre(array $entree, array $sortie, string $id): void
    {
        $regle = Regle::depuisVecteur(['nom' => 'vecteur', 'assign_title_from' => $entree['assign_title_from'] ?? 1]);
        $message = new Message(1, (string) ($entree['sujet'] ?? ''), 'a@b.test', '', [], '', $this->maintenant(), '', [], false, false, []);
        $piece = new Piece((string) ($entree['nom_fichier'] ?? ''), 'application/pdf', 'attachment', '');
        self::assertSame($sortie['titre'] ?? null, $regle->titre($message, $piece), $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerEmetteur(array $entree, array $sortie, string $id): void
    {
        $regle = Regle::depuisVecteur([
            'nom' => 'vecteur',
            'assign_correspondent_from' => $entree['assign_correspondent_from'] ?? 1,
            'emetteur_choisi' => $entree['emetteur_choisi'] ?? '',
        ]);
        $message = new Message(1, '', (string) ($entree['de'] ?? ''), (string) ($entree['de_nom'] ?? ''),
            [], '', $this->maintenant(), '', [], false, false, []);
        self::assertSame($sortie['emetteur'] ?? null, $regle->emetteur($message), $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerPieces(array $entree, array $sortie, string $id): void
    {
        $message = $this->message((array) (($entree['messages'] ?? [])[0] ?? []));
        $regle = Regle::depuisVecteur((array) ($entree['regle'] ?? []) + ['nom' => 'vecteur']);
        $retenues = [];
        foreach ($message->pieces as $piece) {
            if (Filtres::pieceRetenue($piece, $regle)) {
                $retenues[] = $piece->nom;
            }
        }
        self::assertSame($sortie['pieces_retenues'] ?? [], $retenues, $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerPortee(array $entree, array $sortie, string $id): void
    {
        $rapport = $this->executer($entree, $id);
        self::assertSame((int) ($sortie['traites'] ?? 0), $rapport['traites'], $id);
        self::assertSame($sortie['pieces_retenues'] ?? [], $rapport['deposes'], $id);
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerActions(array $entree, array $sortie, string $id): void
    {
        [$rapport, $boite] = $this->executerAvecBoite($entree, $id);
        $etat = $boite->etat();
        foreach ($sortie as $cle => $attendu) {
            if (!array_key_exists((string) $cle, $etat)) {
                continue;
            }
            self::assertSame((int) $attendu, $etat[(string) $cle], $id . " : compteur « $cle »");
        }
        self::assertSame([], $rapport['erreurs'], $id);
    }

    /** @param array<string, mixed> $entree */
    private function rejouerValidation(array $entree, string $id): void
    {
        $regle = Regle::depuisVecteur((array) ($entree['regle'] ?? []) + ['nom' => 'vecteur']);
        try {
            $regle->valider();
            self::fail($id . ' : règle invalide acceptée');
        } catch (ErreurCourrier $e) {
            self::assertNotSame('', $e->getMessage(), $id);
        }
    }

    /** @param array<string, mixed> $entree @param array<string, mixed> $sortie */
    private function rejouerJournal(array $entree, array $sortie, string $id): void
    {
        $message = new Message((int) ($entree['uid'] ?? 1), (string) ($entree['sujet'] ?? ''), 'a@b.test', '',
            [], 'corps', $this->maintenant(), 'brut', [new Piece('piece.pdf', 'application/pdf', 'attachment', '%PDF')],
            false, false, []);
        $regle = Regle::depuisVecteur(['nom' => 'journal', 'action' => $entree['action'] ?? 3, 'assign_title_from' => 1]);
        $boite = new BoiteFausse('faux', [$message]);
        $boite->panneApplication = (bool) ($entree['panne_appliction'] ?? $entree['panne_action'] ?? false);
        $this->moteur()->traiter($this->compte($id), [$regle], $boite);

        $lignes = (new Traites($this->pdo))->journal(5, 'journal');
        self::assertNotSame([], $lignes, $id . ' : journal vide');
        self::assertSame($sortie['statut'] ?? '', (string) $lignes[0]['statut'], $id);
    }

    /**
     * @param array<string, mixed> $entree
     * @return array{traites:int, pieces:int, journalises:int, deposes:list<string>, erreurs:list<string>}
     */
    private function executer(array $entree, string $id): array
    {
        [$rapport] = $this->executerAvecBoite($entree, $id);

        return $rapport;
    }

    /**
     * @param array<string, mixed> $entree
     * @return array{0: array{traites:int, pieces:int, journalises:int, deposes:list<string>, erreurs:list<string>}, 1: BoiteFausse}
     */
    private function executerAvecBoite(array $entree, string $id): array
    {
        $messages = [];
        foreach ((array) ($entree['messages'] ?? []) as $brut) {
            if (is_array($brut)) {
                $messages[] = $this->message($brut);
            }
        }
        $boite = new BoiteFausse('faux', $messages);
        $regle = Regle::depuisVecteur((array) ($entree['regle'] ?? []) + ['nom' => 'vecteur']);
        $rapport = $this->moteur()->traiter($this->compte($id), [$regle], $boite);

        return [$rapport, $boite];
    }

    /** @param array<string, mixed> $brut */
    private function message(array $brut): Message
    {
        // Le constructeur Paperless ajoute une pièce jointe par défaut (attachments=1) : un vecteur qui ne
        // décrit pas ses pièces porte donc une pièce .pdf, comme le faux serveur de Paperless.
        if (!array_key_exists('pieces', $brut)) {
            $brut['pieces'] = [['nom' => 'file_0.pdf', 'type' => 'application/pdf', 'disposition' => 'attachment', 'contenu' => '%PDF']];
        }
        $message = Message::depuisTableau($brut, $this->maintenant());
        if (($brut['traite'] ?? false) === true) {
            $message->motsCles[] = 'processed';
        }

        return $message;
    }

    private function compte(string $id): Compte
    {
        $nom = 'rejeu-courrier-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($id)) . '-' . bin2hex(random_bytes(3));

        return new Compte(1, $nom, 'imap.faux.test', 993, Compte::SECURITE_SSL, 'u', 'x', false, 'UTF-8',
            Compte::TYPE_IMAP, null, null, 'rejeu-courrier', true);
    }

    private function moteur(): Moteur
    {
        return new Moteur(new DepotFichiers($this->config), new Traites($this->pdo), $this->racine . '/travail', $this->maintenant());
    }

    private function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::MAINTENANT);
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
