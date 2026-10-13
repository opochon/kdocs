<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * DÉPÔT SÉPARÉ : la garantie des versions, des fichiers déplacés et des fichiers supprimés hors GED.
 *
 * Le dossier masqué `.<fichier>` est le confort, à côté du document ; il peut être effacé par un
 * malveillant, il n'est donc PAS la garantie. Ce dépôt séparé (une autre racine, idéalement un autre
 * support) conserve chaque contenu adressé par son empreinte sha256, avec la correspondance vers
 * l'emplacement d'origine sur le stockage et les métadonnées associées. Aucune suppression : un
 * contenu déjà conservé est idempotent.
 *
 * Portable Windows/Linux : arborescence dérivée de l'empreinte, séparateurs `/`.
 */
final class DepotSepare
{
    public function __construct(private readonly string $racine)
    {
    }

    public function racine(): string
    {
        return rtrim(str_replace('\\', '/', $this->racine), '/');
    }

    /** Chemin de l'objet adressé par contenu (`objets/ab/cd/<sha256>`), partagé par tous les provenances. */
    public function cheminObjet(string $empreinte): string
    {
        return $this->racine() . '/objets/' . substr($empreinte, 0, 2) . '/' . substr($empreinte, 2, 2) . '/' . $empreinte;
    }

    public function aCopie(string $empreinte): bool
    {
        return $empreinte !== '' && is_file($this->cheminObjet($empreinte));
    }

    /**
     * Conserve le contenu d'un fichier dans le dépôt séparé (idempotent par empreinte) et met à jour
     * la correspondance vers son emplacement d'origine et ses métadonnées fournies.
     *
     * @param array<string,mixed> $metadonnees
     */
    public function conserver(string $absoluSource, string $empreinte, string $cheminOrigine, array $metadonnees, ?string $instant): void
    {
        if (!is_file($absoluSource)) {
            throw new \RuntimeException('dépôt séparé : source absente ' . $absoluSource);
        }
        $calculee = Arborescence::empreinte($absoluSource);
        if ($calculee !== $empreinte) {
            throw new \RuntimeException('dépôt séparé : empreinte incohérente pour ' . $cheminOrigine);
        }
        $objet = $this->cheminObjet($empreinte);
        if (!is_file($objet)) {
            Arborescence::assurerDossier(dirname($objet));
            if (!@copy($absoluSource, $objet)) {
                throw new \RuntimeException('dépôt séparé : copie impossible vers ' . $objet);
            }
        }
        $this->noterCorrespondance($empreinte, $cheminOrigine, (int) filesize($absoluSource), $metadonnees, $instant);
    }

    /**
     * @param array<string,mixed> $metadonnees
     */
    private function noterCorrespondance(string $empreinte, string $cheminOrigine, int $taille, array $metadonnees, ?string $instant): void
    {
        $fichier = $this->cheminCorrespondance($empreinte);
        $existante = is_file($fichier) ? json_decode((string) file_get_contents($fichier), true) : null;
        $vues = is_array($existante) && is_array($existante['vues'] ?? null) ? $existante['vues'] : [];
        $dejaVu = false;
        foreach ($vues as $vue) {
            if (($vue['chemin_origine'] ?? null) === $cheminOrigine && ($vue['vu_le'] ?? null) === $instant) {
                $dejaVu = true;
                break;
            }
        }
        if (!$dejaVu) {
            $vues[] = ['chemin_origine' => $cheminOrigine, 'taille' => $taille, 'vu_le' => $instant];
        }
        Arborescence::assurerDossier(dirname($fichier));
        $contenu = Arborescence::json([
            'empreinte' => $empreinte,
            'taille' => $taille,
            'meta' => $metadonnees,
            'vues' => $vues,
        ], true) . "\n";
        if (file_put_contents($fichier, $contenu) === false) {
            throw new \RuntimeException('dépôt séparé : correspondance non écrite ' . $fichier);
        }
    }

    public function cheminCorrespondance(string $empreinte): string
    {
        return $this->racine() . '/correspondance/' . $empreinte . '.json';
    }

    /** @return array<string,mixed>|null */
    public function correspondance(string $empreinte): ?array
    {
        $fichier = $this->cheminCorrespondance($empreinte);
        if (!is_file($fichier)) {
            return null;
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);

        return is_array($donnees) ? $donnees : null;
    }

    /** Combien de contenus distincts le dépôt séparé garantit. */
    public function compter(): int
    {
        $fichiers = glob($this->racine() . '/correspondance/*.json') ?: [];

        return count($fichiers);
    }

    /**
     * Emplacements d'origine connus pour une empreinte : « où est parti ce fichier ».
     *
     * @return list<string>
     */
    public function origines(string $empreinte): array
    {
        $correspondance = $this->correspondance($empreinte);
        if ($correspondance === null || !is_array($correspondance['vues'] ?? null)) {
            return [];
        }
        $origines = [];
        foreach ($correspondance['vues'] as $vue) {
            if (is_array($vue) && is_string($vue['chemin_origine'] ?? null)) {
                $origines[] = $vue['chemin_origine'];
            }
        }

        return array_values(array_unique($origines));
    }
}
