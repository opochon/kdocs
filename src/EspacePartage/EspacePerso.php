<?php

declare(strict_types=1);

namespace Ged\EspacePartage;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\Registre;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Espace personnel (lot ged-mfiles-espace-1) : les FAVORIS et les RÉCEMMENT CONSULTÉS d'un utilisateur.
 *
 * Ce sont des données d'INDEX, jamais une copie du document : elles vivent dans le registre portable du
 * fonds (`.ged/favoris.json`, `.ged/recents.json`, via `Ged\Socle\Registre`), survivent à une reconstruction
 * et suivent un déménagement du dossier. Le document reste le fichier + son `.meta.json` à côté.
 *
 * Deux garanties :
 * - un favori retiré porte une date de retrait (`retire_le`) : la ligne reste traçable, aucune suppression ;
 * - une liste (favoris, récents) ne révèle jamais un document qu'un compte ne peut pas lire : le filtre est
 *   appliqué à la lecture, fail-closed si le schéma de droits est indisponible.
 */
final class EspacePerso
{
    /** Registres du fonds qui portent l'espace personnel. */
    private const FICHIER_FAVORIS = 'favoris';
    private const FICHIER_RECENTS = 'recents';

    /** Nombre de documents récents rendus par défaut (le registre en garde l'historique complet). */
    public const RECENTS_MAX = 50;

    /** Borne haute admise pour la demande explicite d'un écran. */
    public const RECENTS_LIMITE_MAX = 500;

    private readonly Registre $registre;
    private readonly PDO $pdo;

    public function __construct(private readonly Config $config)
    {
        $this->registre = new Registre($config);
        $this->pdo = Base::pdo($config);
    }

    /**
     * Les favoris actifs d'un utilisateur, du plus récemment ajouté au plus ancien.
     *
     * @return list<array{document:int, titre:string, ajoute_le:string}>
     */
    public function favoris(string $utilisateur, ?int $compte = null): array
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        $documents = [];
        foreach ($this->entrees(self::FICHIER_FAVORIS, $utilisateur) as $entree) {
            if (($entree['retire_le'] ?? null) !== null) {
                continue;
            }
            $id = (int) ($entree['document'] ?? 0);
            if ($id > 0 && !isset($documents[$id])) {
                $documents[$id] = (string) ($entree['ajoute_le'] ?? '');
            }
        }

        return array_map(
            static fn (array $lien): array => ['document' => $lien['document'], 'titre' => $lien['titre'], 'ajoute_le' => $lien['valeur']],
            $this->joindreAvec($documents, $compte),
        );
    }

    /** Ce document est-il dans les favoris ACTIFS de cet utilisateur ? */
    public function estFavori(string $utilisateur, int $document): bool
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        foreach ($this->entrees(self::FICHIER_FAVORIS, $utilisateur) as $entree) {
            if ((int) ($entree['document'] ?? 0) === $document && ($entree['retire_le'] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /** Ajoute (ou réactive) un favori. Idempotent : un favori actif n'est jamais dupliqué. */
    public function ajouterFavori(string $utilisateur, int $document): void
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        $this->exigerDocument($document);
        $maintenant = $this->maintenant();
        $this->registre->modifier(self::FICHIER_FAVORIS, static function (array $etat) use ($utilisateur, $document, $maintenant): array {
            $entrees = self::liste((array) ($etat[$utilisateur] ?? []));
            foreach ($entrees as $entree) {
                if ((int) $entree['document'] === $document && ($entree['retire_le'] ?? null) === null) {
                    return $etat;   // déjà actif : rien à changer
                }
            }
            $autres = array_values(array_filter($entrees, static fn (array $e): bool => (int) $e['document'] !== $document));
            array_unshift($autres, ['document' => $document, 'ajoute_le' => $maintenant, 'retire_le' => null]);
            $etat[$utilisateur] = $autres;

            return $etat;
        });
    }

    /** Retire un favori : pose un marqueur daté, jamais une suppression. Idempotent. */
    public function retirerFavori(string $utilisateur, int $document): void
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        $this->exigerDocument($document);
        $maintenant = $this->maintenant();
        $this->registre->modifier(self::FICHIER_FAVORIS, static function (array $etat) use ($utilisateur, $document, $maintenant): array {
            $entrees = self::liste((array) ($etat[$utilisateur] ?? []));
            foreach ($entrees as &$entree) {
                if ((int) $entree['document'] === $document && ($entree['retire_le'] ?? null) === null) {
                    $entree['retire_le'] = $maintenant;
                }
            }
            unset($entree);
            $etat[$utilisateur] = $entrees;

            return $etat;
        });
    }

    /**
     * Enregistre la consultation d'un document : le document remonte en tête, sans doublon.
     */
    public function enregistrerConsultation(string $utilisateur, int $document): void
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        $this->exigerDocument($document);
        $maintenant = $this->maintenant();
        $this->registre->modifier(self::FICHIER_RECENTS, static function (array $etat) use ($utilisateur, $document, $maintenant): array {
            $autres = array_values(array_filter(
                self::liste((array) ($etat[$utilisateur] ?? [])),
                static fn (array $e): bool => (int) $e['document'] !== $document,
            ));
            array_unshift($autres, ['document' => $document, 'quand' => $maintenant]);
            $etat[$utilisateur] = $autres;

            return $etat;
        });
    }

    /**
     * Les documents récemment consultés, du plus récent au plus ancien, bornés et filtrés par les droits.
     *
     * @return list<array{document:int, titre:string, quand:string}>
     */
    public function recents(string $utilisateur, ?int $compte = null, int $limite = self::RECENTS_MAX): array
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        if ($limite < 1 || $limite > self::RECENTS_LIMITE_MAX) {
            throw new RuntimeException(sprintf('récents : limite hors bornes (1–%d)', self::RECENTS_LIMITE_MAX));
        }
        $documents = [];
        foreach ($this->entrees(self::FICHIER_RECENTS, $utilisateur) as $entree) {
            $id = (int) ($entree['document'] ?? 0);
            if ($id > 0 && !isset($documents[$id])) {
                $documents[$id] = (string) ($entree['quand'] ?? '');
            }
        }

        return array_slice(array_map(
            static fn (array $lien): array => ['document' => $lien['document'], 'titre' => $lien['titre'], 'quand' => $lien['valeur']],
            $this->joindreAvec($documents, $compte),
        ), 0, $limite);
    }

    /**
     * Joint les identifiants de l'index à leurs titres VIVANTS, dans l'ordre du registre, en retirant ce que
     * le compte ne peut pas lire. Un document disparu du fonds est simplement absent de la liste.
     *
     * @param array<int, string> $documents id => horodatage
     * @return list<array{document:int, titre:string, valeur:string}>
     */
    private function joindreAvec(array $documents, ?int $compte): array
    {
        if ($documents === []) {
            return [];
        }
        $ids = array_keys($documents);
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $requete = $this->pdo->prepare("SELECT id, titre FROM documents WHERE id IN ($marques) AND supprime_le IS NULL AND absent_depuis IS NULL");
        $requete->execute($ids);
        $titres = [];
        foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $titres[(int) $ligne['id']] = (string) $ligne['titre'];
        }
        $resultat = [];
        foreach ($documents as $id => $horodatage) {
            if (!isset($titres[$id]) || !$this->lisible($compte, $id)) {
                continue;
            }
            $resultat[] = ['document' => $id, 'titre' => $titres[$id], 'valeur' => $horodatage];
        }

        return $resultat;
    }

    /** Un compte sans droit de lecture ne voit pas la ligne ; schéma de droits absent = refus (fail-closed). */
    private function lisible(?int $compte, int $document): bool
    {
        if ($compte === null) {
            return true;
        }
        try {
            $droits = new DroitsDossiers($this->pdo);
            $dossier = $droits->dossierPourDocument($document);

            return $dossier !== null && $droits->autorise($compte, $dossier, 'lire');
        } catch (PDOException $e) {
            // Schéma de droits indisponible : refus, mais TRACÉ (jamais un échec muet).
            error_log('[GEDv2 espace-perso] droits indisponibles pour le document ' . $document . ' : ' . $e->getMessage());

            return false;
        }
    }

    /** Un favori ou une consultation ne vise qu'un document VIVANT de l'index. */
    private function exigerDocument(int $document): void
    {
        if ($document < 1 || $this->titreVivant($document) === null) {
            throw new RuntimeException('document introuvable dans le fonds');
        }
    }

    private function titreVivant(int $document): ?string
    {
        $requete = $this->pdo->prepare('SELECT titre FROM documents WHERE id = ? AND supprime_le IS NULL AND absent_depuis IS NULL');
        $requete->execute([$document]);
        $titre = $requete->fetchColumn();

        return $titre === false ? null : (string) $titre;
    }

    private function validerUtilisateur(string $utilisateur): string
    {
        $utilisateur = trim($utilisateur);
        if ($utilisateur === '' || mb_strlen($utilisateur) > 128) {
            throw new RuntimeException('espace personnel : utilisateur vide ou trop long (128 caractères au plus)');
        }

        return $utilisateur;
    }

    /**
     * Les entrées brutes d'un registre pour un utilisateur, sous forme de listes associatives.
     *
     * @return list<array<string, mixed>>
     */
    private function entrees(string $fichier, string $utilisateur): array
    {
        return self::liste((array) ($this->registre->lire($fichier)[$utilisateur] ?? []));
    }

    /**
     * Normalise une valeur de registre en liste d'entrées associatives (un JSON à la main reste lisible).
     *
     * @param array<mixed> $valeur
     * @return list<array<string, mixed>>
     */
    private static function liste(array $valeur): array
    {
        $entrees = [];
        foreach ($valeur as $entree) {
            if (is_array($entree)) {
                $entrees[] = $entree;
            }
        }

        return $entrees;
    }

    /** Horodatage lisible, insensible au fuseau du serveur pour rester comparable dans le fonds. */
    private function maintenant(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
