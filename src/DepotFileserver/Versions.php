<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * Versions d'un fichier dans un dossier masqué `.<nom du fichier>` à côté de lui
 * (docs/ARCHIVAGE-FILESERVER.md). Chaque version est adressée par contenu : `vNNN_<empreinte8>.<ext>` ;
 * deux contenus égaux ne doublonnent jamais, et l'archive n'est jamais écrasée.
 *
 * Ces versions locales sont le CONFORT ; la garantie est le dépôt séparé (`DepotSepare`). L'appelant
 * (Veille) alimente les deux à chaque contenu nouveau.
 */
final class Versions
{
    public function dossier(string $absoluDocument): string
    {
        return Arborescence::dossierVersions($absoluDocument);
    }

    /**
     * Versions présentes, triées par numéro.
     *
     * @return list<array{numero:int, empreinte:string, fichier:string, taille:int}>
     */
    public function lister(string $absoluDocument): array
    {
        $dossier = $this->dossier($absoluDocument);
        $versions = [];
        foreach (glob($dossier . '/v*') ?: [] as $fichier) {
            if (preg_match('#/v(\d{3,})_([0-9a-f]{8,})#', str_replace('\\', '/', $fichier), $trouve) !== 1) {
                continue;
            }
            $versions[] = [
                'numero' => (int) $trouve[1],
                'empreinte' => (string) $trouve[2],
                'fichier' => str_replace('\\', '/', $fichier),
                'taille' => (int) (filesize($fichier) ?: 0),
            ];
        }
        usort($versions, static fn (array $a, array $b): int => $a['numero'] <=> $b['numero']);

        return $versions;
    }

    public function contient(string $absoluDocument, string $empreinte): bool
    {
        return $this->cheminVersion($absoluDocument, $empreinte) !== null;
    }

    /** Chemin de la version dont l'empreinte commence par `$empreinte`, ou null. */
    public function cheminVersion(string $absoluDocument, string $empreinte): ?string
    {
        foreach ($this->lister($absoluDocument) as $version) {
            if (str_starts_with($empreinte, $version['empreinte']) || str_starts_with($version['empreinte'], $empreinte)) {
                return $version['fichier'];
            }
        }

        return null;
    }

    /**
     * Archive le contenu ACTUEL du fichier comme version `$numero`, nommée par l'empreinte.
     * Idempotent : si ce contenu est déjà archivé, le fichier existant est rendu tel quel.
     */
    public function archiver(string $absoluDocument, int $numero, string $empreinte): string
    {
        $existant = $this->cheminVersion($absoluDocument, substr($empreinte, 0, 8));
        if ($existant !== null) {
            return $existant;
        }
        $dossier = $this->dossier($absoluDocument);
        Arborescence::assurerDossier($dossier);
        Arborescence::cacher($dossier);
        $extension = pathinfo($absoluDocument, PATHINFO_EXTENSION);
        $archive = sprintf('%s/v%03d_%s%s', $dossier, $numero, substr($empreinte, 0, 8), $extension !== '' ? '.' . $extension : '');
        if (!@copy($absoluDocument, $archive)) {
            throw new \RuntimeException('version : copie impossible vers ' . $archive);
        }

        return $archive;
    }

    /** Numéro à donner à la prochaine version (au moins 1). */
    public function numeroSuivant(string $absoluDocument): int
    {
        $versions = $this->lister($absoluDocument);

        return $versions === [] ? 1 : ((int) end($versions)['numero']) + 1;
    }

    /**
     * Restaure une version : le contenu actuel est d'abord archivé (aucune perte, aucune suppression),
     * puis la version choisie revient à la place du document. Rend l'empreinte restaurée.
     */
    public function restaurer(string $absoluDocument, string $empreinte): string
    {
        $source = $this->cheminVersion($absoluDocument, $empreinte);
        if ($source === null) {
            throw new \RuntimeException('version introuvable pour ' . basename($absoluDocument) . ' : ' . $empreinte);
        }
        if (is_file($absoluDocument)) {
            $this->archiver($absoluDocument, $this->numeroSuivant($absoluDocument), Arborescence::empreinte($absoluDocument));
        }
        if (!@copy($source, $absoluDocument)) {
            throw new \RuntimeException('restauration : copie impossible vers ' . $absoluDocument);
        }

        return Arborescence::empreinte($absoluDocument);
    }
}
