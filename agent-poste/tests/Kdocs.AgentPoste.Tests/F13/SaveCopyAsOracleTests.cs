using DocumentFormat.OpenXml.Packaging;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.F13;

/// <summary>
/// ORACLE du point 4 pour Excel et PowerPoint : la copie de secours est prise par
/// <c>Workbook.SaveCopyAs</c> / <c>Presentation.SaveCopyAs</c> (« sans modifier l'original »), sur un VRAI
/// classeur et une VRAIE présentation modifiés sans enregistrement. Le document de l'utilisateur reste
/// non enregistré après la copie.
/// </summary>
[Collection("Office")]
[Trait("Categorie", "Office")]
public sealed class SaveCopyAsOracleTests
{
    [Fact]
    public void La_copie_Excel_conserve_le_contenu_non_enregistre()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.CheminDe("agent"));
        racine.Preparer();
        var chemin = racine.CheminTravail(11, "classeur.xlsx");
        Directory.CreateDirectory(Path.GetDirectoryName(chemin)!);

        dynamic? excel = null;
        dynamic? classeur = null;
        using (var session = new SessionSta(
            pret =>
            {
                excel = SessionSta.CreerApplication("Excel.Application");
                excel.Visible = false;
                excel.DisplayAlerts = false;
                classeur = excel.Workbooks.Add();
                classeur.Worksheets[1].Cells[1, 1].Value = "ORACLE EXCEL";
                classeur.SaveAs(chemin, 51);   // xlOpenXMLWorkbook
                classeur.Worksheets[1].Cells[2, 1].Value = "MODIF EXCEL NON ENREGISTREE";
                pret();
            },
            () =>
            {
                try
                {
                    classeur?.Close(false);
                }
                finally
                {
                    excel?.Quit();
                }
            }))
        {
            session.Verifier();

            var automate = new OfficeComAutomate(new JournalMemoire());
            var ouvert = automate.Lister().Single(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase));
            Assert.Equal(TypeOffice.Excel, ouvert.Type);
            Assert.False(ouvert.Enregistre);

            var destination = dossier.CheminDe("secours", "classeur.xlsx");
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            automate.EcrireCopieSecours(ouvert, destination);

            Assert.True(File.Exists(destination));
            using var copie = SpreadsheetDocument.Open(destination, false);
            var classeurPart = copie.WorkbookPart!;
            var textes = new List<string>();
            if (classeurPart.SharedStringTablePart?.SharedStringTable is { } table)
            {
                textes.AddRange(table.Descendants<DocumentFormat.OpenXml.Spreadsheet.SharedStringItem>().Select(e => e.InnerText));
            }
            textes.AddRange(classeurPart.WorksheetParts
                .SelectMany(p => p.Worksheet!.Descendants<DocumentFormat.OpenXml.Spreadsheet.InlineString>())
                .Select(e => e.InnerText));
            Assert.Contains(textes, t => t.Contains("MODIF EXCEL NON ENREGISTREE", StringComparison.Ordinal));
            Assert.False(
                automate.Lister().Single(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase))!.Enregistre,
                "SaveCopyAs ne doit pas enregistrer le classeur de l’utilisateur");
        }
    }

    [Fact]
    public void La_copie_PowerPoint_conserve_le_contenu_non_enregistre()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.CheminDe("agent"));
        racine.Preparer();
        var chemin = racine.CheminTravail(12, "presentation.pptx");
        Directory.CreateDirectory(Path.GetDirectoryName(chemin)!);

        dynamic? powerpoint = null;
        dynamic? presentation = null;
        using (var session = new SessionSta(
            pret =>
            {
                powerpoint = SessionSta.CreerApplication("PowerPoint.Application");
                presentation = powerpoint.Presentations.Add();
                var diapositive = presentation.Slides.Add(1, 12);   // ppLayoutBlank
                diapositive.Shapes.AddTextbox(1, 20, 20, 400, 60).TextFrame.TextRange.Text = "MODIF PPT NON ENREGISTREE";
                presentation.SaveAs(chemin, 24);   // ppSaveAsOpenXMLPresentation
                diapositive.Shapes.AddTextbox(1, 20, 100, 400, 60).TextFrame.TextRange.Text = "SECONDE MODIF PPT";
                pret();
            },
            () =>
            {
                try
                {
                    presentation?.Close();
                }
                finally
                {
                    powerpoint?.Quit();
                }
            }))
        {
            session.Verifier();

            var automate = new OfficeComAutomate(new JournalMemoire());
            var ouvert = automate.Lister().Single(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase));
            Assert.Equal(TypeOffice.PowerPoint, ouvert.Type);
            Assert.False(ouvert.Enregistre);

            var destination = dossier.CheminDe("secours", "presentation.pptx");
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            automate.EcrireCopieSecours(ouvert, destination);

            Assert.True(File.Exists(destination));
            using var copie = PresentationDocument.Open(destination, false);
            var texte = copie.PresentationPart!.SlideParts
                .SelectMany(p => p.Slide!.Descendants<DocumentFormat.OpenXml.Drawing.Text>())
                .Select(t => t.Text);
            Assert.Contains(texte, t => t.Contains("SECONDE MODIF PPT", StringComparison.Ordinal));
            Assert.False(
                automate.Lister().Single(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase))!.Enregistre,
                "SaveCopyAs ne doit pas enregistrer la présentation de l’utilisateur");
        }
    }
}
