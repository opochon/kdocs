<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use PDO;

/**
 * Journal des courriels traités (modele Paperless `ProcessedMail`), porté par la table `ingestion_messages`
 * enrichie par la migration v2 : compte + règle + dossier + uid identifient un message déjà vu, donc un
 * message n'est JAMAIS traité deux fois, même après une panne. La trace ne se supprime jamais : « purger »
 * marque la ligne, il ne l'efface pas (règle GEDv2, zéro suppression).
 */
final class Traites
{
    public const SUCCES = 'SUCCESS';
    public const SANS_CONSOMMATION = 'PROCESSED_WO_CONSUMPTION';
    public const ECHEC = 'FAILED';
    public const PURGE = 'purge';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function cle(string $compte, string $regle, string $dossier, string $uid): string
    {
        return 'courrier:' . hash('sha256', $compte . '|' . $regle . '|' . $dossier . '|' . $uid);
    }

    public function existe(string $compte, string $regle, string $dossier, string $uid): bool
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM ingestion_messages WHERE boite = ? AND message_id = ?');
        $requete->execute([$compte, $this->cle($compte, $regle, $dossier, $uid)]);

        return (bool) $requete->fetchColumn();
    }

    public function enregistrer(
        string $compte,
        string $regle,
        string $dossier,
        string $uid,
        string $sujet,
        \DateTimeImmutable $recu,
        string $statut,
        ?string $erreur,
        \DateTimeImmutable $traite,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO ingestion_messages (boite, message_id, pieces, regle, dossier, uid, sujet, recu_le, statut, erreur, traite_le)'
            . ' VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE statut = VALUES(statut), erreur = VALUES(erreur), traite_le = VALUES(traite_le)',
        )->execute([
            $compte,
            $this->cle($compte, $regle, $dossier, $uid),
            mb_substr($regle, 0, 190),
            mb_substr($dossier, 0, 190),
            mb_substr($uid, 0, 190),
            mb_substr($sujet, 0, 255),
            $recu->format('Y-m-d H:i:s'),
            $statut,
            $erreur,
            $traite->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Lignes du journal, les plus récentes d'abord. `$regle` et `$statut` filtrent à la manière des
     * `ProcessedMailFilterSet` de Paperless (égalité stricte).
     *
     * @return list<array<string, mixed>>
     */
    public function journal(int $limite = 100, ?string $regle = null, ?string $statut = null): array
    {
        $sql = 'SELECT id, boite, regle, dossier, uid, sujet, recu_le, statut, erreur, traite_le'
            . ' FROM ingestion_messages WHERE regle IS NOT NULL';
        $parametres = [];
        if ($regle !== null && $regle !== '') {
            $sql .= ' AND regle = ?';
            $parametres[] = $regle;
        }
        if ($statut !== null && $statut !== '') {
            $sql .= ' AND statut = ?';
            $parametres[] = $statut;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min($limite, 500));
        $requete = $this->pdo->prepare($sql);
        $requete->execute($parametres);

        /** @var list<array<string, mixed>> $lignes */
        $lignes = $requete->fetchAll(PDO::FETCH_ASSOC);

        return $lignes;
    }

    /**
     * Retire des lignes du journal au sens Paperless `bulk_delete` : GEDv2 ne supprime pas, il MARQUE. Le
     * statut passe à `purge`, les identifiants rendus sont ceux effectivement marqués.
     *
     * @param list<int> $identifiants
     * @return list<int>
     */
    public function purger(array $identifiants): array
    {
        $marques = [];
        $requete = $this->pdo->prepare('UPDATE ingestion_messages SET statut = ? WHERE id = ? AND regle IS NOT NULL');
        foreach ($identifiants as $identifiant) {
            $requete->execute([self::PURGE, (int) $identifiant]);
            if ($requete->rowCount() > 0) {
                $marques[] = (int) $identifiant;
            }
        }

        return $marques;
    }
}
