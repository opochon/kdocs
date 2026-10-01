<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Dépôt des circuits à états (niveau M-Files) : états, transitions (chacune avec sa permission) et
 * instances (un document dans un circuit, à un état, éventuellement assigné à un rôle).
 */
final class Circuits
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function toutes(): array
    {
        $lignes = $this->pdo->query("SELECT * FROM workflow_definitions WHERE genre = 'circuit' ORDER BY id")->fetchAll();

        return array_map(fn (array $ligne): array => $this->decoder($ligne), $lignes);
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        return $this->chercher('id', $id);
    }

    /** @return array<string, mixed>|null */
    public function parNom(string $nom): ?array
    {
        return $this->chercher('nom', $nom);
    }

    /** @return array<string, mixed>|null */
    public function parGabarit(string $gabarit): ?array
    {
        return $this->chercher('gabarit', $gabarit);
    }

    /** Crée un circuit : nom, etats[], transitions[], gabarit?, actif?, graphe? (disposition du designer). */
    public function creer(array $circuit): int
    {
        $corps = json_encode([
            'etats' => array_values((array) ($circuit['etats'] ?? [])),
            'transitions' => array_values((array) ($circuit['transitions'] ?? [])),
            'graphe' => is_array($circuit['graphe'] ?? null) ? $circuit['graphe'] : null,
        ], JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare('INSERT INTO workflow_definitions (genre, nom, gabarit, actif, declencheur, corps)'
            . ' VALUES (?, ?, ?, ?, NULL, ?)')->execute([
                'circuit',
                (string) ($circuit['nom'] ?? 'Circuit sans nom'),
                isset($circuit['gabarit']) ? (string) $circuit['gabarit'] : null,
                !empty($circuit['actif']) ? 1 : 0,
                $corps,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Met à jour un circuit existant (jamais de suppression) : nom, gabarit, actif, états, transitions,
     * disposition du designer. L'identifiant et l'historique des instances restent intacts.
     */
    public function mettreAJour(int $id, array $circuit): void
    {
        $corps = json_encode([
            'etats' => array_values((array) ($circuit['etats'] ?? [])),
            'transitions' => array_values((array) ($circuit['transitions'] ?? [])),
            'graphe' => is_array($circuit['graphe'] ?? null) ? $circuit['graphe'] : null,
        ], JSON_UNESCAPED_UNICODE);

        $this->pdo->prepare("UPDATE workflow_definitions SET nom = ?, gabarit = ?, actif = ?, corps = ?"
            . " WHERE id = ? AND genre = 'circuit'")->execute([
                (string) ($circuit['nom'] ?? 'Circuit sans nom'),
                isset($circuit['gabarit']) && $circuit['gabarit'] !== '' ? (string) $circuit['gabarit'] : null,
                !empty($circuit['actif']) ? 1 : 0,
                $corps,
                $id,
            ]);
    }

    /** L'instance d'un document dans un circuit, ou null. @return array<string, mixed>|null */
    public function instance(int $definitionId, int $documentId): ?array
    {
        $requete = $this->pdo->prepare('SELECT * FROM workflow_instances WHERE definition_id = ? AND document_id = ?');
        $requete->execute([$definitionId, $documentId]);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $this->decoderInstance($ligne);
    }

    /** Toutes les instances d'un document, avec leur circuit décodé. @return list<array<string, mixed>> */
    public function instancesDocument(int $documentId): array
    {
        $requete = $this->pdo->prepare('SELECT i.*, d.nom AS circuit_nom, d.gabarit AS circuit_gabarit, d.corps AS circuit_corps,'
            . ' d.actif AS circuit_actif FROM workflow_instances i JOIN workflow_definitions d ON d.id = i.definition_id'
            . ' WHERE i.document_id = ? AND d.genre = ? ORDER BY i.id');
        $requete->execute([$documentId, 'circuit']);

        $instances = [];
        foreach ($requete->fetchAll() as $ligne) {
            $instance = $this->decoderInstance($ligne);
            $corps = json_decode((string) ($ligne['circuit_corps'] ?? '{}'), true);
            $corps = is_array($corps) ? $corps : [];
            $instance['circuit'] = [
                'id' => (int) $ligne['definition_id'],
                'nom' => (string) $ligne['circuit_nom'],
                'gabarit' => $ligne['circuit_gabarit'] !== null ? (string) $ligne['circuit_gabarit'] : null,
                'actif' => (bool) $ligne['circuit_actif'],
                'etats' => array_values((array) ($corps['etats'] ?? [])),
                'transitions' => array_values((array) ($corps['transitions'] ?? [])),
                'graphe' => is_array($corps['graphe'] ?? null) ? $corps['graphe'] : null,
            ];
            $instances[] = $instance;
        }

        return $instances;
    }

    /** Démarre un document dans un circuit (une seule instance par couple circuit/document). */
    public function demarrer(int $definitionId, int $documentId, string $etatInitial, ?string $assigne): int
    {
        $this->pdo->prepare('INSERT INTO workflow_instances (definition_id, document_id, etat, assigne_a) VALUES (?, ?, ?, ?)')
            ->execute([$definitionId, $documentId, $etatInitial, $assigne]);

        return (int) $this->pdo->lastInsertId();
    }

    public function assigner(int $instanceId, string $role): void
    {
        $this->pdo->prepare('UPDATE workflow_instances SET assigne_a = ? WHERE id = ?')->execute([$role, $instanceId]);
    }

    public function changerEtat(int $instanceId, string $etat): void
    {
        $this->pdo->prepare('UPDATE workflow_instances SET etat = ? WHERE id = ?')->execute([$etat, $instanceId]);
    }

    /** La transition `de → vers` d'un circuit, ou null. @return array<string, mixed>|null */
    public function transition(int $definitionId, string $de, string $vers): ?array
    {
        $circuit = $this->parId($definitionId);
        if ($circuit === null) {
            return null;
        }
        foreach ($circuit['transitions'] as $transition) {
            if ((string) ($transition['de'] ?? '') === $de && (string) ($transition['vers'] ?? '') === $vers) {
                return $transition;
            }
        }

        return null;
    }

    /** L'état de départ d'un circuit, ou null. */
    public static function etatInitial(array $circuit): ?string
    {
        foreach ((array) ($circuit['etats'] ?? []) as $etat) {
            if (!empty($etat['initial'])) {
                return (string) $etat['nom'];
            }
        }

        return null;
    }

    /** Le circuit connaît-il cet état ? */
    public function aEtat(array $circuit, string $nom): bool
    {
        foreach ((array) ($circuit['etats'] ?? []) as $etat) {
            if ((string) ($etat['nom'] ?? '') === $nom) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed>|null */
    private function chercher(string $colonne, int|string $valeur): ?array
    {
        $requete = $this->pdo->prepare("SELECT * FROM workflow_definitions WHERE genre = 'circuit' AND `$colonne` = ? LIMIT 1");
        $requete->execute([$valeur]);
        $ligne = $requete->fetch();

        return $ligne === false ? null : $this->decoder($ligne);
    }

    /** @param array<string, mixed> $ligne @return array<string, mixed> */
    private function decoder(array $ligne): array
    {
        $corps = json_decode((string) ($ligne['corps'] ?? '{}'), true);
        $corps = is_array($corps) ? $corps : [];

        return [
            'id' => (int) $ligne['id'],
            'nom' => (string) $ligne['nom'],
            'gabarit' => $ligne['gabarit'] !== null ? (string) $ligne['gabarit'] : null,
            'actif' => (bool) $ligne['actif'],
            'etats' => array_values((array) ($corps['etats'] ?? [])),
            'transitions' => array_values((array) ($corps['transitions'] ?? [])),
            'graphe' => is_array($corps['graphe'] ?? null) ? $corps['graphe'] : null,
        ];
    }

    /** @param array<string, mixed> $ligne @return array<string, mixed> */
    private function decoderInstance(array $ligne): array
    {
        return [
            'id' => (int) $ligne['id'],
            'definition_id' => (int) $ligne['definition_id'],
            'document_id' => (int) $ligne['document_id'],
            'etat' => (string) $ligne['etat'],
            'assigne_a' => $ligne['assigne_a'] !== null ? (string) $ligne['assigne_a'] : null,
        ];
    }
}
