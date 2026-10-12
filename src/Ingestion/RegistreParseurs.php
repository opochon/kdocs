<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Registre des parseurs : quel type MIME, quelle extension par défaut, quelle famille de parseur.
 *
 * Équivalent GEDv2 du registre Paperless (`documents.parsers`) : chez GEDv2 le parseur n'est pas une
 * classe Django découverte par signaux mais l'aiguillage de la chaîne d'ingestion — rasterisation (OCR
 * du moteur externe `cmd4_ingest`), lecture texte directe, ou bureautique (LibreOffice → PDF puis OCR,
 * l'équivalent du parseur Tika/Gotenberg). Le registre est LA table de référence : il ne duplique ni
 * `Socle\Fichiers` (MIME par extension) ni `Apercu\Piece` (catégorie d'une pièce), il les complète par
 * la lecture inverse (type MIME → extension → famille).
 *
 * Aucun parseur n'est déclaré deux fois : une famille par type MIME, la plus spécifique d'abord.
 */
final class RegistreParseurs
{
    /** Famille rasterisée : PDF et images bitmap passent par l'OCR du moteur d'ingestion. */
    public const RASTERISE = 'rasterise';
    /** Famille texte : le fichier est déjà du texte lisible, aucune OCR. */
    public const TEXTE = 'texte';
    /** Famille bureautique : conversion LibreOffice → PDF avant OCR (équivalent Tika/Gotenberg). */
    public const BUREAUTIQUE = 'bureautique';

    /**
     * Table type MIME → [extension par défaut, famille]. Une seule entrée par type MIME.
     *
     * @var array<string, array{0:string, 1:string}>
     */
    private const PARSEURS = [
        'application/pdf' => ['.pdf', self::RASTERISE],
        'image/png' => ['.png', self::RASTERISE],
        'image/jpeg' => ['.jpg', self::RASTERISE],
        'image/tiff' => ['.tif', self::RASTERISE],
        'image/webp' => ['.webp', self::RASTERISE],
        'text/plain' => ['.txt', self::TEXTE],
        'text/csv' => ['.csv', self::TEXTE],
        'application/vnd.oasis.opendocument.text' => ['.odt', self::BUREAUTIQUE],
        'text/rtf' => ['.rtf', self::BUREAUTIQUE],
        'application/msword' => ['.doc', self::BUREAUTIQUE],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['.docx', self::BUREAUTIQUE],
    ];

    /**
     * Types MIME connus SANS parseur propre (archives) : leur extension reste déterminable, comme le
     * repli `mimetypes.guess_extension` de Paperless, mais aucun parseur ne les réclame.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS_CONNUES = ['application/zip' => '.zip'];

    /** Famille de parseur pour un type MIME, ou null si aucun parseur ne le réclame. */
    public static function parseurPourMime(string $mime): ?string
    {
        return self::PARSEURS[$mime][1] ?? null;
    }

    /** Un type MIME est supporté si une famille de parseur le réclame. */
    public static function mimeSupporte(string $mime): bool
    {
        return self::parseurPourMime($mime) !== null;
    }

    /** Extension par défaut d'un type MIME ; chaîne vide si indéterminable (jamais une extension inventée). */
    public static function extensionDefaut(string $mime): string
    {
        if (isset(self::PARSEURS[$mime])) {
            return self::PARSEURS[$mime][0];
        }

        return self::EXTENSIONS_CONNUES[$mime] ?? '';
    }

    /** @return list<string> */
    public static function typesMimeSupportes(): array
    {
        return array_keys(self::PARSEURS);
    }

    /**
     * Extensions supportées : celles déclarées par les parseurs, plus les variantes graphiques usuelles
     * (`.jpeg`, `.tiff`) que la bibliothèque standard de Paperless ajoute par `guess_all_extensions`.
     *
     * @return list<string>
     */
    public static function extensionsSupportees(): array
    {
        $extensions = [];
        foreach (self::PARSEURS as [$extension]) {
            $extensions[] = $extension;
        }
        $extensions[] = '.jpeg';
        $extensions[] = '.tiff';

        return array_values(array_unique($extensions));
    }

    /** Une extension est supportée si le registre la connaît ; la casse et le point initial sont ignorés. */
    public static function extensionSupportee(string $extension): bool
    {
        if ($extension === '') {
            return false;
        }
        $normalisee = strtolower($extension);
        if (!str_starts_with($normalisee, '.')) {
            $normalisee = '.' . $normalisee;
        }

        return in_array($normalisee, self::extensionsSupportees(), true);
    }

    /**
     * Construit les arguments d'une commande de conversion (équivalent `run_convert`), dans l'ordre
     * documenté de Paperless : densité, échelle, alpha, drapeaux, type, profondeur, orientations, puis
     * les chemins d'entrée et de sortie. Les options absentes (null/false) ne produisent aucun argument.
     *
     * @param array{densite?:int|null, echelle?:string|null, alpha?:string|null, detacher?:bool,
     *              detourer?:bool, type?:string|null, profondeur?:int|null, autoOrienter?:bool,
     *              cropbox?:bool, extra?:list<string>} $options
     * @return list<string>
     */
    public static function argumentsConvert(string $binaire, string $entree, string $sortie, array $options = []): array
    {
        $arguments = [$binaire];
        if (isset($options['densite'])) {
            $arguments[] = '-density';
            $arguments[] = (string) $options['densite'];
        }
        if (isset($options['echelle'])) {
            $arguments[] = '-scale';
            $arguments[] = $options['echelle'];
        }
        if (isset($options['alpha'])) {
            $arguments[] = '-alpha';
            $arguments[] = $options['alpha'];
        }
        if (($options['detacher'] ?? false) === true) {
            $arguments[] = '-strip';
        }
        if (($options['detourer'] ?? false) === true) {
            $arguments[] = '-trim';
        }
        if (isset($options['type'])) {
            $arguments[] = '-type';
            $arguments[] = $options['type'];
        }
        if (isset($options['profondeur'])) {
            $arguments[] = '-depth';
            $arguments[] = (string) $options['profondeur'];
        }
        if (($options['autoOrienter'] ?? false) === true) {
            $arguments[] = '-auto-orient';
        }
        if (($options['cropbox'] ?? false) === true) {
            $arguments[] = '-define';
            $arguments[] = 'pdf:use-cropbox=true';
        }
        foreach ($options['extra'] ?? [] as $supplement) {
            $arguments[] = $supplement;
        }
        $arguments[] = $entree;
        $arguments[] = $sortie;

        return $arguments;
    }
}
