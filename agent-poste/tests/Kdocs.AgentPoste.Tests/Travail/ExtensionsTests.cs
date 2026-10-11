using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Travail;

/// <summary>
/// Oracle de la liste BLANCHE d'extensions éditables.
///
/// Un document dont le nom n'appartient pas à la liste blanche n'est JAMAIS passé au shell. Le nom brut
/// du serveur est d'abord normalisé par <see cref="Kdocs.AgentPoste.RacineAgent.NomSain"/> : l'extension
/// retenue est celle du nom normalisé (basename, caractères interdits remplacés, espaces et points finaux
/// retirés), jamais celle fournie telle quelle. Couvre les variantes : majuscules, double extension,
/// espaces ou points finaux, flux de données alternatif (<c>nom.docx:x.exe</c>), nom sans extension,
/// et les formats à macros ou modèles Office explicitement exclus.
/// </summary>
public sealed class ExtensionsTests
{
    public static TheoryData<string> NomsDangereux() => new()
    {
        // Exécutables et scripts.
        "facture.exe", "FACTURE.EXE", "script.com", "lancer.bat", "Lancer.CMD",
        "profil.ps1", "app.js", "macro.vbs", "raccourci.lnk", "site.url", "page.hta",
        "programme.msi", "ecran.scr", "commande.pif", "installation.cpl", "objet.jar",
        // Double extension : seule la DERNIÈRE extension compte.
        "facture.pdf.exe", "image.png.bat", "note.docx.com",
        // Espaces ou points finaux (Windows les retire).
        "piece.exe ", "piece.exe.", " piece.exe",
        // Flux de données alternatif : « : » interdit, remplacé, la dernière extension est .exe.
        "nom.docx:x.exe", "nom.docx:script.bat",
        // Nom sans extension.
        "sans-extension", "document", ".exe",
        // Macros Office (.xlsb peut aussi porter des macros) et modèles : hors liste blanche.
        "courrier.docm", "courrier.DOCM", "courrier.dot", "courrier.dotx",
        "classeur.xlsm", "classeur.xlsb", "diapo.pptm", "modele.potm",
    };

    [Theory]
    [MemberData(nameof(NomsDangereux))]
    public void Un_nom_hors_liste_blanche_n_est_jamais_autorise(string nom)
    {
        Assert.False(ExtensionsEditables.Autorisee(nom), $"nom accepté à tort : {nom}");
        Assert.NotEqual(string.Empty, ExtensionsEditables.CauseRefus(nom));
    }

    public static TheoryData<string> NomsEditables() => new()
    {
        "rapport.doc", "rapport.docx", "RAPPORT.DOCX", "classeur.xls", "classeur.xlsx",
        "diapo.ppt", "diapo.pptx", "texte.odt", "tableur.ods", "presentation.odp",
        "note.rtf", "note.txt", "export.csv", "facture.pdf.docx",
        "rapport.docx ", "rapport.docx.",
    };

    [Theory]
    [MemberData(nameof(NomsEditables))]
    public void Un_nom_de_la_liste_blanche_est_autorise(string nom)
    {
        Assert.True(ExtensionsEditables.Autorisee(nom), $"nom refusé à tort : {nom}");
    }

    [Fact]
    public void La_liste_blanche_est_exactement_celle_du_cahier_des_charges()
    {
        Assert.Equal(
            [".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx", ".odt", ".ods", ".odp", ".rtf", ".txt", ".csv"],
            ExtensionsEditables.Editables);
    }
}
