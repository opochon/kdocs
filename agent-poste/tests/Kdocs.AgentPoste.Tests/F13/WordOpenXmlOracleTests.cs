using System.Diagnostics;
using System.Runtime.InteropServices;
using DocumentFormat.OpenXml.Packaging;
using DocumentFormat.OpenXml.Wordprocessing;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.F13;

/// <summary>Les tests qui pilotent Office réel ne s'exécutent jamais en parallèle d'autres collections.</summary>
[CollectionDefinition("Office", DisableParallelization = true)]
public sealed class CollectionOffice
{
}

/// <summary>
/// ORACLE du point 4 du cahier des charges : la copie de secours Word est prise par
/// <c>Document.Content.WordOpenXML</c> (lecture seule) puis convertie en .docx par le SDK Open XML.
/// On prouve sur un VRAI document Word, avec en-tête, pied de page, commentaire et image, que RIEN ne
/// manque dans la copie, et que le document de l'utilisateur n'est pas enregistré au passage.
///
/// Le document est ouvert par l'association Windows (comme le fait l'agent), donc dans la session Word
/// exposée par la table des objets : aucune instance parallèle invisible.
/// </summary>
[Collection("Office")]
[Trait("Categorie", "Office")]
public sealed class WordOpenXmlOracleTests
{
    private const string PngUnPixel =
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

    [Fact]
    public void La_copie_de_secours_Word_conserve_en_tete_pied_commentaire_et_image()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.CheminDe("agent"));
        racine.Preparer();
        var cheminTravail = racine.CheminTravail(5, "oracle.docx");
        Directory.CreateDirectory(Path.GetDirectoryName(cheminTravail)!);
        var png = dossier.CheminDe("image.png");
        File.WriteAllBytes(png, Convert.FromBase64String(PngUnPixel));

        WordReel.NettoyerTemporaires();
        CreerDocumentWord(cheminTravail, png);
        Assert.True(File.Exists(cheminTravail));

        try
        {
            // Ouverture par l'association Windows : le document vit dans la session Word de l'utilisateur.
            Process.Start(new ProcessStartInfo(cheminTravail) { UseShellExecute = true });
            Assert.True(
                Attendre(() => WordReel.EstOuvert(cheminTravail), TimeSpan.FromSeconds(30)),
                "Word doit avoir ouvert le document de l'oracle");
            WordReel.Modifier(cheminTravail, document => ((dynamic)document).Content.InsertAfter(" MODIF NON ENREGISTREE"));
            Assert.False(WordReel.EstEnregistre(cheminTravail), "le document doit être modifié sans enregistrement");

            var automate = new OfficeComAutomate(new JournalMemoire());
            var ouvert = AttendreDocument(automate, cheminTravail);
            Assert.NotNull(ouvert);
            Assert.Equal(TypeOffice.Word, ouvert!.Type);
            Assert.False(ouvert.Enregistre);

            var destination = dossier.CheminDe("secours", "copie.docx");
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            automate.EcrireCopieSecours(ouvert, destination);

            Assert.True(File.Exists(destination));
            using var docx = WordprocessingDocument.Open(destination, false);
            var principal = docx.MainDocumentPart!;
            Assert.NotEmpty(principal.HeaderParts);
            Assert.NotEmpty(principal.FooterParts);
            Assert.NotNull(principal.WordprocessingCommentsPart);
            Assert.NotEmpty(principal.ImageParts);

            Assert.Contains("CORPS ORACLE", principal.Document!.InnerText, StringComparison.Ordinal);
            Assert.Contains("MODIF NON ENREGISTREE", principal.Document.InnerText, StringComparison.Ordinal);
            Assert.Contains(
                principal.HeaderParts.SelectMany(p => p.Header!.Descendants<Text>()).Select(t => t.Text),
                texte => texte.Contains("EN-TETE ORACLE", StringComparison.Ordinal));
            Assert.Contains(
                principal.FooterParts.SelectMany(p => p.Footer!.Descendants<Text>()).Select(t => t.Text),
                texte => texte.Contains("PIED ORACLE", StringComparison.Ordinal));
            Assert.Contains(
                principal.WordprocessingCommentsPart!.Comments!.Descendants<Text>().Select(t => t.Text),
                texte => texte.Contains("COMMENTAIRE ORACLE", StringComparison.Ordinal));

            Assert.False(WordReel.EstEnregistre(cheminTravail), "lire WordOpenXML ne doit jamais enregistrer le document");
        }
        finally
        {
            if (WordReel.EstOuvert(cheminTravail))
            {
                WordReel.Fermer(cheminTravail);
            }
        }
    }

    /// <summary>Crée le document de l'oracle (en-tête, pied, commentaire, image) dans une instance dédiée, puis la ferme.</summary>
    private static void CreerDocumentWord(string chemin, string image)
    {
        using var session = new SessionSta(pret =>
        {
            dynamic word = SessionSta.CreerApplication("Word.Application");
            word.Visible = false;
            word.DisplayAlerts = 0;
            try
            {
                dynamic document = word.Documents.Add();
                document.Content.Text = "CORPS ORACLE";
                document.Sections.Item(1).Headers.Item(1).Range.Text = "EN-TETE ORACLE";
                document.Sections.Item(1).Footers.Item(1).Range.Text = "PIED ORACLE";
                document.Comments.Add(document.Range(0, 0), "COMMENTAIRE ORACLE");
                document.InlineShapes.AddPicture(image, false, true);
                try
                {
                    document.SaveAs2(chemin, 16);   // wdFormatDocumentDefault
                }
                catch (COMException)
                {
                    // Word 16 lève parfois une exception de convertisseur tout en écrivant le fichier.
                    if (!File.Exists(chemin))
                    {
                        throw;
                    }
                }
                document.Close(0);
            }
            finally
            {
                word.Quit();
            }
            pret();
        });
        session.Verifier();
    }

    private static DocumentOffice? AttendreDocument(OfficeComAutomate automate, string chemin)
    {
        var fin = DateTime.UtcNow + TimeSpan.FromSeconds(20);
        do
        {
            var document = automate.Lister()
                .FirstOrDefault(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase));
            if (document is not null)
            {
                return document;
            }

            Thread.Sleep(500);
        }
        while (DateTime.UtcNow < fin);

        return null;
    }

    private static bool Attendre(Func<bool> condition, TimeSpan delai)
    {
        var fin = DateTime.UtcNow + delai;
        while (DateTime.UtcNow < fin)
        {
            if (condition())
            {
                return true;
            }

            Thread.Sleep(500);
        }

        return condition();
    }
}
