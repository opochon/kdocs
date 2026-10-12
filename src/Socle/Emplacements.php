<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Registre des EMPLACEMENTS de classement (modèle Paperless `StoragePath`) : un nom lisible et un
 * gabarit de chemin RELATIF au fonds, où les documents d'un type/réglage sont rangés. Il vit dans le
 * fonds (`.ged/emplacements.json`) — le dossier fait foi — et ne crée ni ne déplace aucun document :
 * c'est un référentiel de destinations, comme la nomenclature ou les étiquettes.
 *
 * Zéro suppression (règle 4) : un emplacement se met en veille (`actif = false`), il reste lisible.
 * Le chemin est validé (relatif, sans `..`, sans racine) : un gabarit ne peut pas sortir du fonds.
 */
final class Emplacements
{
    public const FICHIER = 'emplacements';

    private const PLAFOND_CHEMIN = 512;

    /**
     * Placeholders admis dans un gabarit de chemin (ceux du contexte de `Ingestion\GabaritChemin`, plus
     * les alias français). Un placeholder inconnu est REFUSÉ (400), comme le test Paperless
     * `test_api_create_invalid_storage_path` : « {correspdent} » n'est pas « {correspondent} ».
     */
    private const PLACEHOLDERS = [
        'title', 'titre', 'correspondent', 'correspondant', 'document_type', 'type',
        'asn', 'owner_username', 'proprietaire', 'original_name', 'doc_pk',
        'created', 'created_year', 'created_year_short', 'created_month', 'created_month_name',
        'created_month_name_short', 'created_day',
        'added', 'added_year', 'added_year_short', 'added_month', 'added_month_name',
        'added_month_name_short', 'added_day', 'tag_list', 'checksum',
    ];

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /** @return array{id:int, nom:string, chemin:string, actif:bool} */
    public function ajouter(string $nom, string $chemin): array
    {
        $nom = self::validerNom($nom);
        $chemin = self::validerChemin($chemin);
        foreach ($this->lister(false) as $e) {
            if (mb_strtolower($e['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("emplacement : « $nom » existe déjà");
            }
        }
        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($nom, $chemin, &$id): array {
            $entrees = (array) ($etat['emplacements'] ?? []);
            $id = self::prochainId($entrees);
            $entrees[] = ['id' => $id, 'nom' => $nom, 'chemin' => $chemin, 'actif' => true];

            return ['emplacements' => $entrees];
        });

        return $this->lire($id) ?? throw new RuntimeException('emplacement : création non relue');
    }

    public function renommer(int $id, string $nom): void
    {
        $this->changer($id, ['nom' => self::validerNom($nom)]);
    }

    public function definirChemin(int $id, string $chemin): void
    {
        $this->changer($id, ['chemin' => self::validerChemin($chemin)]);
    }

    public function definirActif(int $id, bool $actif): void
    {
        $this->changer($id, ['actif' => $actif]);
    }

    /** @return array{id:int, nom:string, chemin:string, actif:bool}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister(false) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /** @return list<array{id:int, nom:string, chemin:string, actif:bool}> */
    public function lister(bool $actifsSeuls = true): array
    {
        $entrees = [];
        foreach ((array) ($this->registre->lire(self::FICHIER)['emplacements'] ?? []) as $e) {
            $e = (array) $e;
            $entrees[] = ['id' => (int) $e['id'], 'nom' => (string) $e['nom'],
                'chemin' => (string) ($e['chemin'] ?? ''), 'actif' => (bool) ($e['actif'] ?? true)];
        }

        return $actifsSeuls ? array_values(array_filter($entrees, static fn (array $e): bool => $e['actif'])) : $entrees;
    }

    private static function validerNom(string $nom): string
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 100) {
            throw new RuntimeException('emplacement : nom vide ou trop long (100 caractères au plus)');
        }

        return $nom;
    }

    private static function validerChemin(string $chemin): string
    {
        $chemin = trim(str_replace('\\', '/', $chemin), '/');
        if ($chemin === '' || mb_strlen($chemin) > self::PLAFOND_CHEMIN) {
            throw new RuntimeException('emplacement : chemin vide ou trop long (512 caractères au plus)');
        }
        foreach (explode('/', $chemin) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('emplacement : le chemin ne peut pas sortir du fonds (« .. » interdit)');
            }
        }
        if (preg_match_all('/\{([^{}]*)\}/', $chemin, $trouves) > 0) {
            foreach ($trouves[1] as $placeholder) {
                $nom = trim($placeholder);
                if (!in_array($nom, self::PLACEHOLDERS, true)) {
                    throw new RuntimeException("emplacement : placeholder inconnu « {" . $nom . "} » (gabarit de chemin invalide)");
                }
            }
        }

        return $chemin;
    }

    /** @param array<string, mixed> $champs */
    private function changer(int $id, array $champs): void
    {
        if ($this->lire($id) === null) {
            throw new RuntimeException("emplacement : aucun emplacement nº $id");
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $champs): array {
            $entrees = (array) ($etat['emplacements'] ?? []);
            foreach ($entrees as &$e) {
                $e = (array) $e;
                if ((int) ($e['id'] ?? 0) === $id) {
                    foreach ($champs as $cle => $valeur) {
                        $e[$cle] = $valeur;
                    }
                }
            }

            return ['emplacements' => $entrees];
        });
    }

    /** @param array<mixed> $entrees */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $e) {
            $max = max($max, (int) (((array) $e)['id'] ?? 0));
        }

        return $max + 1;
    }
}
