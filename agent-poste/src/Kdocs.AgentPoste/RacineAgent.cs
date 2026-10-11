using System.Globalization;
using System.Text;

namespace Kdocs.AgentPoste;

/// <summary>
/// Chemins de l'agent sous <c>%LOCALAPPDATA%\K-Docs</c> :
///   Travail\&lt;id&gt;\&lt;nom&gt;     le document ouvert comme un double-clic ;
///   Secours\&lt;id&gt;\…            les copies de secours, jamais une version ;
///   File\…                     la file d'envoi persistante et ses instantanés ;
///   journal\…                  le journal.
/// Tous les noms venant du serveur passent par <see cref="NomSain"/> : aucune traversée de dossier.
/// </summary>
public sealed class RacineAgent
{
    public const string ExtensionFiche = ".kdocs.json";

    public RacineAgent(string? racine = null) => Racine = Path.GetFullPath(racine ?? ParDefaut());

    public string Racine { get; }

    public string DossierTravail => Path.Combine(Racine, "Travail");

    public string DossierSecours => Path.Combine(Racine, "Secours");

    /// <summary>File d'envoi persistante (une entrée JSON par envoi) et instantanés des contenus.</summary>
    public string DossierFile => Path.Combine(Racine, "File");

    public string DossierJournal => Path.Combine(Racine, "journal");

    /// <summary>Refus d'ouverture conservés (formats hors liste blanche), expliqués dans « Mes documents en cours ».</summary>
    public string DossierRefus => Path.Combine(Racine, "refus");

    public string FichierConfiguration => Path.Combine(Racine, "config.json");

    public string FichierJeton => Path.Combine(Racine, "jeton.dpapi");

    public static string ParDefaut() => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData, Environment.SpecialFolderOption.DoNotVerify),
        "K-Docs");

    public string DossierTravailDe(int document) =>
        Path.Combine(DossierTravail, document.ToString(CultureInfo.InvariantCulture));

    public string DossierSecoursDe(int document) =>
        Path.Combine(DossierSecours, document.ToString(CultureInfo.InvariantCulture));

    public string CheminTravail(int document, string nom) =>
        Path.Combine(DossierTravailDe(document), NomSain(nom));

    public string CheminFiche(int document, string nom) => CheminTravail(document, nom) + ExtensionFiche;

    /// <summary>
    /// Identifiant du document si le chemin est bien un document de NOTRE dossier de travail
    /// (<c>Travail\&lt;id&gt;\…</c>), sinon null. Sert aux copies de secours : on ne sauvegarde jamais un
    /// document hors de ce dossier, ni un chemin piégé.
    /// </summary>
    public int? DocumentDansTravail(string? chemin)
    {
        if (string.IsNullOrWhiteSpace(chemin))
        {
            return null;
        }

        try
        {
            var complet = Path.GetFullPath(chemin);
            var racineTravail = Path.GetFullPath(DossierTravail) + Path.DirectorySeparatorChar;
            if (!complet.StartsWith(racineTravail, StringComparison.OrdinalIgnoreCase))
            {
                return null;
            }

            var relatif = complet[racineTravail.Length..];
            var separateur = relatif.IndexOfAny([Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar]);
            var premier = separateur < 0 ? relatif : relatif[..separateur];

            return int.TryParse(premier, NumberStyles.None, CultureInfo.InvariantCulture, out var document) && document > 0
                ? document
                : null;
        }
        catch (ArgumentException)
        {
            return null;
        }
    }

    /// <summary>Crée l'arborescence de l'agent si besoin (idempotent).</summary>
    public void Preparer()
    {
        Directory.CreateDirectory(DossierTravail);
        Directory.CreateDirectory(DossierSecours);
        Directory.CreateDirectory(DossierFile);
        Directory.CreateDirectory(DossierJournal);
        Directory.CreateDirectory(DossierRefus);
    }

    /// <summary>
    /// Nom de fichier sûr : réduit au basename, retire les caractères interdits Windows, interdit les
    /// noms réservés et borne la longueur. Un nom vide ou piégé rend « document ».
    /// </summary>
    public static string NomSain(string? nom)
    {
        var baseNom = Path.GetFileName((nom ?? string.Empty).Replace('\\', '/')).Trim();
        if (baseNom is "" or "." or "..")
        {
            return "document";
        }

        var interdit = new HashSet<char>(Path.GetInvalidFileNameChars());
        var propre = new StringBuilder(baseNom.Length);
        foreach (var c in baseNom)
        {
            propre.Append(interdit.Contains(c) || char.IsControl(c) ? '_' : c);
        }

        var resultat = propre.ToString().TrimEnd('.', ' ');
        if (resultat is "" or "." or "..")
        {
            return "document";
        }

        var sansExtension = Path.GetFileNameWithoutExtension(resultat);
        if (EstNomReserve(sansExtension))
        {
            resultat = "_" + resultat;
        }

        if (resultat.Length > 150)
        {
            var extension = Path.GetExtension(resultat);
            if (extension.Length > 20)
            {
                extension = string.Empty;
            }
            resultat = resultat[..(150 - extension.Length)] + extension;
        }

        return resultat;
    }

    private static bool EstNomReserve(string nom) =>
        nom.ToUpperInvariant() is "CON" or "PRN" or "AUX" or "NUL"
            or "COM1" or "COM2" or "COM3" or "COM4" or "COM5" or "COM6" or "COM7" or "COM8" or "COM9"
            or "LPT1" or "LPT2" or "LPT3" or "LPT4" or "LPT5" or "LPT6" or "LPT7" or "LPT8" or "LPT9";
}
