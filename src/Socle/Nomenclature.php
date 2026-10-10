<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Nomenclature des types de documents (V2-04) : les six types livrés par le plan, modifiables par
 * l'administrateur (ajouter, renommer, désactiver — jamais supprimer : des documents vivent avec ces
 * valeurs, et le dossier fait foi). C'est LA lecture de référence pour les autres secteurs ; le
 * Classement branchera ses règles dessus au lieu de sa constante TYPES_CONNUS.
 *
 * Elle vit dans le fonds (`.ged/nomenclature.json`, via Registre), pas en base : le plafond de
 * tables de l'installateur (20, oracle V2-01) est déjà plein, et la configuration suit ainsi le
 * dossier — une reconstruction (F12) ou un déménagement du fonds l'emporte avec lui.
 */
final class Nomenclature
{
    /** Les types livrés, dans l'ordre du plan. L'administrateur peut ensuite les adapter dans le fonds. */
    public const TYPES_DEFAUT = ['Facture', 'Note de crédit', 'Reçu', 'Contrat', 'Courrier', 'Document RH',
        'Décompte', 'Relevé', 'Attestation', 'Police d’assurance', 'Avis d’impôt', 'Décision'];

    /** Registre du fonds qui porte la nomenclature (types et champs : un même document de référence). */
    private const FICHIER = 'nomenclature';

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Pose la graine si le fonds n'a pas encore de nomenclature : idempotent, rejouable sur un fonds
     * existant. La transformation relit l'état SOUS VERROU : deux amorçages croisés (serveur +
     * installation, sans le verrou de la base de test) ne posent la graine qu'une fois.
     */
    public function assurer(): void
    {
        if ($this->registre->lire(self::FICHIER) !== []) {
            return;
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat): array {
            if ($etat !== []) {
                return $etat;   // un concurrent l'a posée entre-temps : elle reste telle quelle
            }

            return ['types' => array_map(
                static fn (string $nom, int $i): array => ['id' => $i + 1, 'nom' => $nom, 'actif' => true],
                self::TYPES_DEFAUT, array_keys(self::TYPES_DEFAUT),
            ), 'champs' => []];
        });
    }

    /** @return list<string> noms des types ACTIFS, dans l'ordre de création */
    public function types(): array
    {
        return array_column($this->lister(), 'nom');
    }

    /** @return list<array{id:int, nom:string, actif:bool}> */
    public function lister(bool $actifsSeuls = true): array
    {
        $types = $this->contenu()['types'];

        return $actifsSeuls ? array_values(array_filter($types, static fn (array $t): bool => $t['actif'])) : $types;
    }

    /** Ajoute un type ; refuse un nom vide, trop long, ou homonyme (casse et espaces mis à part). */
    public function ajouter(string $nom): int
    {
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 64) {
            throw new RuntimeException('type : nom vide ou trop long (64 caractères au plus)');
        }
        foreach ($this->lister(false) as $t) {
            if (mb_strtolower($t['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("type : « $nom » existe déjà dans la nomenclature");
            }
        }

        $id = 0;
        $this->transformer(static function (array $etat) use ($nom, &$id): array {
            // Id attribué SOUS VERROU : le suivant du plus grand posé — l'équivalent déterministe de
            // l'auto-incrément, sans table. Capturé par référence pour être rendu à l'appelant.
            $id = self::prochainId($etat['types']);
            $etat['types'][] = ['id' => $id, 'nom' => $nom, 'actif' => true];

            return $etat;
        });

        return $id;
    }

    /** Renomme : le nom affiché change, les documents déjà rangés gardent leur valeur dans le dossier. */
    public function renommer(int $id, string $nom): void
    {
        $this->exigerConnu($id);
        $nom = trim($nom);
        if ($nom === '' || mb_strlen($nom) > 64) {
            throw new RuntimeException('type : nom vide ou trop long (64 caractères au plus)');
        }
        foreach ($this->lister(false) as $t) {
            if ($t['id'] !== $id && mb_strtolower($t['nom']) === mb_strtolower($nom)) {
                throw new RuntimeException("type : « $nom » existe déjà dans la nomenclature");
            }
        }
        $this->transformer(static function (array $etat) use ($id, $nom): array {
            foreach ($etat['types'] as &$t) {
                if ($t['id'] === $id) {
                    $t['nom'] = $nom;
                }
            }

            return $etat;
        });
    }

    /** Désactive (ou réactive) : un type désactivé n'est plus proposé, mais reste dans la nomenclature. */
    public function definirActif(int $id, bool $actif): void
    {
        $this->exigerConnu($id);
        $this->transformer(static function (array $etat) use ($id, $actif): array {
            foreach ($etat['types'] as &$t) {
                if ($t['id'] === $id) {
                    $t['actif'] = $actif;
                }
            }

            return $etat;
        });
    }

    /** Identifiant du type portant ce nom (exact), ou null — pour rattacher un champ. */
    public function idDuType(string $nom): ?int
    {
        foreach ($this->lister(false) as $t) {
            if ($t['nom'] === $nom) {
                return $t['id'];
            }
        }

        return null;
    }

    /**
     * La nomenclature complète (types et champs), normalisée : ids entiers, actifs booléens,
     * options en liste. C'est la forme interne partagée avec Champs — un seul fichier, une seule
     * lecture par requête.
     *
     * @return array{types:list<array{id:int, nom:string, actif:bool}>, champs:list<array{id:int, type_id:int,
     *               cle:string, nom:string, nature:string, options:list<string>}>}
     */
    public function contenu(): array
    {
        $brut = $this->registre->lire(self::FICHIER);
        $types = [];
        foreach ((array) ($brut['types'] ?? []) as $t) {
            $t = (array) $t;
            $types[] = ['id' => (int) $t['id'], 'nom' => (string) $t['nom'], 'actif' => (bool) ($t['actif'] ?? true)];
        }
        $champs = [];
        foreach ((array) ($brut['champs'] ?? []) as $c) {
            $c = (array) $c;
            $champs[] = ['id' => (int) $c['id'], 'type_id' => (int) $c['type_id'], 'cle' => (string) $c['cle'],
                'nom' => (string) $c['nom'], 'nature' => (string) $c['nature'],
                'options' => array_values(array_map('strval', (array) ($c['options'] ?? [])))];
        }

        return ['types' => $types, 'champs' => $champs];
    }

    /**
     * Réécrit la nomenclature au complet sous verrou, depuis une transformation de son contenu
     * normalisé. Rend l'état final — Champs y lit l'id qui vient d'être attribué.
     *
     * @param callable(array{types:list<array<string, mixed>>, champs:list<array<string, mixed>>}): array<string, mixed> $transformation
     * @return array{types:list<array<string, mixed>>, champs:list<array<string, mixed>>}
     */
    public function transformer(callable $transformation): array
    {
        return $this->registre->modifier(self::FICHIER, static fn (array $etat): array => $transformation($etat === [] ? ['types' => [], 'champs' => []] : $etat));
    }

    /**
     * Le prochain identifiant libre d'une liste : un de plus que le plus grand posé.
     *
     * @param list<array<string, mixed>> $entrees
     */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $e) {
            $max = max($max, (int) ($e['id'] ?? 0));
        }

        return $max + 1;
    }

    private function exigerConnu(int $id): void
    {
        foreach ($this->lister(false) as $t) {
            if ($t['id'] === $id) {
                return;
            }
        }
        throw new RuntimeException("type : aucun type nº $id dans la nomenclature");
    }
}
