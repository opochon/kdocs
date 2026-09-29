<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Journal du secteur : AJOUT SEUL. Il trace l'exécution des règles, chaque transition (qui, quand,
 * depuis quel état), les notifications, les jonctions demandées, les refus et les erreurs.
 * Le temps écrit vient de l'horloge injectée, jamais de `NOW()`.
 */
final class Journal
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{document_id?:int|null, definition_id?:int|null, instance_id?:int|null, genre:string,
     *              de_etat?:string|null, vers_etat?:string|null, auteur?:string,
     *              horodatage:\DateTimeImmutable|string, details?:array<string,mixed>} $entree
     */
    public function ajouter(array $entree): int
    {
        $horodatage = $entree['horodatage'] instanceof \DateTimeImmutable
            ? $entree['horodatage']->format('Y-m-d H:i:s') : (string) $entree['horodatage'];

        $this->pdo->prepare('INSERT INTO workflow_journal (document_id, definition_id, instance_id, genre, de_etat,'
            . ' vers_etat, auteur, horodatage, details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                isset($entree['document_id']) ? (int) $entree['document_id'] : null,
                isset($entree['definition_id']) ? (int) $entree['definition_id'] : null,
                isset($entree['instance_id']) ? (int) $entree['instance_id'] : null,
                (string) $entree['genre'],
                isset($entree['de_etat']) ? (string) $entree['de_etat'] : null,
                isset($entree['vers_etat']) ? (string) $entree['vers_etat'] : null,
                (string) ($entree['auteur'] ?? 'systeme'),
                $horodatage,
                json_encode($entree['details'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function recents(int $limite = 20): array
    {
        return $this->lire('', [], $limite);
    }

    /** @return list<array<string, mixed>> */
    public function pourDocument(int $documentId, int $limite = 20): array
    {
        return $this->lire('WHERE document_id = ?', [$documentId], $limite);
    }

    /** @return list<array<string, mixed>> */
    public function parGenre(string $genre, int $limite = 20): array
    {
        return $this->lire('WHERE genre = ?', [$genre], $limite);
    }

    /**
     * @param list<mixed> $parametres
     * @return list<array<string, mixed>>
     */
    private function lire(string $filtre, array $parametres, int $limite): array
    {
        $limite = max(1, min(500, $limite));
        $requete = $this->pdo->prepare("SELECT * FROM workflow_journal $filtre ORDER BY id DESC LIMIT $limite");
        $requete->execute($parametres);

        $entrees = [];
        foreach ($requete->fetchAll() as $ligne) {
            $ligne['details'] = json_decode((string) ($ligne['details'] ?? 'null'), true) ?: [];
            $ligne['id'] = (int) $ligne['id'];
            $ligne['document_id'] = $ligne['document_id'] !== null ? (int) $ligne['document_id'] : null;
            $entrees[] = $ligne;
        }

        return $entrees;
    }
}
