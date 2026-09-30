<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Contrats\Depot;
use Ged\Ingestion\SourceDossier;
use PDO;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Dépôt direct dans un dossier du fonds (section 2 du contrat CMM, `destination=dossier`).
 *
 * Le fichier est rangé par le contrat `Depot` SANS découpe ni classement automatique : le dossier demandé
 * est conservé tel quel, le statut reste `a_controler`. Un `.eml` est lu ici pour que son texte (en-têtes
 * + corps) accompagne le document dans le `.meta.json` : l'index plein texte le trouvera sans OCR.
 * Idempotent : même empreinte = même document, le nouveau fichier est mis de côté, jamais supprimé.
 */
final class DepotDossier
{
    public const SOUS_DOSSIER = 'dossiers';

    public function __construct(
        private readonly Depot $depot,
        private readonly string $zoneTravail,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param array<string, mixed> $meta métadonnées déjà lues (origine CMM comprise)
     * @return array{document:int, empreinte:string}
     */
    public function deposer(UploadedFileInterface $fichier, string $nomOrigine, array $meta, string $dossier, string $etape): array
    {
        $chemin = $this->ecrire($fichier, $nomOrigine);
        $empreinte = (string) hash_file('sha256', $chemin);

        $existant = $this->documentParEmpreinte($empreinte);
        if ($existant !== null) {
            $this->mettreDeCote($chemin);

            return ['document' => $existant, 'empreinte' => $empreinte];
        }

        $meta['titre'] ??= pathinfo($nomOrigine, PATHINFO_FILENAME);
        if (Courriel::estMessage($nomOrigine)) {
            $courriel = Courriel::lire($chemin);
            if ($courriel !== null) {
                $meta['texte'] = $courriel['texte'];
                $meta['type'] ??= 'courriel';
                $meta['pieces_jointes'] = array_column($courriel['pieces'], 'nom');
            }
        }
        // `dossier` est honoré par le Socle (rangement direct) ; `statut` fixe l'état de contrôle.
        $meta['dossier'] = $dossier;
        $meta['statut'] = 'a_controler';

        $id = $this->depot->deposer($chemin, $meta);

        return ['document' => $id, 'empreinte' => $empreinte];
    }

    private function documentParEmpreinte(string $empreinte): ?int
    {
        try {
            $requete = $this->pdo->prepare('SELECT id FROM documents WHERE empreinte = ? AND supprime_le IS NULL ORDER BY id DESC LIMIT 1');
            $requete->execute([$empreinte]);
            $id = $requete->fetchColumn();

            return $id === false ? null : (int) $id;
        } catch (\PDOException) {
            return null;   // index absent : on dépose quand même, le dossier fait foi
        }
    }

    private function ecrire(UploadedFileInterface $fichier, string $nomOrigine): string
    {
        $dossier = $this->zoneTravail . '/' . self::SOUS_DOSSIER;
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('zone de travail des dépôts inutilisable : ' . $dossier);
        }
        $nom = SourceDossier::nomSur($nomOrigine);
        $cible = $dossier . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $nom;
        $partiel = $cible . '.partiel';
        try {
            $fichier->moveTo($partiel);
        } catch (\Throwable $e) {
            throw new \RuntimeException('écriture du dépôt impossible : ' . $e->getMessage());
        }
        if (!rename($partiel, $cible)) {
            throw new \RuntimeException('dépôt non finalisé : ' . $cible);
        }

        return $cible;
    }

    private function mettreDeCote(string $chemin): void
    {
        $cible = $this->zoneTravail . '/' . self::SOUS_DOSSIER . '/doublons';
        if (!is_dir($cible) && !mkdir($cible, 0775, true) && !is_dir($cible)) {
            throw new \RuntimeException('dossier des doublons inutilisable : ' . $cible);
        }
        @rename($chemin, $cible . '/' . basename($chemin));
    }
}
