<?php

declare(strict_types=1);

namespace Ged\Permissions;

use Ged\Config;
use Ged\Socle\Registre;
use RuntimeException;

/**
 * Règles de permission dynamiques (famille M-Files « permissions dynamiques », lot
 * ged-mfiles-permissions-1).
 *
 * Une règle est NOMMÉE, PORTABLE et VERSIONNÉE dans le fonds (`.ged/permissions.json`, via
 * `Ged\Socle\Registre`) : elle suit un déménagement du dossier et survit à une reconstruction de
 * l'index (F12), comme la nomenclature et les vues. Aucun coffre propriétaire, aucune table de plus.
 *
 * Sémantique : une règle ne fait que RESTREINDRE. Ses conditions retiennent des documents (classe,
 * valeur de métadonnée, état) ; une capacité protégée (`voir`, `modifier`) n'est alors ouverte qu'aux
 * cibles listées (groupe, rôle, compte). Un administrateur (`*`) voit toujours tout. Un document hors
 * conditions n'est jamais touché : l'ACL de dossier et la garde centrale restent la première barrière.
 */
final class Regles
{
    public const NOM = 'permissions';

    /** @var list<string> */
    private const TYPES_CIBLE = ['groupe', 'role', 'compte'];

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Toutes les règles, actives ou non, dans l'ordre de création.
     *
     * @return list<array<string, mixed>>
     */
    public function toutes(): array
    {
        $etat = $this->registre->lire(self::NOM, ['version' => 1, 'regles' => []]);
        $regles = array_values(array_filter((array) ($etat['regles'] ?? []), 'is_array'));
        usort($regles, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        return array_map([$this, 'normaliser'], $regles);
    }

    /** @return list<array<string, mixed>> */
    public function actives(): array
    {
        return array_values(array_filter($this->toutes(), static fn (array $r): bool => $r['actif'] === true));
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        foreach ($this->toutes() as $regle) {
            if ($regle['id'] === $id) {
                return $regle;
            }
        }

        return null;
    }

    /**
     * Crée ou met à jour une règle nommée. Rend son identifiant stable. Aucune suppression : une
     * règle retirée de l'écran est seulement DÉSACTIVÉE (`desactiver`), elle reste relisible.
     *
     * @param array<string, mixed> $regle
     */
    public function enregistrer(array $regle, string $auteur): int
    {
        $propose = $this->valider($regle);
        $id = isset($regle['id']) && is_numeric($regle['id']) ? (int) $regle['id'] : 0;
        $horodatage = (new \DateTimeImmutable())->format('c');

        $final = null;
        $this->registre->modifier(self::NOM, function (array $etat) use ($propose, $id, $auteur, $horodatage, &$final): array {
            $regles = array_values(array_filter((array) ($etat['regles'] ?? []), 'is_array'));
            $existant = null;
            foreach ($regles as $index => $courante) {
                if ($id > 0 && (int) ($courante['id'] ?? 0) === $id) {
                    $existant = $index;
                    break;
                }
            }
            $identifiant = $existant !== null ? $id : $this->prochainId($regles);
            $final = $propose + [
                'id' => $identifiant,
                'auteur' => (string) ($existant !== null ? ($regles[$existant]['auteur'] ?? $auteur) : $auteur),
                'cree_le' => (string) ($existant !== null ? ($regles[$existant]['cree_le'] ?? $horodatage) : $horodatage),
                'maj_le' => $horodatage,
            ];
            if ($existant !== null) {
                $regles[$existant] = $final;
            } else {
                $regles[] = $final;
            }

            return ['version' => 1, 'regles' => array_values($regles)];
        }, ['version' => 1, 'regles' => []]);

        return (int) $final['id'];
    }

    /** Désactive une règle sans jamais la supprimer ; un identifiant inconnu est refusé nommément. */
    public function desactiver(int $id): void
    {
        $trouvee = false;
        $this->registre->modifier(self::NOM, function (array $etat) use ($id, &$trouvee): array {
            $regles = array_values(array_filter((array) ($etat['regles'] ?? []), 'is_array'));
            foreach ($regles as $index => $regle) {
                if ((int) ($regle['id'] ?? 0) === $id) {
                    $regles[$index]['actif'] = false;
                    $regles[$index]['maj_le'] = (new \DateTimeImmutable())->format('c');
                    $trouvee = true;
                }
            }

            return ['version' => 1, 'regles' => array_values($regles)];
        }, ['version' => 1, 'regles' => []]);

        if (!$trouvee) {
            throw new RuntimeException("règle de permission inconnue : $id");
        }
    }

    /**
     * Valide et normalise une règle. Toute incohérence lève une erreur NOMMÉE (étape, cause) : aucune
     * règle bancale n'entre dans le fonds.
     *
     * @param array<string, mixed> $regle
     * @return array<string, mixed>
     */
    private function valider(array $regle): array
    {
        $nom = trim((string) ($regle['nom'] ?? ''));
        if ($nom === '' || mb_strlen($nom) > 128) {
            throw new RuntimeException('règle de permission invalide : nom requis (128 caractères au plus)');
        }

        $brut = (array) ($regle['conditions'] ?? []);
        $classe = $this->texte($brut['classe'] ?? null);
        $etat = $this->texte($brut['etat'] ?? null);
        $champ = $this->texte($brut['champ'] ?? null);
        $valeur = $this->texte($brut['valeur'] ?? null);
        if ($champ !== null && preg_match('/^[A-Za-z0-9_-]+$/', $champ) !== 1) {
            throw new RuntimeException('règle de permission invalide : clé de métadonnée impossible');
        }
        if ($champ !== null && $valeur === null) {
            throw new RuntimeException('règle de permission invalide : valeur requise quand un champ est visé');
        }
        if ($champ === null) {
            $valeur = null;
        }

        $cibles = [];
        foreach ((array) ($regle['cibles'] ?? []) as $cible) {
            $cible = (array) $cible;
            $type = (string) ($cible['type'] ?? '');
            $valeurCible = $this->texte($cible['valeur'] ?? null);
            if (!in_array($type, self::TYPES_CIBLE, true) || $valeurCible === null) {
                throw new RuntimeException('règle de permission invalide : cible (groupe, rôle ou compte) complète requise');
            }
            $cibles[] = ['type' => $type, 'valeur' => $valeurCible];
        }
        if ($cibles === []) {
            throw new RuntimeException('règle de permission invalide : au moins une cible autorisée requise');
        }

        $voir = (bool) ($regle['voir'] ?? false);
        $modifier = (bool) ($regle['modifier'] ?? false);
        if (!$voir && !$modifier) {
            throw new RuntimeException('règle de permission invalide : la règle ne protège ni la lecture ni la modification');
        }

        return [
            'nom' => $nom,
            'actif' => !array_key_exists('actif', $regle) || (bool) $regle['actif'],
            'conditions' => ['classe' => $classe, 'champ' => $champ, 'valeur' => $valeur, 'etat' => $etat],
            'voir' => $voir,
            'modifier' => $modifier,
            'cibles' => $cibles,
        ];
    }

    /** @param array<string, mixed> $regle @return array<string, mixed> */
    private function normaliser(array $regle): array
    {
        $conditions = (array) ($regle['conditions'] ?? []);

        return [
            'id' => (int) ($regle['id'] ?? 0),
            'nom' => (string) ($regle['nom'] ?? ''),
            'actif' => (bool) ($regle['actif'] ?? true),
            'conditions' => [
                'classe' => $this->texte($conditions['classe'] ?? null),
                'champ' => $this->texte($conditions['champ'] ?? null),
                'valeur' => $this->texte($conditions['valeur'] ?? null),
                'etat' => $this->texte($conditions['etat'] ?? null),
            ],
            'voir' => (bool) ($regle['voir'] ?? false),
            'modifier' => (bool) ($regle['modifier'] ?? false),
            'cibles' => array_values(array_map(static fn ($cible): array => [
                'type' => (string) ((array) $cible)['type'],
                'valeur' => (string) ((array) $cible)['valeur'],
            ], array_filter((array) ($regle['cibles'] ?? []), 'is_array'))),
            'auteur' => (string) ($regle['auteur'] ?? ''),
            'cree_le' => (string) ($regle['cree_le'] ?? ''),
            'maj_le' => (string) ($regle['maj_le'] ?? ''),
        ];
    }

    /** @param list<array<string, mixed>> $regles */
    private function prochainId(array $regles): int
    {
        $max = 0;
        foreach ($regles as $regle) {
            $max = max($max, (int) ($regle['id'] ?? 0));
        }

        return $max + 1;
    }

    private function texte(mixed $valeur): ?string
    {
        if (is_int($valeur) || is_float($valeur)) {
            $valeur = (string) $valeur;
        }
        if (!is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }
}
