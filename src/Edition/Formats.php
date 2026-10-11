<?php

declare(strict_types=1);

namespace Ged\Edition;

/**
 * Liste BLANCHE des formats éditables, côté serveur — SOURCE UNIQUE.
 *
 * Le bouton « Ouvrir dans Word » ne s'affiche que pour ces formats ; l'API de l'agent refuse l'ouverture
 * et le dépôt de version pour tout autre (403 nommé). La liste est celle du cahier des charges et de
 * l'agent local (`Kdocs.AgentPoste.Travail.ExtensionsEditables`), qui doit rester identique.
 *
 * Exclus, avec la raison :
 *  - `docm`, `xlsm`, `pptm` (et `xlsb`) : formats Office Open XML à macros VBA, vecteur d'exécution —
 *    documenté par Microsoft : les formats « macro-enabled » portent un `vbaProject.bin` ;
 *  - `dot`, `dotx`, `potm`… : modèles, hors périmètre d'édition ;
 *  - exécutables, scripts, raccourcis et formats inconnus : refusés PAR DÉFAUT (liste blanche, jamais
 *    liste noire).
 */
final class Formats
{
    /** @var list<string> Extensions acceptées, sans le point, en minuscules. */
    public const EXTENSIONS_EDITABLES = [
        'doc', 'docx',
        'xls', 'xlsx',
        'ppt', 'pptx',
        'odt', 'ods', 'odp',
        'rtf', 'txt', 'csv',
    ];

    /** @var array<string, list<string>> Application attendue par groupe de formats (libellé du bouton). */
    public const GROUPES = [
        'Word' => ['doc', 'docx', 'odt', 'rtf', 'txt'],
        'Excel' => ['xls', 'xlsx', 'ods', 'csv'],
        'PowerPoint' => ['ppt', 'pptx', 'odp'],
    ];

    /** Extension du nom rangé, en minuscules ; chaîne vide si le nom n'en porte pas. */
    public static function extension(string $nom): string
    {
        return strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    }

    /** Vrai si le document est d'un format éditable de la liste blanche. */
    public static function editable(string $nom): bool
    {
        return in_array(self::extension($nom), self::EXTENSIONS_EDITABLES, true);
    }

    /** Libellé du bouton d'ouverture selon le groupe du format ; repli neutre hors liste blanche. */
    public static function libelle(string $nom): string
    {
        $extension = self::extension($nom);
        foreach (self::GROUPES as $application => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return 'Ouvrir dans ' . $application;
            }
        }

        return 'Ouvrir sur le poste';
    }

    /** Motif lisible du refus, nommant l'extension fautive (ou son absence). */
    public static function motif(string $nom): string
    {
        $extension = self::extension($nom);

        return $extension === ''
            ? 'format sans extension : ouverture refusée (seuls les formats éditables de la liste blanche sont acceptés)'
            : sprintf(
                'format « %s » non éditable : ouverture refusée (seuls les formats éditables de la liste blanche sont acceptés)',
                $extension,
            );
    }
}
