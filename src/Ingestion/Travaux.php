<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use PDO;

/** Accès à la table ingestion_travaux. Aucune suppression : un travail fini reste comme trace. */
final class Travaux
{
    public const MAX_ESSAIS = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return int|null id créé, ou null si ce fichier a déjà été reçu (doublon) */
    public function recevoir(Entree $e): ?int
    {
        $st = $this->pdo->prepare('INSERT IGNORE INTO ingestion_travaux (empreinte, source, nom_origine, chemin) VALUES (?, ?, ?, ?)');
        $st->execute([$e->empreinte, $e->source, $e->nomOrigine, $e->chemin]);

        return $st->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * @param list<int> $pages
     * @param array<string, mixed> $analyse
     */
    public function ajouterPiece(int $lotId, string $chemin, string $nomOrigine, string $source, array $pages, string $texte, array $analyse): ?int
    {
        $empreinte = (string) hash_file('sha256', $chemin);
        $st = $this->pdo->prepare('INSERT IGNORE INTO ingestion_travaux (empreinte, source, nom_origine, chemin, parent_id, pages, etat, analyse)
            VALUES (?, ?, ?, ?, ?, ?, \'pret\', ?)');
        $st->execute([$empreinte, $source, $nomOrigine, $chemin, $lotId, self::plages($pages),
            json_encode(['texte' => $texte] + $analyse, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);

        return $st->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * Réserve le prochain travail dans l'état donné (verrou court ; un verrou expiré est repris : une tâche tuée
     * ne bloque jamais un fichier).
     *
     * @return array<string, mixed>|null
     */
    public function reserver(string $etat, int $minutes = 15): ?array
    {
        $ids = $this->pdo->prepare('SELECT id FROM ingestion_travaux WHERE etat = ? AND (reserve_jusqua IS NULL OR reserve_jusqua < NOW()) ORDER BY id LIMIT 5');
        $ids->execute([$etat]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $st = $this->pdo->prepare('UPDATE ingestion_travaux SET reserve_jusqua = NOW() + INTERVAL ? MINUTE
                WHERE id = ? AND etat = ? AND (reserve_jusqua IS NULL OR reserve_jusqua < NOW())');
            $st->execute([$minutes, $id, $etat]);
            if ($st->rowCount() === 1) {
                $l = $this->pdo->prepare('SELECT * FROM ingestion_travaux WHERE id = ?');
                $l->execute([$id]);

                return $l->fetch() ?: null;
            }
        }

        return null;
    }

    public function passer(int $id, string $etat, ?int $documentId = null): void
    {
        $this->pdo->prepare('UPDATE ingestion_travaux SET etat = ?, document_id = COALESCE(?, document_id), reserve_jusqua = NULL, erreur = NULL WHERE id = ?')
            ->execute([$etat, $documentId, $id]);
    }

    /**
     * Un lot découpé est terminé quand toutes ses pièces sont rangées : il passe alors en « range » et cesse
     * d'être compté « en cours de lecture » (défaut trouvé par le banc de charge H-6).
     */
    public function clore(int $lotId): void
    {
        $q = $this->pdo->prepare("SELECT COUNT(*) FROM ingestion_travaux WHERE parent_id = ? AND etat <> 'range'");
        $q->execute([$lotId]);
        if ((int) $q->fetchColumn() === 0) {
            $this->pdo->prepare("UPDATE ingestion_travaux SET etat = 'range' WHERE id = ? AND etat = 'decoupe'")->execute([$lotId]);
        }
    }

    /** Un échec est rejoué au tic suivant ; au bout de MAX_ESSAIS, le travail passe en « erreur », nommée. */
    public function echouer(int $id, string $message): void
    {
        // MariaDB évalue les affectations dans l'ordre : `essais` est déjà incrémenté quand IF() le lit.
        $this->pdo->prepare('UPDATE ingestion_travaux SET essais = essais + 1, erreur = ?, reserve_jusqua = NULL,
            etat = IF(essais >= ?, \'erreur\', etat) WHERE id = ?')->execute([mb_substr($message, 0, 1000), self::MAX_ESSAIS, $id]);
    }

    /** @return array{en_cours:int, erreurs:int, lots:int} */
    public function bilan(): array
    {
        $r = $this->pdo->query("SELECT
            SUM(etat IN ('recu','decoupe','pret')) en_cours, SUM(etat = 'erreur') erreurs, SUM(parent_id = 0) lots
            FROM ingestion_travaux")->fetch();

        return ['en_cours' => (int) ($r['en_cours'] ?? 0), 'erreurs' => (int) ($r['erreurs'] ?? 0), 'lots' => (int) ($r['lots'] ?? 0)];
    }

    /**
     * [25,26,27,28,30] → "25-28,30"
     *
     * @param list<int> $pages
     */
    public static function plages(array $pages): string
    {
        sort($pages);
        $morceaux = [];
        $debut = $fin = null;
        foreach ($pages as $p) {
            if ($debut === null) {
                $debut = $fin = $p;
            } elseif ($p === $fin + 1) {
                $fin = $p;
            } else {
                $morceaux[] = $debut === $fin ? "$debut" : "$debut-$fin";
                $debut = $fin = $p;
            }
        }
        if ($debut !== null) {
            $morceaux[] = $debut === $fin ? "$debut" : "$debut-$fin";
        }

        return implode(',', $morceaux);
    }
}
