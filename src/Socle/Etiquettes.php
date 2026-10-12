<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Étiquettes de classement (modèle Paperless `Tag`) : un nom, une COULEUR et le drapeau « boîte de
 * réception » (`is_inbox_tag`). Comme la nomenclature et les vues, elles vivent dans le fonds
 * (`.ged/etiquettes.json`) : le dossier fait foi, une reconstruction les emporte. Une étiquette n'est
 * jamais supprimée — elle peut être renommée et recolorée.
 */
final class Etiquettes
{
    private const FICHIER = 'etiquettes';

    public const COULEUR_DEFAUT = '#8aa1b1';

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * @return array{id:int, nom:string, couleur:string, boite_reception:bool}
     */
    public function ajouter(string $nom, string $couleur = self::COULEUR_DEFAUT, bool $boiteReception = false, ?int $parent = null): array
    {
        $nom = self::validerNom($nom);
        $couleur = self::validerCouleur($couleur);
        $parent = $this->validerParent($parent);
        foreach ($this->lister(false) as $e) {
            if (mb_strtolower($e['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("étiquette : « $nom » existe déjà");
            }
        }
        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $couleur, $boiteReception, $parent, &$id): array {
            $etiquettes = (array) ($etat['etiquettes'] ?? []);
            $id = self::prochainId($etiquettes);
            $etiquettes[] = ['id' => $id, 'nom' => $nom, 'couleur' => $couleur,
                'boite_reception' => $boiteReception, 'parent' => $parent, 'actif' => true];

            return ['etiquettes' => $etiquettes];
        });

        return $this->lire($id) ?? throw new RuntimeException('étiquette : création non relue');
    }

    public function renommer(int $id, string $nom): void
    {
        $nom = self::validerNom($nom);
        $this->changer($id, ['nom' => $nom]);
    }

    public function definirCouleur(int $id, string $couleur): void
    {
        $this->changer($id, ['couleur' => self::validerCouleur($couleur)]);
    }

    public function definirBoiteReception(int $id, bool $boiteReception): void
    {
        $this->changer($id, ['boite_reception' => $boiteReception]);
    }

    /** Règle la parenté (hiérarchie d'étiquettes, modèle Paperless `Tag.parent`) ; null = racine. */
    public function definirParent(int $id, ?int $parent): void
    {
        $this->changer($id, ['parent' => $this->validerParent($parent, $id)]);
    }

    /** Met en veille (`actif = false`) : l'étiquette reste lisible, elle n'est plus proposée. */
    public function definirActif(int $id, bool $actif): void
    {
        $this->changer($id, ['actif' => $actif]);
    }

    /** @return array{id:int, nom:string, couleur:string, boite_reception:bool, parent:?int, actif:bool}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister(false) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return list<array{id:int, nom:string, couleur:string, boite_reception:bool, parent:?int, actif:bool}>
     */
    public function lister(bool $actifsSeuls = true): array
    {
        $etat = $this->registre->lire(self::FICHIER);
        $etiquettes = [];
        foreach ((array) ($etat['etiquettes'] ?? []) as $e) {
            $e = (array) $e;
            $parent = $e['parent'] ?? null;
            $etiquettes[] = ['id' => (int) $e['id'], 'nom' => (string) $e['nom'],
                'couleur' => (string) ($e['couleur'] ?? self::COULEUR_DEFAUT),
                'boite_reception' => (bool) ($e['boite_reception'] ?? false),
                'parent' => is_numeric($parent) ? (int) $parent : null,
                'actif' => (bool) ($e['actif'] ?? true)];
        }

        return $actifsSeuls ? array_values(array_filter($etiquettes, static fn (array $e): bool => $e['actif'])) : $etiquettes;
    }

    /**
     * L'étiquette de boîte de réception (au plus une attendue), ou null.
     *
     * @return array{id:int, nom:string, couleur:string, boite_reception:bool, parent:?int, actif:bool}|null
     */
    public function boiteReception(): ?array
    {
        foreach ($this->lister() as $e) {
            if ($e['boite_reception']) {
                return $e;
            }
        }

        return null;
    }

    /** Une parenté doit désigner une étiquette EXISTANTE et différente d'elle-même : jamais de cycle direct. */
    private function validerParent(?int $parent, ?int $id = null): ?int
    {
        if ($parent === null || $parent <= 0) {
            return null;
        }
        if ($id !== null && $parent === $id) {
            throw new RuntimeException('étiquette : une étiquette ne peut pas être sa propre parente');
        }
        if ($this->lire($parent) === null) {
            throw new RuntimeException("étiquette : parente introuvable ($parent)");
        }

        return $parent;
    }

    /** @param array<string, mixed> $champs */
    private function changer(int $id, array $champs): void
    {
        $trouvee = false;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $champs, &$trouvee): array {
            $etiquettes = (array) ($etat['etiquettes'] ?? []);
            foreach ($etiquettes as &$e) {
                $e = (array) $e;
                if ((int) ($e['id'] ?? 0) === $id) {
                    $trouvee = true;
                    foreach ($champs as $cle => $valeur) {
                        $e[$cle] = $valeur;
                    }
                }
            }

            return ['etiquettes' => $etiquettes];
        });
        if (!$trouvee) {
            throw new RuntimeException("étiquette : aucune étiquette nº $id");
        }
    }

    private static function validerNom(string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('étiquette : nom vide ou trop long (100 caractères au plus)');
        }

        return $nom;
    }

    private static function validerCouleur(string $couleur): string
    {
        $couleur = strtoupper(trim($couleur));
        if (preg_match('/^#[0-9A-F]{6}$/', $couleur) !== 1) {
            throw new RuntimeException("étiquette : couleur « $couleur » invalide (attendu #RRGGBB)");
        }

        return $couleur;
    }

    /** @param array<mixed> $etiquettes */
    private static function prochainId(array $etiquettes): int
    {
        $max = 0;
        foreach ($etiquettes as $e) {
            $max = max($max, (int) (((array) $e)['id'] ?? 0));
        }

        return $max + 1;
    }
}
