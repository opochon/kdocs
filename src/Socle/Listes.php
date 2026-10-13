<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Listes de valeurs nommées (famille M-Files nº 1, lot ged-mfiles-classes-1) : des vocabulaires
 * contrôlés réutilisables — Statut, Type de pièce, Exercice — qu'une propriété de classe référence
 * (`Champs` nature « liste » + `liste` = clé). Comme la nomenclature, elles vivent dans le fonds
 * (`.ged/listes.json`, via Registre) : configuration portable et versionnable, reconstruisible,
 * jamais dans la base (le plafond de 20 tables tient) ni dans un coffre propriétaire.
 *
 * Intégrité : une valeur n'est JAMAIS effacée. On la retire (`actif = false`, `retire_le`,
 * `retire_par`) : elle n'est plus proposée aux nouvelles saisies, mais reste lisible dans le dossier
 * — les documents qui la portent et la trace du geste survivent à tout.
 */
final class Listes
{
    private const FICHIER = 'listes';

    /** Vocabulaires livrés, dans l'ordre du plan. L'administrateur les adapte ensuite dans le fonds. */
    public const LISTES_DEFAUT = [
        'Statut' => ['À traiter', 'En cours', 'Validé', 'Payé', 'Refusé'],
        'Type de pièce' => ['Facture', 'Contrat', 'Courrier', 'Reçu', 'Attestation'],
        'Exercice' => ['2024', '2025', '2026'],
    ];

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Pose la graine si le fonds n'a pas encore de listes : idempotent, rejouable. La transformation
     * relit l'état SOUS VERROU (deux amorçages croisés ne posent la graine qu'une fois).
     */
    public function assurer(): void
    {
        if ($this->registre->lire(self::FICHIER) !== []) {
            return;
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat): array {
            if (($etat['listes'] ?? []) !== []) {
                return $etat;   // un concurrent l'a posée entre-temps : elle reste telle quelle
            }
            $listes = [];
            $idListe = 0;
            foreach (self::LISTES_DEFAUT as $nom => $valeurs) {
                ++$idListe;
                $listes[] = [
                    'id' => $idListe,
                    'nom' => $nom,
                    'cle' => Fichiers::slug($nom),
                    'actif' => true,
                    'valeurs' => array_map(
                        static fn (string $libelle, int $i): array => ['id' => $i + 1, 'libelle' => $libelle, 'actif' => true],
                        $valeurs,
                        array_keys($valeurs),
                    ),
                ];
            }

            return ['listes' => $listes];
        });
    }

    /**
     * @return list<array{id:int, nom:string, cle:string, actif:bool, nb_valeurs:int}>
     */
    public function lister(bool $activesSeules = true): array
    {
        $listes = [];
        foreach ($this->contenu() as $liste) {
            if ($activesSeules && $liste['actif'] === false) {
                continue;
            }
            $listes[] = ['id' => $liste['id'], 'nom' => $liste['nom'], 'cle' => $liste['cle'],
                'actif' => $liste['actif'], 'nb_valeurs' => count($liste['valeurs'])];
        }

        return $listes;
    }

    /**
     * Les listes complètes (avec leurs valeurs), pour l'écran d'administration et l'export portable.
     * Les valeurs retirées restent visibles (traçabilité), filtrables par `$activesSeules`.
     *
     * @return list<array{id:int, nom:string, cle:string, actif:bool, valeurs:list<array<string,mixed>>}>
     */
    public function complet(bool $activesSeules = false): array
    {
        $listes = [];
        foreach ($this->contenu() as $liste) {
            if ($activesSeules && $liste['actif'] === false) {
                continue;
            }
            if ($activesSeules) {
                $liste['valeurs'] = array_values(array_filter($liste['valeurs'], static fn (array $v): bool => $v['actif'] === true));
            }
            $listes[] = $liste;
        }

        return $listes;
    }

    /**
     * Une liste complète (avec ses valeurs), par identifiant.
     *
     * @return array{id:int, nom:string, cle:string, actif:bool, valeurs:list<array<string,mixed>>}|null
     */
    public function trouver(int $id): ?array
    {
        foreach ($this->contenu() as $liste) {
            if ($liste['id'] === $id) {
                return $liste;
            }
        }

        return null;
    }

    /**
     * Une liste complète (avec ses valeurs), par sa clé stable (slug).
     *
     * @return array{id:int, nom:string, cle:string, actif:bool, valeurs:list<array<string,mixed>>}|null
     */
    public function parCle(string $cle): ?array
    {
        foreach ($this->contenu() as $liste) {
            if ($liste['cle'] === $cle) {
                return $liste;
            }
        }

        return null;
    }

    /** Ajoute une liste nommée ; refuse un nom vide, trop long ou homonyme. Rend son identifiant. */
    public function ajouter(string $nom): int
    {
        $nom = self::validerNom($nom);
        foreach ($this->lister(false) as $liste) {
            if (mb_strtolower($liste['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("liste : « $nom » existe déjà dans le fonds");
            }
        }

        $id = 0;
        $this->transformer(static function (array $etat) use ($nom, &$id): array {
            $id = self::prochainId((array) ($etat['listes'] ?? []));
            $etat['listes'][] = ['id' => $id, 'nom' => $nom, 'cle' => Fichiers::slug($nom),
                'actif' => true, 'valeurs' => []];

            return $etat;
        });

        return $id;
    }

    /** Renomme le libellé affiché : la clé ne bouge jamais, les propriétés restent adressées. */
    public function renommer(int $id, string $nom): void
    {
        $nom = self::validerNom($nom);
        foreach ($this->lister(false) as $liste) {
            if ($liste['id'] !== $id && mb_strtolower($liste['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("liste : « $nom » existe déjà dans le fonds");
            }
        }
        $this->changerListe($id, static function (array $liste) use ($nom): array {
            $liste['nom'] = $nom;

            return $liste;
        });
    }

    /** Met une liste en veille (ou la réactive) : elle n'est plus proposée, ses valeurs restent lisibles. */
    public function definirActif(int $id, bool $actif): void
    {
        $this->changerListe($id, static function (array $liste) use ($actif): array {
            $liste['actif'] = $actif;

            return $liste;
        });
    }

    /** Ajoute une valeur à une liste ; refuse un libellé vide, trop long ou homonyme. Rend son id. */
    public function ajouterValeur(int $listeId, string $libelle): int
    {
        $libelle = self::validerLibelle($libelle);
        $liste = $this->trouver($listeId) ?? throw new RuntimeException("liste : aucune liste nº $listeId");
        foreach ($liste['valeurs'] as $valeur) {
            if (mb_strtolower((string) $valeur['libelle']) === mb_strtolower($libelle)) {
                throw new RuntimeException("liste : « $libelle » existe déjà dans « " . $liste['nom'] . " »");
            }
        }

        $id = 0;
        $this->transformer(static function (array $etat) use ($listeId, $libelle, &$id): array {
            foreach ($etat['listes'] as &$liste) {
                $liste = (array) $liste;
                if ((int) ($liste['id'] ?? 0) === $listeId) {
                    $valeurs = (array) ($liste['valeurs'] ?? []);
                    $id = self::prochainId($valeurs);
                    $valeurs[] = ['id' => $id, 'libelle' => $libelle, 'actif' => true];
                    $liste['valeurs'] = $valeurs;
                }
            }

            return $etat;
        });

        return $id;
    }

    /** Renomme le libellé d'une valeur : les documents qui la portent gardent l'ancienne orthographe. */
    public function renommerValeur(int $listeId, int $valeurId, string $libelle): void
    {
        $libelle = self::validerLibelle($libelle);
        $this->changerValeur($listeId, $valeurId, static function (array $valeur) use ($libelle): array {
            $valeur['libelle'] = $libelle;

            return $valeur;
        });
    }

    /**
     * Retire une valeur SANS l'effacer : elle n'est plus proposée, mais reste datée et attribuée dans
     * le dossier (intégrité demandée par la mission).
     */
    public function retirerValeur(int $listeId, int $valeurId, string $auteur = 'systeme'): void
    {
        $auteur = trim($auteur) !== '' ? trim($auteur) : 'systeme';
        $this->changerValeur($listeId, $valeurId, static function (array $valeur) use ($auteur): array {
            $valeur['actif'] = false;
            $valeur['retire_le'] = date('c');
            $valeur['retire_par'] = $auteur;

            return $valeur;
        });
    }

    /** Réactive une valeur retirée (le geste inverse, tracé par la remise en service). */
    public function reactiverValeur(int $listeId, int $valeurId): void
    {
        $this->changerValeur($listeId, $valeurId, static function (array $valeur): array {
            $valeur['actif'] = true;
            unset($valeur['retire_le'], $valeur['retire_par']);

            return $valeur;
        });
    }

    /**
     * Une valeur par son identifiant, retirée ou active.
     *
     * @return array<string,mixed>|null
     */
    public function valeur(int $listeId, int $valeurId): ?array
    {
        $liste = $this->trouver($listeId);
        if ($liste === null) {
            return null;
        }
        foreach ($liste['valeurs'] as $valeur) {
            if ((int) $valeur['id'] === $valeurId) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * Une valeur par son libellé (exact), retirée ou active, ou null.
     *
     * @return array<string,mixed>|null
     */
    public function valeurParLibelle(int $listeId, string $libelle): ?array
    {
        $liste = $this->trouver($listeId);
        if ($liste === null) {
            return null;
        }
        foreach ($liste['valeurs'] as $valeur) {
            if ((string) $valeur['libelle'] === $libelle) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * Les libellés d'une liste, par sa clé. Actifs seuls par défaut ; `false` rend aussi les valeurs
     * retirées (lecture d'administration et de traçabilité).
     *
     * @return list<string>
     */
    public function valeursDe(string $cle, bool $activesSeules = true): array
    {
        $liste = $this->parCle($cle);
        if ($liste === null || ($activesSeules && $liste['actif'] === false)) {
            return [];
        }
        $libelles = [];
        foreach ($liste['valeurs'] as $valeur) {
            if (!$activesSeules || $valeur['actif'] === true) {
                $libelles[] = (string) $valeur['libelle'];
            }
        }

        return $libelles;
    }

    /** Une valeur est-elle proposable ? (liste active, valeur active) — garde de validation. */
    public function estValeurActive(string $cle, string $libelle): bool
    {
        return in_array($libelle, $this->valeursDe($cle), true);
    }

    /** L'identifiant d'une liste depuis un alias (clé, slug ou nom) — résolution des requêtes M-Files. */
    public function resoudreAlias(string $alias): ?int
    {
        $alias = trim($alias);
        foreach ($this->contenu() as $liste) {
            if ($liste['cle'] === $alias || Fichiers::slug($liste['nom']) === Fichiers::slug($alias)
                || (ctype_digit($alias) && $liste['id'] === (int) $alias)) {
                return $liste['id'];
            }
        }

        return null;
    }

    /**
     * L'état complet, normalisé : listes et valeurs typées, ordre de création conservé.
     *
     * @return list<array{id:int, nom:string, cle:string, actif:bool, valeurs:list<array<string,mixed>>}>
     */
    private function contenu(): array
    {
        $brut = $this->registre->lire(self::FICHIER);
        $listes = [];
        foreach ((array) ($brut['listes'] ?? []) as $liste) {
            $liste = (array) $liste;
            $valeurs = [];
            foreach ((array) ($liste['valeurs'] ?? []) as $valeur) {
                $valeur = (array) $valeur;
                $normalisee = ['id' => (int) $valeur['id'], 'libelle' => (string) $valeur['libelle'],
                    'actif' => (bool) ($valeur['actif'] ?? true)];
                if (isset($valeur['retire_le'])) {
                    $normalisee['retire_le'] = (string) $valeur['retire_le'];
                }
                if (isset($valeur['retire_par'])) {
                    $normalisee['retire_par'] = (string) $valeur['retire_par'];
                }
                $valeurs[] = $normalisee;
            }
            $listes[] = [
                'id' => (int) ($liste['id'] ?? 0),
                'nom' => (string) ($liste['nom'] ?? ''),
                'cle' => (string) ($liste['cle'] ?? Fichiers::slug((string) ($liste['nom'] ?? ''))),
                'actif' => (bool) ($liste['actif'] ?? true),
                'valeurs' => $valeurs,
            ];
        }

        return $listes;
    }

    /**
     * Réécrit les listes au complet SOUS VERROU, depuis une transformation de l'état brut.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $transformation
     * @return array<string, mixed>
     */
    private function transformer(callable $transformation): array
    {
        return $this->registre->modifier(self::FICHIER, static function (array $etat) use ($transformation): array {
            return $transformation($etat === [] ? ['listes' => []] : $etat);
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $transformation */
    private function changerListe(int $id, callable $transformation): void
    {
        $trouvee = false;
        $this->transformer(static function (array $etat) use ($id, $transformation, &$trouvee): array {
            foreach ($etat['listes'] as &$liste) {
                $liste = (array) $liste;
                if ((int) ($liste['id'] ?? 0) === $id) {
                    $trouvee = true;
                    $liste = $transformation($liste);
                }
            }

            return $etat;
        });
        if (!$trouvee) {
            throw new RuntimeException("liste : aucune liste nº $id");
        }
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $transformation */
    private function changerValeur(int $listeId, int $valeurId, callable $transformation): void
    {
        $trouvee = false;
        $this->transformer(static function (array $etat) use ($listeId, $valeurId, $transformation, &$trouvee): array {
            foreach ($etat['listes'] as &$liste) {
                $liste = (array) $liste;
                if ((int) ($liste['id'] ?? 0) !== $listeId) {
                    continue;
                }
                $valeurs = (array) ($liste['valeurs'] ?? []);
                foreach ($valeurs as &$valeur) {
                    $valeur = (array) $valeur;
                    if ((int) ($valeur['id'] ?? 0) === $valeurId) {
                        $trouvee = true;
                        $valeur = $transformation($valeur);
                    }
                }
                unset($valeur);
                $liste['valeurs'] = $valeurs;
            }

            return $etat;
        });
        if (!$trouvee) {
            throw new RuntimeException("liste : aucune valeur nº $valeurId dans la liste nº $listeId");
        }
    }

    private static function validerNom(string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('liste : nom vide ou trop long (100 caractères au plus)');
        }

        return $nom;
    }

    private static function validerLibelle(string $libelle): string
    {
        $libelle = trim($libelle);
        if ($libelle === '' || mb_strlen($libelle) > 200) {
            throw new RuntimeException('liste : libellé vide ou trop long (200 caractères au plus)');
        }

        return $libelle;
    }

    /** @param array<mixed> $entrees */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $entree) {
            $max = max($max, (int) (((array) $entree)['id'] ?? 0));
        }

        return $max + 1;
    }
}
