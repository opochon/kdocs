<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Dossier d'ingestion. Un fichier n'est pris que lorsqu'il est STABLE (même taille et même date à deux relevés
 * successifs : un scanner qui écrit encore n'est jamais lu à moitié), puis il est DÉPLACÉ dans la zone de
 * travail (renommage atomique) : il ne peut pas être pris deux fois.
 * Les dossiers et fichiers cachés (point initial) sont ignorés.
 */
final class SourceDossier
{
    private const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'docx', 'doc', 'xlsx', 'xls', 'odt', 'ods', 'rtf', 'txt', 'eml', 'msg'];

    public function __construct(
        private readonly string $dossier,
        private readonly string $zoneTravail,
        private readonly string $fichierEtat,   // mémoire des tailles vues au relevé précédent
    ) {
    }

    /** @return list<Entree> */
    public function relever(): array
    {
        if (!is_dir($this->dossier)) {
            return [];
        }
        $vus = is_file($this->fichierEtat) ? (json_decode((string) file_get_contents($this->fichierEtat), true) ?: []) : [];
        $maintenant = [];
        $entrees = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->dossier, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f) => !str_starts_with($f->getFilename(), '.'),
        ));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if (!$f->isFile() || !in_array(strtolower($f->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }
            $cle = $f->getPathname();
            $signature = $f->getSize() . ':' . $f->getMTime();
            $maintenant[$cle] = $signature;
            if (($vus[$cle] ?? null) !== $signature) {
                continue;   // vu pour la première fois ou encore en écriture : on attend le relevé suivant
            }
            $cible = $this->zoneTravail . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . self::nomSur($f->getFilename());
            if (!is_dir($this->zoneTravail)) {
                mkdir($this->zoneTravail, 0775, true);
            }
            if (@rename($cle, $cible)) {
                unset($maintenant[$cle]);
                $entrees[] = new Entree($cible, 'dossier', $f->getFilename());
            }
        }
        file_put_contents($this->fichierEtat, json_encode($maintenant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $entrees;
    }

    public static function nomSur(string $nom): string
    {
        $nom = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $nom) ?? 'fichier';

        return mb_substr(trim($nom, '._') ?: 'fichier', 0, 120);
    }
}
