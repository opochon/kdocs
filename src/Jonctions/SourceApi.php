<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Ingestion\Entree;
use Ged\Ingestion\Source;
use Ged\Ingestion\SourceDossier;
use Ged\Ingestion\Travaux;
use PDO;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Source d'ingestion alimentée par l'API d'entrée : un dépôt poussé par une application est écrit dans la
 * zone de travail, puis passé à `Travaux::recevoir` — exactement le même dédoublonnage que le dossier
 * d'ingestion. Même empreinte = même travail : le nouveau fichier est mis de côté, jamais supprimé.
 *
 * Les métadonnées facultatives accompagnent la copie de travail dans un petit fichier
 * `<fichier>.depot.json` (le dossier fait foi) ; elles seront rattachées au document par la chaîne
 * d'ingestion quand le secteur Ingestion aura branché cette source (voir la demande dans sa fiche).
 * Les fichiers présents sans travail (reprise après panne entre l'écriture et l'enregistrement) sont
 * rendus par `relever()` et entrent alors dans la chaîne normale.
 */
final class SourceApi implements Source
{
    public const SOURCE = 'api';
    public const DOSSIER_DOUBLONS = 'doublons';
    public const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'rtf', 'txt', 'eml', 'msg'];

    public function __construct(
        private readonly string $zoneTravail,   // sous-dossier dédié de la zone de travail (hors du fonds)
        private readonly Travaux $travaux,
        private readonly PDO $pdo,
    ) {
    }

    /** Extension normalisée si le type est accepté, sinon null. */
    public static function extensionAutorisee(string $nom): ?string
    {
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));

        return in_array($ext, self::EXTENSIONS, true) ? $ext : null;
    }

    /**
     * Réceptionne un fichier poussé : copie stable dans la zone de travail puis enregistrement idempotent.
     *
     * @param array<string, mixed> $metadonnees
     * @return array{travail:int, empreinte:string} travail d'ingestion (le même si l'empreinte est connue)
     */
    public function recevoir(UploadedFileInterface $fichier, string $nomOrigine, array $metadonnees = [], ?string $application = null): array
    {
        $chemin = $this->ecrire($fichier, $nomOrigine);
        $entree = new Entree($chemin, self::SOURCE, $nomOrigine);
        $id = $this->travaux->recevoir($entree);

        if ($id === null) {
            $existant = $this->travailParEmpreinte($entree->empreinte);
            $this->mettreDeCote($chemin);
            if ($existant === null) {
                throw new \RuntimeException('doublon sans travail existant : ' . $entree->empreinte);
            }

            return ['travail' => $existant, 'empreinte' => $entree->empreinte];
        }
        $this->ecrireAccompagnement($chemin, $application, $metadonnees);

        return ['travail' => $id, 'empreinte' => $entree->empreinte];
    }

    /**
     * Fichiers de la zone API pas encore enregistrés comme travail (reprise d'une écriture interrompue).
     *
     * @return list<Entree>
     */
    public function relever(): array
    {
        if (!is_dir($this->zoneTravail)) {
            return [];
        }
        $entrees = [];
        foreach (glob($this->zoneTravail . '/*') ?: [] as $fichier) {
            if (!is_file($fichier) || self::extensionAutorisee($fichier) === null) {
                continue;   // .partiel, .depot.json, dossier doublons : jamais des documents
            }
            $empreinte = hash_file('sha256', $fichier);
            if ($empreinte === false || $this->travailParEmpreinte($empreinte) !== null) {
                continue;   // déjà enregistré : la chaîne d'ingestion s'en occupe
            }
            $entrees[] = new Entree($fichier, self::SOURCE, basename($fichier));
        }

        return $entrees;
    }

    /** @return array{application:?string, metadonnees:array<string, mixed>, recu_le:?string} */
    public function lireAccompagnement(string $chemin): array
    {
        $fichier = $chemin . '.depot.json';
        $vide = ['application' => null, 'metadonnees' => [], 'recu_le' => null];
        if (!is_file($fichier)) {
            return $vide;
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);

        return is_array($donnees) ? [
            'application' => isset($donnees['application']) ? (string) $donnees['application'] : null,
            'metadonnees' => is_array($donnees['metadonnees'] ?? null) ? $donnees['metadonnees'] : [],
            'recu_le' => isset($donnees['recu_le']) ? (string) $donnees['recu_le'] : null,
        ] : $vide;
    }

    public function travailParEmpreinte(string $empreinte): ?int
    {
        $requete = $this->pdo->prepare('SELECT id FROM ingestion_travaux WHERE empreinte = ? AND parent_id = 0 LIMIT 1');
        $requete->execute([$empreinte]);
        $id = $requete->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function ecrire(UploadedFileInterface $fichier, string $nomOrigine): string
    {
        if (!is_dir($this->zoneTravail) && !mkdir($this->zoneTravail, 0775, true) && !is_dir($this->zoneTravail)) {
            throw new \RuntimeException('zone de travail de l’API inutilisable : ' . $this->zoneTravail);
        }
        $nom = SourceDossier::nomSur($nomOrigine);
        $cible = $this->zoneTravail . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . $nom;
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
        $cible = $this->zoneTravail . '/' . self::DOSSIER_DOUBLONS;
        if (!is_dir($cible) && !mkdir($cible, 0775, true) && !is_dir($cible)) {
            throw new \RuntimeException('dossier des doublons inutilisable : ' . $cible);
        }
        if (!@rename($chemin, $cible . '/' . basename($chemin))) {
            throw new \RuntimeException('doublon non mis de côté : ' . $chemin);
        }
    }

    /** @param array<string, mixed> $metadonnees */
    private function ecrireAccompagnement(string $chemin, ?string $application, array $metadonnees): void
    {
        $contenu = json_encode([
            'application' => $application,
            'metadonnees' => $metadonnees,
            'recu_le' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $fichier = $chemin . '.depot.json';
        $partiel = $fichier . '.partiel';
        if (file_put_contents($partiel, $contenu) !== false) {
            @rename($partiel, $fichier);
        }
    }
}
