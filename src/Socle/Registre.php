<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Registre de la configuration GED DANS LE FONDS (V2-04) : la nomenclature (types et champs) et les
 * vues enregistrées vivent dans un dossier réservé `.ged/` à la racine documentaire — jamais en
 * base, le plafond de tables de l'installateur (20, oracle V2-01) est déjà plein et « le dossier
 * fait foi » : la configuration suit le fonds, survivant à toute reconstruction (F12).
 *
 * Le dossier `.ged/` est interne (nom en point : l'indexeur, le reconstructeur et Fonds l'ignorent,
 * comme `.versions/` ou `.index/`), ses fichiers sont du JSON canonique indenté — lisibles à la main,
 * comme les `.meta.json`.
 *
 * Deux garanties :
 * - LECTURE : cache en mémoire rafraîchi par la signature du fichier (mtime + taille) — une lecture
 *   par requête quand rien ne change, mais un autre processus (préparation d'oracle, second serveur)
 *   qui réécrit le fichier est vu immédiatement ;
 * - ÉCRITURE : atomique (temporaire puis renommage — un lecteur ne voit jamais un JSON à moitié
 *   écrit) et sous verrou dédié (deux administrateurs ne se marchent pas dessus : la dernière
 *   écriture repart de l'état le plus frais, jamais d'un état lu avant le verrou).
 */
final class Registre
{
    /** Dossier réservé à la racine du fonds. Commence par un point : jamais un dossier de documents. */
    public const DOSSIER = '.ged';

    /** Cache de lecture, par fichier absolu : ['signature', valeur] — la signature détecte l'écriture d'un autre processus. */
    /** @var array<string, array{signature:string, valeur:array<string, mixed>}> */
    private static array $cache = [];

    private readonly string $dossier;

    public function __construct(Config $config)
    {
        $racine = rtrim(str_replace('\\', '/', $config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
        $this->dossier = $racine . '/' . self::DOSSIER;
    }

    /** Le contenu d'un registre, ou le défaut s'il n'existe pas encore (la lecture n'écrit jamais). */
    public function lire(string $nom, array $defaut = []): array
    {
        $fichier = $this->chemin($nom);
        $signature = self::signature($fichier);

        if (($cache = self::$cache[$fichier] ?? null) !== null && $cache['signature'] === $signature) {
            return $cache['valeur'];   // rien n'a changé depuis la dernière lecture de CE processus
        }
        $valeur = $signature === '' ? $defaut : self::decoder($fichier);
        self::$cache[$fichier] = ['signature' => $signature, 'valeur' => $valeur];

        return $valeur;
    }

    /**
     * Modifie un registre SOUS VERROU : le contenu est relu frais (jamais du cache), passé à la
     * transformation, réécrit atomiquement. Une transformation qui lève n'écrit rien.
     *
     * @param callable(array<string, mixed>): mixed $transformation reçoit l'état frais, rend l'état nouveau
     *        (PHP n'impose pas le type de retour d'un callable : le `is_array` ci-dessous est un vrai
     *        garde-fou, pas une redondance)
     * @return array<string, mixed> l'état final, tel qu'écrit
     */
    public function modifier(string $nom, callable $transformation, array $defaut = []): array
    {
        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new RuntimeException('registre : impossible de créer le dossier ' . $this->dossier);
        }
        Fichiers::cacher($this->dossier);

        $fichier = $this->chemin($nom);
        $verrou = fopen($this->dossier . '/.verrou', 'c');
        if ($verrou === false || !flock($verrou, LOCK_EX)) {
            throw new RuntimeException("registre : verrou indisponible pour « $nom »");
        }
        try {
            $etat = self::signature($fichier) === '' ? $defaut : self::decoder($fichier);
            $nouveau = $transformation($etat);
            if (!is_array($nouveau)) {
                throw new RuntimeException("registre : la transformation de « $nom » n'a pas rendu un tableau");
            }

            $temporaire = $fichier . '.tmp';
            if (file_put_contents($temporaire, Fichiers::json($nouveau, true)) === false) {
                throw new RuntimeException("registre : écriture impossible pour « $nom »");
            }
            if (!rename($temporaire, $fichier)) {
                @unlink($temporaire);
                throw new RuntimeException("registre : publication impossible pour « $nom »");
            }

            // Le cache rend l'état écrit : la signature relue couvre le renommage (mtime neuf).
            self::$cache[$fichier] = ['signature' => self::signature($fichier), 'valeur' => $nouveau];

            return $nouveau;
        } finally {
            flock($verrou, LOCK_UN);
            fclose($verrou);
        }
    }

    /** Vide le cache de lecture (les tests changent de fonds dans un même processus PHP). */
    public static function oublier(): void
    {
        self::$cache = [];
    }

    /** Chemin absolu du fichier d'un registre. */
    private function chemin(string $nom): string
    {
        return $this->dossier . '/' . basename($nom) . '.json';
    }

    /** Signature d'état d'un fichier : horodatages + taille, '' s'il n'existe pas. */
    private static function signature(string $fichier): string
    {
        $etat = @stat($fichier);

        // mtime ET ctime : toute publication passe par un renommage, qui renouvelle les deux —
        // deux écritures dans la même seconde (à la taille égale) restent donc distinguées.
        return $etat === false ? '' : $etat['mtime'] . ':' . $etat['ctime'] . ':' . $etat['size'];
    }

    /** @return array<string, mixed> */
    private static function decoder(string $fichier): array
    {
        $decode = json_decode((string) file_get_contents($fichier), true);
        if (!is_array($decode)) {
            throw new RuntimeException("registre : « " . basename($fichier) . " » n'est pas un JSON lisible"
                . ' — le fichier fait foi, il ne sera pas écrasé en silence : le corriger à la main ou le supprimer');
        }

        return $decode;
    }
}
