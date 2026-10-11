<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Apercu\Outils;

/**
 * Conversion d'un PDF ou d'une image en copie d'archivage PDF/A-2b via Ghostscript, mécanisme natif du
 * produit (doc officielle § 14.7 « Creating a PDF/A document », p. 315-316 de Ghostscript 10.02.1) :
 *
 *   gs -dPDFA=2 -dBATCH -dNOPAUSE -dNOOUTERSAVE -sColorConversionStrategy=RGB -sDEVICE=pdfwrite
 *      -dPDFACompatibilityPolicy=1 -sOutputFile=<copie> --permit-file-read=<srgb.icc>
 *      -f <PDFA_def.ps (ICC absolu)> <source>
 *
 * Le fichier `PDFA_def.ps` livré par Ghostscript (`lib/PDFA_def.ps`) fournit l'OutputIntent ; il est relu et
 * seulement réécrit avec le chemin ABSOLU du profil sRGB (`iccprofiles/srgb.icc`), car un chemin relatif
 * dépend du répertoire courant. Aucune ligne Ghostscript n'est recopiée dans le dépôt.
 *
 * Un échec rend une RAISON nommée (jamais une exception muette) ; l'appelant décide de garder le document.
 * Aucune copie partielle n'est publiée : on écrit dans un temporaire, publié par renommage seulement après
 * contrôle.
 */
final class ConversionPdfA
{
    public const MSG_ABSENT = 'copie d’archivage indisponible : Ghostscript absent';
    public const MSG_FORMAT = 'copie d’archivage indisponible : format non pris en charge';
    public const MSG_ICC = 'copie d’archivage indisponible : profil ICC sRGB introuvable';
    public const MSG_DEF = 'copie d’archivage indisponible : PDFA_def.ps introuvable';
    public const MSG_SORTIE = 'conversion PDF/A refusée : aucune sortie exploitable';
    public const MSG_CHIFFRE = 'conversion PDF/A refusée : le PDF source est chiffré (mot de passe requis)';

    private readonly ?string $ghostscript;
    private readonly ?string $profilIcc;
    private readonly ?string $definition;

    public function __construct(?string $ghostscript = null, ?string $profilIcc = null, ?string $definition = null)
    {
        $this->ghostscript = $ghostscript;
        $this->profilIcc = $profilIcc ?? self::trouver([], 'srgb.icc');
        $this->definition = $definition ?? self::trouver([], 'PDFA_def.ps');
    }

    public static function depuis(): self
    {
        return new self(Outils::ghostscript());
    }

    public function disponible(): bool
    {
        return $this->ghostscript !== null;
    }

    /**
     * Produit la copie PDF/A-2b de `$source` en `$sortie`. Rend null si la copie est conforme au contrôle
     * partiel, sinon une raison nommée. N'écrit jamais `$sortie` en cas d'échec.
     */
    public function convertir(string $source, string $sortie): ?string
    {
        if (!$this->disponible()) {
            return self::MSG_ABSENT;
        }
        if (!is_file($source)) {
            return 'copie d’archivage impossible : fichier source introuvable';
        }
        if ($this->profilIcc === null) {
            return self::MSG_ICC;
        }
        if ($this->definition === null) {
            return self::MSG_DEF;
        }
        $entree = $this->entree($source);
        if ($entree === null) {
            return self::MSG_FORMAT;
        }

        $temporaire = $sortie . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!is_dir(dirname($temporaire)) && !mkdir(dirname($temporaire), 0775, true) && !is_dir(dirname($temporaire))) {
            return 'copie d’archivage impossible : dossier de destination non créé';
        }
        $definition = $this->definitionAdaptee($source);

        try {
            $raison = $this->executer($entree, $temporaire, $definition);
            if ($raison !== null) {
                return $raison;
            }
            $controle = ControlePdfA::controler($temporaire);
            if (!$controle['ok']) {
                return 'conversion PDF/A refusée : ' . $controle['raison'];
            }
            if (!@rename($temporaire, $sortie)) {
                return 'copie d’archivage impossible : publication du fichier refusée';
            }
        } finally {
            if (is_file($temporaire)) {
                @unlink($temporaire);
            }
            if ($entree !== $source && is_file($entree)) {
                @unlink($entree);
            }
            if ($definition !== $this->definition && is_file($definition)) {
                @unlink($definition);
            }
        }

        return null;
    }

    /**
     * Le chemin d'entrée réellement remis à Ghostscript : le PDF lui-même, ou un PDF d'une page enveloppant
     * une image (Ghostscript ne lit pas une image seule). Rend null pour un format inconnu.
     */
    private function entree(string $source): ?string
    {
        $debut = (string) file_get_contents($source, false, null, 0, 5);
        if (str_starts_with($debut, '%PDF')) {
            return $source;
        }
        $image = EnveloppeImage::depuis($source);
        if ($image === null) {
            return null;
        }
        $temporaire = dirname($source) . '/' . bin2hex(random_bytes(6)) . '-image.pdf';
        if (file_put_contents($temporaire, $image) === false) {
            return null;
        }

        return $temporaire;
    }

    /**
     * Le `PDFA_def.ps` livré, avec le chemin ABSOLU du profil ICC et un titre propre. Rend un fichier
     * temporaire.
     */
    private function definitionAdaptee(string $source): string
    {
        $def = (string) file_get_contents((string) $this->definition);
        $def = str_replace('/ICCProfile (srgb.icc)', '/ICCProfile (' . str_replace('\\', '/', (string) $this->profilIcc) . ')', $def);
        $titre = self::titreSur($source);
        $def = str_replace('/Title (Title)', '/Title (' . $titre . ')', $def);
        $fichier = sys_get_temp_dir() . '/gedv2-pdfa-def-' . bin2hex(random_bytes(6)) . '.ps';
        file_put_contents($fichier, $def);

        return $fichier;
    }

    /** Titre PDF/A sobre et sûr : ASCII seul (les DOCINFO PostScript ne sont pas de l'UTF-8). */
    private static function titreSur(string $source): string
    {
        $titre = 'Copie d archivage PDF/A de ' . pathinfo($source, PATHINFO_FILENAME);
        if (function_exists('iconv')) {
            $titre = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titre);
        }
        $titre = (string) preg_replace('/[^A-Za-z0-9 ._-]/', '_', $titre);

        return mb_substr($titre, 0, 120);
    }

    /**
     * Exécute Ghostscript et rend null en cas de succès, sinon la raison nommée. Ghostscript peut sortir
     * avec 0 tout en n'ayant rien produit (PDF chiffré) : les diagnostics sont donc lus, pas seulement le
     * code de sortie.
     */
    private function executer(string $entree, string $sortie, string $definition): ?string
    {
        $commande = [
            (string) $this->ghostscript,
            '-dPDFA=2', '-dBATCH', '-dNOPAUSE', '-dNOOUTERSAVE',
            '-sColorConversionStrategy=RGB', '-sDEVICE=pdfwrite',
            '-dPDFACompatibilityPolicy=1',
            '-sOutputFile=' . $sortie,
            '--permit-file-read=' . str_replace('\\', '/', (string) $this->profilIcc),
            '-f', $definition, $entree,
        ];
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processus = proc_open($commande, $descripteurs, $tuyaux);
        if (!is_resource($processus)) {
            return 'conversion PDF/A refusée : Ghostscript n’a pas pu démarrer';
        }
        $diagnostic = (string) stream_get_contents($tuyaux[1]) . (string) stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        $code = proc_close($processus);

        if (preg_match('/requires a password/i', $diagnostic) === 1) {
            return self::MSG_CHIFFRE;
        }
        if ($code !== 0 || preg_match('/Unrecoverable error|No pages will be processed|Couldn.t initialise file/i', $diagnostic) === 1) {
            return 'conversion PDF/A refusée : ' . self::resume($diagnostic);
        }
        if (!is_file($sortie) || filesize($sortie) === 0) {
            return self::MSG_SORTIE;
        }

        return null;
    }

    /** Premier message d'erreur net de Ghostscript, borné : jamais une trace entière à l'écran. */
    private static function resume(string $diagnostic): string
    {
        foreach (preg_split('/\R/', $diagnostic) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || str_starts_with($ligne, 'GPL Ghostscript') || str_starts_with($ligne, 'Copyright')
                || str_starts_with($ligne, 'This software') || str_starts_with($ligne, 'Processing pages')) {
                continue;
            }
            if (preg_match('/error|Error|invalid|Invalid|cannot|Cannot|undefined/i', $ligne) === 1) {
                return mb_substr($ligne, 0, 200);
            }
        }

        return 'Ghostscript a refusé le fichier';
    }

    /**
     * Cherche un fichier livré par Ghostscript à côté du binaire (Windows : `<gs>/lib`, `<gs>/iccprofiles` ;
     * Linux : `/usr/share/ghostscript/<version>/…`), et accepte une surcharge explicite par `$candidats` ou
     * par variable d'environnement (`GS_ICC_SRGB`, `GS_PDFA_DEF`). Rend le premier chemin existant.
     *
     * @param list<string> $candidats
     */
    public static function trouver(array $candidats, string $nom): ?string
    {
        $env = getenv($nom === 'srgb.icc' ? 'GS_ICC_SRGB' : 'GS_PDFA_DEF');
        if (is_string($env) && $env !== '') {
            array_unshift($candidats, $env);
        }
        $gs = Outils::ghostscript();
        if ($gs !== null) {
            $racine = dirname(dirname($gs));                 // <gs>/bin/<binaire> → <gs>
            $candidats[] = $nom === 'srgb.icc' ? $racine . '/iccprofiles/' . $nom : $racine . '/lib/' . $nom;
        }
        foreach (['/usr/share/ghostscript/*/' . ($nom === 'srgb.icc' ? 'iccprofiles' : 'lib') . '/' . $nom,
            '/usr/share/color/icc/ghostscript/' . $nom, '/usr/share/ghostscript/iccprofiles/' . $nom] as $motif) {
            foreach (glob($motif) ?: [] as $fichier) {
                $candidats[] = $fichier;
            }
        }
        foreach ($candidats as $candidat) {
            if ($candidat !== '' && is_file($candidat)) {
                return $candidat;
            }
        }

        return null;
    }
}
