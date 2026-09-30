<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Securite\DroitsDossiers;
use PDO;

/**
 * Destinations que CE compte peut écrire (section 2 du contrat CMM). Les dossiers sont les dossiers ACL
 * du secteur Droits ; le droit est décidé par `DroitsDossiers` (héritage parent, refus par défaut).
 * Un dossier sans droit n'apparaît JAMAIS, et un dépôt dedans est refusé 403.
 */
final class Dossiers
{
    public function __construct(private readonly PDO $pdo, private readonly DroitsDossiers $droits)
    {
    }

    /**
     * Dossiers enfants directs du chemin `$parent` où ce compte peut écrire.
     * `parent` vide = la racine. `enfants` dit s'il existe un sous-dossier également autorisé.
     *
     * @return list<array{chemin:string, nom:string, enfants:bool}>
     */
    public function lister(?int $compteId, string $parent = ''): array
    {
        if ($compteId === null) {
            return [];   // pas de compte : refus par défaut
        }
        $autorises = $this->autorises($compteId);
        $parent = $this->normaliser($parent);

        $resultat = [];
        foreach (array_keys($autorises) as $chemin) {
            $reste = $this->reste($chemin, $parent);
            if ($reste === null || $reste === '' || str_contains($reste, '/')) {
                continue;   // hors du parent, le parent lui-même, ou un descendant plus profond
            }
            $resultat[$chemin] = ['chemin' => $chemin, 'nom' => $reste, 'enfants' => $this->aEnfant($autorises, $chemin)];
        }
        ksort($resultat);

        return array_values($resultat);
    }

    /** Nombre de dossiers où ce compte peut écrire (renvoyé par `GET /api/v1/appairage/moi`). */
    public function compterEcriture(?int $compteId): int
    {
        return $compteId === null ? 0 : count($this->autorises($compteId));
    }

    /** Le compte peut-il écrire dans ce dossier ? Refus par défaut (chemin inconnu, aucune règle). */
    public function ecritureAutorisee(?int $compteId, string $dossier): bool
    {
        if ($compteId === null) {
            return false;
        }
        $dossier = $this->normaliser($dossier);
        if ($dossier === '' || str_contains($dossier, '..')) {
            return false;
        }
        $id = $this->droits->dossierPourChemin($dossier);

        return $id !== null && $this->droits->autorise($compteId, $id, 'modifier');
    }

    /** @return array<string, int> chemin => identifiant de dossier ACL, pour les dossiers autorisés */
    private function autorises(int $compteId): array
    {
        $lignes = $this->pdo->query('SELECT id, chemin_relatif FROM securite_dossiers ORDER BY chemin_relatif')->fetchAll(PDO::FETCH_ASSOC);
        $autorises = [];
        foreach ($lignes as $ligne) {
            $chemin = $this->normaliser((string) $ligne['chemin_relatif']);
            if ($chemin === '') {
                continue;
            }
            if ($this->droits->autorise($compteId, (int) $ligne['id'], 'modifier')) {
                $autorises[$chemin] = (int) $ligne['id'];
            }
        }

        return $autorises;
    }

    /** @param array<string, int> $autorises */
    private function aEnfant(array $autorises, string $chemin): bool
    {
        foreach (array_keys($autorises) as $autre) {
            if (str_starts_with($autre, $chemin . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Partie du chemin sous `$parent`, ou null si le chemin n'est pas dans ce parent. */
    private function reste(string $chemin, string $parent): ?string
    {
        if ($parent === '') {
            return $chemin;
        }
        if ($chemin === $parent) {
            return '';
        }

        return str_starts_with($chemin, $parent . '/') ? substr($chemin, strlen($parent) + 1) : null;
    }

    private function normaliser(string $chemin): string
    {
        return trim(str_replace('\\', '/', $chemin), '/');
    }
}
