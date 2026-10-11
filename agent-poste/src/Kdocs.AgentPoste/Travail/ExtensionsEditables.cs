namespace Kdocs.AgentPoste.Travail;

/// <summary>
/// Liste BLANCHE des formats de documents que l'agent accepte d'ouvrir sur le poste. Tout le reste est
/// refusé : un exécutable, un script ou un format à macros déposé dans la GED ne doit JAMAIS être passé
/// au shell (association Windows), sinon il s'exécuterait sur le poste de celui qui l'ouvre.
///
/// La liste est celle du cahier des charges (mêmes valeurs côté serveur, `Ged\Edition\Formats`) :
/// documents Word, classeurs Excel, présentations PowerPoint et formats ouverts comparables.
///
/// Exclus, avec la raison :
///   - <c>docm</c>, <c>xlsm</c>, <c>pptm</c> (et <c>xlsb</c>) : formats Office Open XML à macros VBA
///     (`vbaProject.bin`), un vecteur d'exécution — documenté par Microsoft :
///     https://support.microsoft.com/en-us/office/macros-in-office-files-12b036fd-d140-4e74-b45e-16fed1a7e5c6 ;
///   - <c>dot</c>, <c>dotx</c>, <c>potm</c>… : modèles (et modèles à macros), hors périmètre d'édition ;
///   - tout exécutable, script, raccourci ou format inconnu, par défaut (liste blanche, jamais liste noire).
///
/// L'extension retenue est celle du nom NORMALISÉ par l'agent (<see cref="RacineAgent.NomSain"/>), jamais
/// celle fournie brute : basename réduit, caractères interdits remplacés, espaces et points finaux retirés.
/// </summary>
public static class ExtensionsEditables
{
    /// <summary>Extensions acceptées, en minuscules et avec le point.</summary>
    public static readonly IReadOnlyList<string> Editables =
    [
        ".doc", ".docx",
        ".xls", ".xlsx",
        ".ppt", ".pptx",
        ".odt", ".ods", ".odp",
        ".rtf", ".txt", ".csv",
    ];

    private static readonly HashSet<string> Autorisees = new(Editables, StringComparer.OrdinalIgnoreCase);

    /// <summary>Extension du nom normalisé, en minuscules ; chaîne vide si le nom n'en porte pas.</summary>
    public static string ExtensionNormalisee(string? nom) =>
        Path.GetExtension(RacineAgent.NomSain(nom)).ToLowerInvariant();

    /// <summary>Vrai si le document est d'un format éditable de la liste blanche.</summary>
    public static bool Autorisee(string? nom) => Autorisees.Contains(ExtensionNormalisee(nom));

    /// <summary>Motif lisible du refus, nommant l'extension fautive (ou son absence).</summary>
    public static string CauseRefus(string? nom)
    {
        var extension = ExtensionNormalisee(nom);

        return extension.Length == 0
            ? "format sans extension : ouverture refusée (seuls les formats éditables de la liste blanche sont acceptés)"
            : $"format « {extension} » non éditable : ouverture refusée (seuls les formats éditables de la liste blanche sont acceptés)";
    }
}
