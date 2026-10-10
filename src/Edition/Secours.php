<?php

declare(strict_types=1);

namespace Ged\Edition;

use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use RuntimeException;

/**
 * Espace de secours de l'application (F13) : la DERNIÈRE copie de travail de l'agent, hors du fonds
 * (jamais sous `STORAGE_ROOT`, donc jamais dans le dossier du client), une seule copie par document et
 * par appareil. Une copie de secours n'est PAS une version : elle ne touche ni le fichier courant, ni
 * `document_versions`, ni le `.meta.json`. Elle ne devient une version que si l'utilisateur la
 * RÉCUPÈRE explicitement (`recuperer()`), auquel cas le contenu courant est archivé comme toujours.
 *
 * Aucune table : chaque copie vit sous `SECOURS_ROOT/<document>/<appareil>/copie.<ext>` avec un
 * `copie.json` à côté (le dossier fait foi, comme le fonds).
 */
final class Secours
{
    private readonly string $racine;
    private readonly DepotFichiers $depot;

    public function __construct(private readonly Config $config)
    {
        $this->racine = rtrim(str_replace('\\', '/', $config->chemin('SECOURS_ROOT', 'var/secours')), '/');
        $this->depot = new DepotFichiers($config);
    }

    /** Racine absolue de l'espace de secours (toujours hors du fonds). */
    public function racine(): string
    {
        return $this->racine;
    }

    /**
     * Dépose (ou remplace) la copie de secours de ce document pour cet appareil.
     *
     * @return array{appareil:string, quand:string, empreinte:string, taille:int, nom:string, fichier:string, id:string}
     */
    public function deposer(int $document, string $appareil, string $cheminFichier): array
    {
        $doc = $this->depot->fiche($document);
        if ($doc === null || $doc['supprime_le'] !== null) {
            throw new RuntimeException("copie de secours : document $document introuvable dans le fonds");
        }
        if (!is_file($cheminFichier)) {
            throw new RuntimeException('copie de secours : fichier à sauvegarder introuvable');
        }
        $appareil = trim($appareil);
        if ($appareil === '') {
            throw new RuntimeException('copie de secours : appareil requis');
        }
        $id = $this->identifiantAppareil($appareil);
        $dossier = $this->racine . '/' . $document . '/' . $id;
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new RuntimeException("copie de secours : impossible de créer $dossier");
        }

        // Le nom de la copie garde l'extension du document rangé : la récupération retrouve ainsi un
        // type identique et `remplacerContenu()` l'accepte.
        $extension = strtolower(pathinfo((string) $doc['nom'], PATHINFO_EXTENSION));
        $fichier = 'copie' . ($extension !== '' ? '.' . $extension : '');
        $cible = $dossier . '/' . $fichier;
        $temporaire = $dossier . '/.copie-' . bin2hex(random_bytes(4)) . '.tmp';
        if (!copy($cheminFichier, $temporaire)) {
            throw new RuntimeException('copie de secours : copie impossible');
        }
        if (!rename($temporaire, $cible)) {
            @unlink($temporaire);
            throw new RuntimeException('copie de secours : écriture impossible');
        }

        $meta = [
            'id' => $id,
            'document' => $document,
            'appareil' => $appareil,
            'nom' => (string) $doc['nom'],
            'fichier' => $fichier,
            'empreinte' => Fichiers::empreinte($cible),
            'taille' => (int) filesize($cible),
            'quand' => date('Y-m-d H:i:s'),
        ];
        file_put_contents($dossier . '/copie.json', Fichiers::json($meta, true));

        return $meta;
    }

    /**
     * Copies de secours disponibles pour un document, la plus récente d'abord.
     *
     * @return list<array{id:string, appareil:string, quand:string, empreinte:string, taille:int, nom:string, fichier:string, taille_lisible:string}>
     */
    public function lister(int $document): array
    {
        $copies = [];
        foreach (glob($this->racine . '/' . $document . '/*/copie.json') ?: [] as $json) {
            $meta = json_decode((string) file_get_contents($json), true);
            if (!is_array($meta)) {
                continue;
            }
            $dossier = dirname($json);
            $id = basename($dossier);
            $fichier = $this->fichier($dossier, $meta);
            if ($fichier === null) {
                continue;   // copie incomplète : jamais proposée à la récupération
            }
            $taille = (int) ($meta['taille'] ?? filesize($fichier));
            $copies[] = [
                'id' => $id,
                'appareil' => (string) ($meta['appareil'] ?? $id),
                'quand' => (string) ($meta['quand'] ?? ''),
                'empreinte' => (string) ($meta['empreinte'] ?? Fichiers::empreinte($fichier)),
                'taille' => $taille,
                'nom' => (string) ($meta['nom'] ?? basename((string) $fichier)),
                'fichier' => basename($fichier),
                'taille_lisible' => $this->tailleLisible($taille),
            ];
        }
        usort($copies, static fn (array $a, array $b): int => strcmp($b['quand'], $a['quand']) ?: strcmp($a['id'], $b['id']));

        return $copies;
    }

    /**
     * Une copie précise d'un document, ou null (identifiant inconnu, hors format, ou copie incomplète).
     *
     * @return array<string, mixed>|null
     */
    public function copie(int $document, string $copie): ?array
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $copie);
        if ($id === '') {
            return null;
        }
        $dossier = $this->racine . '/' . $document . '/' . $id;
        $json = $dossier . '/copie.json';
        if (!is_file($json)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($json), true);
        if (!is_array($meta)) {
            return null;
        }
        $fichier = $this->fichier($dossier, $meta);
        if ($fichier === null) {
            return null;
        }

        return $meta + ['id' => $id, 'chemin' => $fichier];
    }

    /**
     * Récupère une copie de secours en NOUVELLE version (le Socle archive le contenu courant : rien
     * n'est écrasé). Rend le numéro de la version créée.
     */
    public function recuperer(int $document, string $copie, string $auteur): int
    {
        $meta = $this->copie($document, $copie);
        if ($meta === null || !isset($meta['chemin']) || !is_string($meta['chemin'])) {
            throw new RuntimeException('copie de secours : introuvable pour ce document');
        }

        return $this->depot->remplacerContenu($document, (string) $meta['chemin'], $auteur);
    }

    /** Identifiant d'appareil sûr pour un nom de dossier (slug, repli sur l'empreinte). */
    private function identifiantAppareil(string $appareil): string
    {
        $slug = Fichiers::slug($appareil);

        return $slug !== '' ? $slug : substr(hash('sha256', $appareil), 0, 16);
    }

    /**
     * Chemin absolu du fichier de copie : la colonne `fichier` du JSON, sinon le premier non-JSON.
     *
     * @param array<string, mixed> $meta
     */
    private function fichier(string $dossier, array $meta): ?string
    {
        // Le nom vient du `copie.json` (notre écriture) : `basename()` interdit toute évasion de dossier
        // si le fichier était modifié à la main.
        $nom = isset($meta['fichier']) && is_string($meta['fichier']) ? basename($meta['fichier']) : '';
        if ($nom !== '' && is_file($dossier . '/' . $nom)) {
            return $dossier . '/' . $nom;
        }
        foreach (glob($dossier . '/*') ?: [] as $fichier) {
            if (is_file($fichier) && !str_ends_with(strtolower($fichier), '.json')) {
                return $fichier;
            }
        }

        return null;
    }

    private function tailleLisible(int $octets): string
    {
        if ($octets < 1024) {
            return $octets . ' o';
        }
        if ($octets < 1024 * 1024) {
            return round($octets / 1024) . ' Ko';
        }

        return round($octets / (1024 * 1024), 1) . ' Mo';
    }
}
