<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * `WorkflowRun` à la manière de GEDv2 : CHAQUE exécution d'un workflow est tracée (workflow, type de
 * déclencheur, document, date d'exécution). La trace vit dans `workflow_journal`, en AJOUT SEUL : aucune
 * mise à jour, aucune suppression — c'est le même journal que les règles et les circuits, jamais un
 * second mécanisme.
 */
final class Executions
{
    private readonly Journal $journal;
    private readonly PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->journal = new Journal($pdo);
    }

    /** Enregistre une exécution et rend son identifiant de journal. */
    public function enregistrer(int $workflowId, int $type, int $documentId, \DateTimeImmutable $runAt, string $auteur = 'workflow'): int
    {
        return $this->journal->ajouter([
            'document_id' => $documentId,
            'definition_id' => $workflowId,
            'genre' => 'execution',
            'auteur' => $auteur,
            'horodatage' => $runAt,
            'details' => ['type' => $type, 'document' => $documentId, 'run_at' => $runAt->format('c')],
        ]);
    }

    /**
     * Dernière exécution connue d'un workflow sur un document (pour la récurrence planifiée).
     */
    public function derniere(int $workflowId, int $documentId): ?\DateTimeImmutable
    {
        $requete = $this->pdo->prepare("SELECT horodatage FROM workflow_journal"
            . " WHERE definition_id = ? AND document_id = ? AND genre = 'execution' ORDER BY id DESC LIMIT 1");
        $requete->execute([$workflowId, $documentId]);
        $valeur = $requete->fetchColumn();
        if (!is_string($valeur) || $valeur === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Exécutions récentes d'un workflow, de la plus récente à la plus ancienne.
     *
     * @return list<array<string, mixed>>
     */
    public function pourWorkflow(int $workflowId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        $requete = $this->pdo->prepare("SELECT * FROM workflow_journal"
            . " WHERE definition_id = ? AND genre = 'execution' ORDER BY id DESC LIMIT $limite");
        $requete->execute([$workflowId]);
        $lignes = [];
        foreach ($requete->fetchAll() as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }
            $details = json_decode((string) ($ligne['details'] ?? '{}'), true);
            $lignes[] = [
                'id' => (int) $ligne['id'],
                'workflow_id' => $ligne['definition_id'] !== null ? (int) $ligne['definition_id'] : null,
                'type' => (int) ($details['type'] ?? 0),
                'document_id' => $ligne['document_id'] !== null ? (int) $ligne['document_id'] : null,
                'run_at' => (string) ($ligne['horodatage'] ?? ''),
                'auteur' => (string) ($ligne['auteur'] ?? ''),
            ];
        }

        return $lignes;
    }
}
