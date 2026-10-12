<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Dépôt des WORKFLOWS à la manière de Paperless (`Workflow` + `WorkflowTrigger` + `WorkflowAction`) :
 * un nom, un ordre, un état actif, des déclencheurs et des actions. Ils vivent dans la même table que les
 * règles et les circuits (`workflow_definitions`, genre « workflow ») : un seul moteur, aucune table de
 * plus (plafond de 21 tables). Jamais de suppression : une définition se met en veille.
 */
final class DepotWorkflows
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> triés par ordre puis identifiant */
    public function toutes(): array
    {
        $lignes = $this->pdo->query("SELECT * FROM workflow_definitions WHERE genre = 'workflow' ORDER BY ordre, id")->fetchAll();

        return array_map(fn (array $ligne): array => $this->decoder($ligne), is_array($lignes) ? $lignes : []);
    }

    /** @return list<array<string, mixed>> workflows actifs, dans l'ordre */
    public function actifs(): array
    {
        return array_values(array_filter($this->toutes(), static fn (array $w): bool => $w['actif'] === true));
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        $requete = $this->pdo->prepare("SELECT * FROM workflow_definitions WHERE id = ? AND genre = 'workflow'");
        $requete->execute([$id]);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $this->decoder($ligne);
    }

    /**
     * Crée un workflow. `$workflow` : nom, ordre?, actif?, triggers[], actions[].
     *
     * @param array<string, mixed> $workflow
     */
    public function creer(array $workflow): int
    {
        $this->pdo->prepare("INSERT INTO workflow_definitions (genre, nom, gabarit, actif, ordre, declencheur, corps)"
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                'workflow',
                $this->nom($workflow),
                null,
                !empty($workflow['actif']) ? 1 : 0,
                (int) ($workflow['ordre'] ?? 0),
                null,
                $this->corps($workflow),
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Met à jour un workflow existant (jamais de suppression). L'identifiant reste stable.
     *
     * @param array<string, mixed> $workflow
     */
    public function mettreAJour(int $id, array $workflow): void
    {
        $this->pdo->prepare("UPDATE workflow_definitions SET nom = ?, actif = ?, ordre = ?, corps = ?"
            . " WHERE id = ? AND genre = 'workflow'")->execute([
                $this->nom($workflow),
                !empty($workflow['actif']) ? 1 : 0,
                (int) ($workflow['ordre'] ?? 0),
                $this->corps($workflow),
                $id,
            ]);
    }

    /** Active ou met en veille (jamais de suppression). */
    public function basculer(int $id, bool $actif): void
    {
        $this->pdo->prepare("UPDATE workflow_definitions SET actif = ? WHERE id = ? AND genre = 'workflow'")
            ->execute([$actif ? 1 : 0, $id]);
    }

    /** Change l'ordre de passage d'un workflow. */
    public function definirOrdre(int $id, int $ordre): void
    {
        $this->pdo->prepare("UPDATE workflow_definitions SET ordre = ? WHERE id = ? AND genre = 'workflow'")
            ->execute([$ordre, $id]);
    }

    /**
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    private function decoder(array $ligne): array
    {
        $corps = json_decode((string) ($ligne['corps'] ?? '{}'), true);
        $corps = is_array($corps) ? $corps : [];

        return [
            'id' => (int) $ligne['id'],
            'nom' => (string) $ligne['nom'],
            'ordre' => (int) ($ligne['ordre'] ?? 0),
            'actif' => (bool) $ligne['actif'],
            'triggers' => array_values((array) ($corps['triggers'] ?? [])),
            'actions' => array_values((array) ($corps['actions'] ?? [])),
        ];
    }

    /** @param array<string, mixed> $workflow */
    private function nom(array $workflow): string
    {
        $nom = trim((string) ($workflow['nom'] ?? ''));
        if ($nom === '') {
            throw new ErreurWorkflow('workflow : le nom est requis');
        }

        return $nom;
    }

    /** @param array<string, mixed> $workflow */
    private function corps(array $workflow): string
    {
        return (string) json_encode([
            'triggers' => array_values((array) ($workflow['triggers'] ?? [])),
            'actions' => array_values((array) ($workflow['actions'] ?? [])),
        ], JSON_UNESCAPED_UNICODE);
    }
}
