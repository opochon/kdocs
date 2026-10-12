<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Déchiffrement des PDF chiffrés du fonds (équivalent fonctionnel de la commande Paperless
 * `decrypt_documents`) : GEDv2 refuse à l'ingestion un PDF chiffré (la conversion PDF/A le nomme), mais un
 * fonds repris d'ailleurs peut en contenir. L'outil de déchiffrement est EXTERNE (qpdf par défaut,
 * surchargeable par `PDF_DECHIFFREUR`) : aucun déchiffrement n'est réimplémenté en PHP, et un outil absent
 * rend une raison NOMMÉE, jamais un échec muet.
 *
 * Le fichier est déchiffré sur place (`--replace-input`) : un PDF en clair le remplace, le `.meta.json` et
 * la ligne d'index ne bougent pas. Aucun octet n'est publié si l'outil échoue.
 */
final class DechiffreurPdf
{
    public const RAISON_FICHIER = 'déchiffrement impossible : fichier introuvable';
    public const RAISON_OUTIL = 'déchiffrement impossible : aucun outil de déchiffrement (PDF_DECHIFFREUR)';
    public const RAISON_NON_PDF = 'déchiffrement refusé : ce n’est pas un PDF';
    public const RAISON_PAS_CHIFFRE = 'déchiffrement inutile : le PDF n’est pas chiffré';
    public const RAISON_ECHEC = 'déchiffrement refusé : l’outil n’a pas produit un PDF en clair';

    /** Préfixe d'entête PDF : le fichier doit commencer par `%PDF`. */
    private const ENTETE = '%PDF';

    /**
     * @param list<string> $commande argv de base (l'outil et ses options), le chemin du fichier est ajouté
     *                              en dernier. Vide = aucun outil disponible.
     */
    public function __construct(private readonly array $commande = [])
    {
    }

    /** @return self outil détecté par la configuration (`PDF_DECHIFFREUR`), ou vide si absent. */
    public static function depuis(): self
    {
        $binaire = trim((string) getenv('PDF_DECHIFFREUR'));
        if ($binaire === '') {
            return new self();
        }

        return new self([$binaire, '--decrypt', '--password=', '--replace-input']);
    }

    public function disponible(): bool
    {
        return $this->commande !== [];
    }

    /** Le fichier est-il un PDF ? (l'entête est lue, jamais une extension supposée). */
    public static function estPdf(string $fichier): bool
    {
        if (!is_file($fichier)) {
            return false;
        }

        return str_starts_with((string) file_get_contents($fichier, false, null, 0, 4), self::ENTETE);
    }

    /** Le PDF porte-t-il un dictionnaire `/Encrypt` ? (détection de surface, comme `ControlePdfA`). */
    public static function estChiffre(string $fichier): bool
    {
        if (!self::estPdf($fichier)) {
            return false;
        }
        $brut = (string) file_get_contents($fichier);

        return preg_match('#/Encrypt\b#', $brut) === 1;
    }

    /**
     * Déchiffre le PDF sur place. Rend null en cas de succès, sinon une raison nommée.
     */
    public function dechiffrer(string $fichier): ?string
    {
        if (!$this->disponible()) {
            return self::RAISON_OUTIL;
        }
        if (!is_file($fichier)) {
            return self::RAISON_FICHIER;
        }
        if (!self::estPdf($fichier)) {
            return self::RAISON_NON_PDF;
        }
        if (!self::estChiffre($fichier)) {
            return self::RAISON_PAS_CHIFFRE;
        }

        $commande = [...$this->commande, $fichier];
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tuyaux = [];
        $processus = @proc_open($commande, $descripteurs, $tuyaux);
        if (!is_resource($processus)) {
            return self::RAISON_OUTIL;
        }
        stream_get_contents($tuyaux[1]);
        stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        $code = proc_close($processus);

        if ($code !== 0 || !self::estPdf($fichier) || self::estChiffre($fichier)) {
            return self::RAISON_ECHEC;
        }

        return null;
    }
}
