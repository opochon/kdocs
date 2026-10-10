<?php

declare(strict_types=1);

namespace Ged\Edition;

use PDO;

/**
 * Ouverture informative d'un document depuis un poste (F13, point 3). AUCUNE nouvelle table : une
 * ouverture est un événement de `document_collaboration` (`type = 'evenement'`, `action = 'ouverture'`)
 * — l'auteur est l'identifiant du compte, le poste son `appareil` de jeton, le début de l'ouverture
 * `cree_le`, et le bail (`expire_le`) le dernier signal + 10 minutes. Sans signal depuis 10 minutes, le
 * bail est échu : l'ouverture est « peut-être abandonnée ». La fermeture pose `retire_le` (trace
 * conservée, jamais un DELETE).
 *
 * Le poste est TOUJOURS celui du jeton d'appareil, jamais une valeur du corps de requête : l'agent ne
 * peut pas se faire passer pour un autre poste.
 */
final class Ouvertures
{
    /** Durée de vie d'un signal : au-delà, l'ouverture est considérée comme peut-être abandonnée. */
    public const DELAI_SIGNAL_S = 600;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ouvre (ou rafraîchit) l'ouverture de ce poste pour ce document. Idempotent : un second appel du
     * même couple document + compte + poste garde la même ligne et repousse le bail, donc l'heure
     * d'ouverture affichée ne repart pas de zéro à chaque battement.
     *
     * @return array{id:int, auteur:string, poste:string, depuis:string}
     */
    public function ouvrir(int $document, string $auteur, string $poste, ?int $maintenant = null): array
    {
        $maintenant ??= time();
        $auteur = $this->borner($auteur, 128);
        $poste = $this->borner($poste, 128);
        $expire = date('Y-m-d H:i:s', $maintenant + self::DELAI_SIGNAL_S);

        $requete = $this->pdo->prepare("SELECT id, cree_le FROM document_collaboration
            WHERE document_id = ? AND type = 'evenement' AND action = 'ouverture'
              AND auteur = ? AND libelle = ? AND retire_le IS NULL ORDER BY id DESC LIMIT 1");
        $requete->execute([$document, $auteur, $poste]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);

        if ($ligne === false) {
            $this->pdo->prepare("INSERT INTO document_collaboration (document_id, type, action, auteur, libelle, expire_le)
                VALUES (?, 'evenement', 'ouverture', ?, ?, ?)")->execute([$document, $auteur, $poste, $expire]);
            $id = (int) $this->pdo->lastInsertId();
            $depuis = date('Y-m-d H:i:s', $maintenant);
        } else {
            $id = (int) $ligne['id'];
            $this->pdo->prepare('UPDATE document_collaboration SET expire_le = ? WHERE id = ?')->execute([$expire, $id]);
            $depuis = (string) $ligne['cree_le'];
        }

        return ['id' => $id, 'auteur' => $auteur, 'poste' => $poste, 'depuis' => $depuis];
    }

    /** Prolonge l'ouverture active de ce poste. Faux si aucune ouverture active (l'agent réouvrira). */
    public function signal(int $document, string $auteur, string $poste, ?int $maintenant = null): bool
    {
        $maintenant ??= time();
        $auteur = $this->borner($auteur, 128);
        $poste = $this->borner($poste, 128);
        // On lit d'abord la ligne : `rowCount` d'un UPDATE MySQL compte les lignes CHANGÉES, et repousser
        // le bail à la même seconde ne change rien (deux signaux dans la même seconde sont légitimes).
        $requete = $this->pdo->prepare("SELECT id FROM document_collaboration
            WHERE document_id = ? AND type = 'evenement' AND action = 'ouverture'
              AND auteur = ? AND libelle = ? AND retire_le IS NULL ORDER BY id DESC LIMIT 1");
        $requete->execute([$document, $auteur, $poste]);
        $id = $requete->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->pdo->prepare('UPDATE document_collaboration SET expire_le = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s', $maintenant + self::DELAI_SIGNAL_S), (int) $id]);

        return true;
    }

    /** Ferme l'ouverture active de ce poste (trace : le registre garde l'heure d'ouverture et de fermeture). */
    public function fermer(int $document, string $auteur, string $poste, ?int $maintenant = null): bool
    {
        $maintenant ??= time();
        $date = date('Y-m-d H:i:s', $maintenant);
        $requete = $this->pdo->prepare("UPDATE document_collaboration SET retire_le = ?, expire_le = ?
            WHERE document_id = ? AND type = 'evenement' AND action = 'ouverture'
              AND auteur = ? AND libelle = ? AND retire_le IS NULL");
        $requete->execute([$date, $date, $document, $this->borner($auteur, 128), $this->borner($poste, 128)]);

        return $requete->rowCount() >= 1;
    }

    /**
     * L'ouverture affichée pour un document : la plus récente non fermée, active ou peut-être abandonnée.
     *
     * @return array{id:int, auteur:string, poste:string, depuis:string, abandonnee:bool}|null
     */
    public function active(int $document, ?int $maintenant = null): ?array
    {
        $maintenant ??= time();
        $requete = $this->pdo->prepare("SELECT id, auteur, libelle, cree_le, expire_le FROM document_collaboration
            WHERE document_id = ? AND type = 'evenement' AND action = 'ouverture' AND retire_le IS NULL
            ORDER BY cree_le DESC, id DESC LIMIT 1");
        $requete->execute([$document]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false) {
            return null;
        }

        return $this->ligneVersEtat($ligne, $maintenant);
    }

    /**
     * Les ouvertures affichables d'une page de documents, en UNE requête (la Bibliothèque ne fait pas
     * une requête par ligne). La plus récente par document gagne.
     *
     * @param list<int> $documents
     * @return array<int, array{id:int, auteur:string, poste:string, depuis:string, abandonnee:bool}>
     */
    public function pourDocuments(array $documents, ?int $maintenant = null): array
    {
        $documents = array_values(array_filter(array_map('intval', $documents), static fn (int $id): bool => $id > 0));
        if ($documents === []) {
            return [];
        }
        $maintenant ??= time();
        $marques = implode(', ', array_fill(0, count($documents), '?'));
        $requete = $this->pdo->prepare("SELECT document_id, id, auteur, libelle, cree_le, expire_le
            FROM document_collaboration
            WHERE type = 'evenement' AND action = 'ouverture' AND retire_le IS NULL
              AND document_id IN ($marques) ORDER BY cree_le ASC, id ASC");
        $requete->execute($documents);

        $carte = [];
        foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $carte[(int) $ligne['document_id']] = $this->ligneVersEtat($ligne, $maintenant);
        }

        return $carte;
    }

    /**
     * @param array<string, mixed> $ligne
     * @return array{id:int, auteur:string, poste:string, depuis:string, abandonnee:bool}
     */
    private function ligneVersEtat(array $ligne, int $maintenant): array
    {
        $expire = (string) ($ligne['expire_le'] ?? '');

        return [
            'id' => (int) $ligne['id'],
            'auteur' => (string) $ligne['auteur'],
            'poste' => (string) ($ligne['libelle'] ?? ''),
            'depuis' => (string) $ligne['cree_le'],
            'abandonnee' => $expire !== '' && $expire < date('Y-m-d H:i:s', $maintenant),
        ];
    }

    private function borner(string $valeur, int $maximum): string
    {
        return mb_substr(trim($valeur), 0, $maximum);
    }
}
