<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Dépôt des règles (niveau Paperless) : un déclencheur, des conditions, des actions. Les règles vivent
 * dans `workflow_definitions` (genre « regle »), avec les circuits : un seul moteur, une seule table.
 */
final class Regles
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> toutes les règles, décodées, dans l'ordre de création */
    public function toutes(): array
    {
        $lignes = $this->pdo->query("SELECT * FROM workflow_definitions WHERE genre = 'regle' ORDER BY id")->fetchAll();

        return array_map(fn (array $ligne): array => $this->decoder($ligne), $lignes);
    }

    /** @return list<array<string, mixed>> règles actives pour un déclencheur donné */
    public function applicables(string $evenement): array
    {
        return array_values(array_filter($this->toutes(),
            static fn (array $regle): bool => $regle['actif'] && $regle['declencheur'] === $evenement));
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        $requete = $this->pdo->prepare("SELECT * FROM workflow_definitions WHERE id = ? AND genre = 'regle'");
        $requete->execute([$id]);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $this->decoder($ligne);
    }

    /**
     * Crée une règle. `$regle` : nom, declencheur, conditions[], actions[], gabarit?, actif?, circuit_id?.
     *
     * @param array<string, mixed> $regle
     */
    public function creer(array $regle): int
    {
        $corps = json_encode(self::corpsDepuis($regle), JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare('INSERT INTO workflow_definitions (genre, nom, gabarit, actif, declencheur, corps)'
            . ' VALUES (?, ?, ?, ?, ?, ?)')->execute([
                'regle',
                (string) ($regle['nom'] ?? 'Règle sans nom'),
                isset($regle['gabarit']) ? (string) $regle['gabarit'] : null,
                !empty($regle['actif']) ? 1 : 0,
                (string) ($regle['declencheur'] ?? 'document_depose'),
                $corps,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Met à jour une règle existante (jamais de suppression) : nom, déclencheur, conditions, actions,
     * gabarit, circuit d'origine. L'identifiant reste stable, le journal n'est pas touché.
     *
     * @param array<string, mixed> $regle
     */
    public function mettreAJour(int $id, array $regle): void
    {
        $this->pdo->prepare("UPDATE workflow_definitions SET nom = ?, gabarit = ?, actif = ?, declencheur = ?, corps = ?"
            . " WHERE id = ? AND genre = 'regle'")->execute([
                (string) ($regle['nom'] ?? 'Règle sans nom'),
                isset($regle['gabarit']) && $regle['gabarit'] !== '' ? (string) $regle['gabarit'] : null,
                !empty($regle['actif']) ? 1 : 0,
                (string) ($regle['declencheur'] ?? 'document_depose'),
                json_encode(self::corpsDepuis($regle), JSON_UNESCAPED_UNICODE),
                $id,
            ]);
    }

    /** Active ou met en veille une règle (jamais de suppression). */
    public function basculer(int $id, bool $actif): void
    {
        $this->pdo->prepare("UPDATE workflow_definitions SET actif = ? WHERE id = ? AND genre = 'regle'")
            ->execute([$actif ? 1 : 0, $id]);
    }

    /**
     * @param array<string, mixed> $regle
     * @return array<string, mixed>
     */
    private static function corpsDepuis(array $regle): array
    {
        $corps = [
            'conditions' => array_values((array) ($regle['conditions'] ?? [])),
            'actions' => array_values((array) ($regle['actions'] ?? [])),
        ];
        if (isset($regle['circuit_id']) && (int) $regle['circuit_id'] > 0) {
            $corps['circuit_id'] = (int) $regle['circuit_id'];
        }

        return $corps;
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
            'gabarit' => $ligne['gabarit'] !== null ? (string) $ligne['gabarit'] : null,
            'actif' => (bool) $ligne['actif'],
            'declencheur' => (string) ($ligne['declencheur'] ?? ''),
            'conditions' => array_values((array) ($corps['conditions'] ?? [])),
            'actions' => array_values((array) ($corps['actions'] ?? [])),
            'circuit_id' => isset($corps['circuit_id']) ? (int) $corps['circuit_id'] : null,
        ];
    }
}
