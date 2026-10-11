using DocumentFormat.OpenXml.Packaging;
using DocumentFormat.OpenXml.Wordprocessing;
using Kdocs.AgentPoste.Com;
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
/// Ce test exige Word installé (Microsoft 365, Office 16) : c'est un oracle de poste, pas un test unitaire.
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

        using var word = new SessionWord(cheminTravail, png);
        var automate = new OfficeComAutomate(new JournalMemoire());

        var documents = automate.Lister();
        var ouvert = documents.SingleOrDefault(d => string.Equals(d.Chemin, cheminTravail, StringComparison.OrdinalIgnoreCase));
        Assert.NotNull(ouvert);
        Assert.Equal(TypeOffice.Word, ouvert!.Type);
        Assert.False(ouvert.Enregistre, "le document doit être modifié sans enregistrement");

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

        // Le document de l'utilisateur n'a PAS été enregistré par la copie de secours.
        var apres = automate.Lister().Single(d => string.Equals(d.Chemin, cheminTravail, StringComparison.OrdinalIgnoreCase));
        Assert.False(apres.Enregistre, "lire WordOpenXML ne doit jamais enregistrer le document");
    }

    /// <summary>Word réel : crée le document piégé, le rouvre, le modifie sans enregistrer, et le tient ouvert.</summary>
    private sealed class SessionWord : IDisposable
    {
        private readonly ManualResetEventSlim _pret = new(false);
        private readonly ManualResetEventSlim _fermer = new(false);
        private readonly Thread _fil;
        private Exception? _erreur;

        public SessionWord(string cheminTravail, string image)
        {
            _fil = new Thread(() => Derouler(cheminTravail, image));
            _fil.SetApartmentState(ApartmentState.STA);
            _fil.IsBackground = true;
            _fil.Start();
            _pret.Wait(TimeSpan.FromMinutes(2));
            if (_erreur is not null)
            {
                throw new InvalidOperationException("session Word impossible", _erreur);
            }
        }

        public void Dispose()
        {
            _fermer.Set();
            _fil.Join(TimeSpan.FromMinutes(1));
        }

        private void Derouler(string cheminTravail, string image)
        {
            try
            {
                var type = Type.GetTypeFromProgID("Word.Application")
                    ?? throw new InvalidOperationException("Word n’est pas installé sur ce poste");
                dynamic word = Activator.CreateInstance(type)!;
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
                        document.SaveAs2(cheminTravail, 16);   // wdFormatDocumentDefault
                    }
                    catch (System.Runtime.InteropServices.COMException)
                    {
                        // Word 16 lève parfois une exception de convertisseur tout en écrivant le fichier.
                        if (!File.Exists(cheminTravail))
                        {
                            throw;
                        }
                    }
                    document.Close(0);

                    dynamic rouvert = word.Documents.Open(cheminTravail);
                    rouvert.Content.InsertAfter(" MODIF NON ENREGISTREE");
                    _pret.Set();
                    _fermer.Wait(TimeSpan.FromMinutes(5));
                    rouvert.Close(0);
                }
                finally
                {
                    word.Quit();
                }
            }
            catch (Exception exception)
            {
                _erreur = exception;
                _pret.Set();
            }
        }
    }
}
