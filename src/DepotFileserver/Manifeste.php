<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

/**
 * Manifeste d'empreintes du dépôt : pour CHAQUE fichier, son sha256, sa taille et l'instant du
 * dernier relevé. C'est lui qui permet de détecter les modifications, les déplacements et les
 * suppressions faites hors GED, et de retrouver où un contenu est parti (recherche par empreinte).
 *
 * Il vit dans le dossier interne `.<dépôt>/.depot-fileserver/empreintes.json` : le dossier fait foi,
 * aucun index en base.
 */
final class Manifeste
{
    public function __construct(private readonly string $racine)
    {
    }

    public function cheminFichier(): string
    {
        return $this->racine . '/' . Arborescence::INTERNE . '/empreintes.json';
    }

    /**
     * @return array{genere_le:?string, fichiers:array<string, array<string,mixed>>}
     */
    public function lire(): array
    {
        $fichier = $this->cheminFichier();
        if (!is_file($fichier)) {
            return ['genere_le' => null, 'fichiers' => []];
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);
        if (!is_array($donnees) || !is_array($donnees['fichiers'] ?? null)) {
            return ['genere_le' => null, 'fichiers' => []];
        }

        /** @var array<string, array<string,mixed>> $fichiers */
        $fichiers = $donnees['fichiers'];

        return ['genere_le' => isset($donnees['genere_le']) ? (string) $donnees['genere_le'] : null, 'fichiers' => $fichiers];
    }

    /**
     * @param array<string, array<string,mixed>> $fichiers
     */
    public function ecrire(array $fichiers, ?string $instant): void
    {
        Arborescence::assurerDossier(dirname($this->cheminFichier()));
        $contenu = Arborescence::json(['genere_le' => $instant, 'fichiers' => $fichiers], true) . "\n";
        $temporaire = $this->cheminFichier() . '.tmp';
        if (file_put_contents($temporaire, $contenu) === false || !@rename($temporaire, $this->cheminFichier())) {
            @unlink($temporaire);
            throw new \RuntimeException('manifeste : écriture impossible dans ' . dirname($this->cheminFichier()));
        }
    }

    /** Nombre de fichiers au manifeste (0 si absent). */
    public function compte(): int
    {
        return count($this->lire()['fichiers']);
    }
}
