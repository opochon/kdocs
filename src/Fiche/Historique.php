<?php

declare(strict_types=1);

namespace Ged\Fiche;

use Ged\Base;
use Ged\Config;
use PDO;
use Throwable;

/**
 * Historique d'un objet : versions archivées et déplacements, lus du FONDS via l'index (le dossier fait
 * foi). Équivalent GEDv2 de `GET /objects/{type}/{objectid}/history` : le plus récent d'abord, chaque
 * entrée venant d'une ligne réelle de `document_versions` ou `document_deplacements` — aucune date ni
 * aucun geste inventé.
 *
 * Une base muette rend une liste vide et TRACE la panne : la fiche dit « aucun déplacement », elle ne
 * tombe jamais en 500.
 */
final class Historique
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param PDO|null $pdo connexion partagée déjà ouverte par l'appelant (`Base::pdo`), ou null pour l'ouvrir.
     *
     * @return list<array{quand:string, quoi:string, nature:string}>
     */
    public function pour(int $idDocument, ?PDO $pdo = null): array
    {
        if ($idDocument < 1) {
            return [];
        }
        if ($pdo === null) {
            try {
                $pdo = Base::pdo($this->config);
            } catch (Throwable $e) {
                error_log(sprintf('[GEDv2 fiche] historique indisponible (base) pour le document %d : %s', $idDocument, $e->getMessage()));

                return [];
            }
        }

        try {
            $lignes = [];
            foreach ($this->deplacements($pdo, $idDocument) as $deplacement) {
                $lignes[] = $deplacement;
            }
            foreach ($this->versions($pdo, $idDocument) as $version) {
                $lignes[] = $version;
            }
            usort($lignes, static fn (array $a, array $b): int => strcmp($b['quand'], $a['quand']));
        } catch (Throwable $e) {
            error_log(sprintf('[GEDv2 fiche] historique illisible pour le document %d : %s', $idDocument, $e->getMessage()));

            return [];
        }

        return $lignes;
    }

    /**
     * @return list<array{quand:string, quoi:string, nature:string}>
     */
    private function deplacements(PDO $pdo, int $idDocument): array
    {
        $requete = $pdo->prepare('SELECT chemin_avant, chemin_apres, cause, auteur, date_deplacement'
            . ' FROM document_deplacements WHERE document_id = ?');
        $requete->execute([$idDocument]);

        return array_map(static fn (array $d): array => [
            'quand' => (string) $d['date_deplacement'],
            'quoi' => (string) $d['cause'] === 'restauration'
                ? 'Restauration : nouvelle version créée (' . (string) $d['auteur'] . ')'
                : 'Déplacé : ' . (string) $d['chemin_avant'] . ' → ' . (string) $d['chemin_apres']
                    . ' (' . (string) $d['cause'] . ', ' . (string) $d['auteur'] . ')',
            'nature' => 'deplacement',
        ], $requete->fetchAll());
    }

    /**
     * @return list<array{quand:string, quoi:string, nature:string}>
     */
    private function versions(PDO $pdo, int $idDocument): array
    {
        $requete = $pdo->prepare('SELECT numero, nom_archive, cree_le FROM document_versions WHERE document_id = ?');
        $requete->execute([$idDocument]);

        return array_map(static fn (array $v): array => [
            'quand' => (string) $v['cree_le'],
            'quoi' => 'Version v' . (int) $v['numero'] . ' archivée (' . (string) $v['nom_archive'] . ')',
            'nature' => 'version',
        ], $requete->fetchAll());
    }
}
