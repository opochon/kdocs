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
    public function ajouter(string $nom, string $couleur = self::COULEUR_DEFAUT, bool $boiteReception = false): array
    {
        $nom = self::validerNom($nom);
        $couleur = self::validerCouleur($couleur);
        foreach ($this->lister() as $e) {
            if (mb_strtolower($e['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("étiquette : « $nom » existe déjà");
            }
        }
        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $couleur, $boiteReception, &$id): array {
            $etiquettes = (array) ($etat['etiquettes'] ?? []);
            $id = self::prochainId($etiquettes);
            $etiquettes[] = ['id' => $id, 'nom' => $nom, 'couleur' => $couleur, 'boite_reception' => $boiteReception];

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

    /** @return array{id:int, nom:string, couleur:string, boite_reception:bool}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister() as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /** @return list<array{id:int, nom:string, couleur:string, boite_reception:bool}> */
    public function lister(): array
    {
        $etat = $this->registre->lire(self::FICHIER);
        $etiquettes = [];
        foreach ((array) ($etat['etiquettes'] ?? []) as $e) {
            $e = (array) $e;
            $etiquettes[] = ['id' => (int) $e['id'], 'nom' => (string) $e['nom'],
                'couleur' => (string) ($e['couleur'] ?? self::COULEUR_DEFAUT), 'boite_reception' => (bool) ($e['boite_reception'] ?? false)];
        }

        return $etiquettes;
    }

    /**
     * L'étiquette de boîte de réception (au plus une attendue), ou null.
     *
     * @return array{id:int, nom:string, couleur:string, boite_reception:bool}|null
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
