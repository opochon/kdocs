<?php

declare(strict_types=1);

namespace Ged\Classement;

use PDO;
use Throwable;

/**
 * Apprentissage léger porté de GEDv1 : pour un même émetteur, N choix HUMAINS identiques et consécutifs
 * font une habitude. Elle prime alors sur la règle ou l'IA, vaut preuve pour valider seul, et un choix
 * contraire la casse (il faut de nouveau N choix concordants).
 *
 * Seuls les choix humains enseignent : le tri automatique n'appelle jamais `retenirChoix`.
 */
final class Habitudes
{
    public const CHAMP_TYPE = 'type';
    public const CHAMP_ETIQUETTES = 'etiquettes';

    private const SOURCE_HUMAIN = 'humain';

    public function __construct(private readonly PDO $pdo, private readonly int $seuil = 3)
    {
    }

    /** Nombre de choix identiques et consécutifs qui font une habitude. */
    public function seuil(): int
    {
        return max(2, $this->seuil);
    }

    /**
     * Retient le choix HUMAIN posé sur un document. Un même document ne pèse qu'une fois : s'il est
     * re-classé, son dernier choix remplace l'ancien et redevient le plus récent (revirement).
     */
    public function retenirChoix(string $emetteur, string $champ, string $valeur, string $documentRef): void
    {
        $cle = Regles::normaliser($emetteur);
        if ($cle === '') {
            return; // sans émetteur, pas d'habitude possible
        }
        $ref = trim($documentRef);
        if ($ref === '') {
            $ref = 'sans-reference';
        }

        $this->pdo->prepare(
            'INSERT INTO classement_choix (emetteur_cle, champ, valeur, document_ref, source, cree_le, maj_le)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE emetteur_cle = VALUES(emetteur_cle), valeur = VALUES(valeur),
                                     cree_le = NOW(), maj_le = NOW()'
        )->execute([$cle, $champ, mb_substr($valeur, 0, 512), mb_substr($ref, 0, 255), self::SOURCE_HUMAIN]);
    }

    /**
     * Habitude d'un émetteur pour un champ : la valeur si les N derniers choix humains concordent,
     * sinon null. L'ordre des choix est celui du journal (cree_le, id).
     *
     * @return array{valeur:string, choix:int, confiance:float}|null
     */
    public function habitude(string $emetteur, string $champ = self::CHAMP_TYPE): ?array
    {
        $cle = Regles::normaliser($emetteur);
        if ($cle === '') {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT valeur FROM classement_choix
                  WHERE emetteur_cle = ? AND champ = ? AND source = ?
                  ORDER BY cree_le DESC, id DESC LIMIT 20'
            );
            $stmt->execute([$cle, $champ, self::SOURCE_HUMAIN]);
            $valeurs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            // Table absente ou base indisponible : on classe sans apprentissage, jamais d'erreur bloquante.
            return null;
        }
        if ($valeurs === []) {
            return null;
        }

        $premiere = (string) $valeurs[0];
        $serie = 0;
        foreach ($valeurs as $valeur) {
            if ((string) $valeur !== $premiere) {
                break;
            }
            $serie++;
        }
        if ($serie < $this->seuil()) {
            return null;
        }

        return [
            'valeur' => $premiere,
            'choix' => $serie,
            // 3 choix => 0.90, 4 => 0.93… plafonné à 0.99
            'confiance' => min(0.99, 0.81 + 0.03 * $serie),
        ];
    }
}
