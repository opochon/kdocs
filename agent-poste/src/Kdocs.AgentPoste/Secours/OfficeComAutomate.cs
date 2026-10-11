using System.Runtime.InteropServices;
using Kdocs.AgentPoste.Com;
using Microsoft.CSharp.RuntimeBinder;

namespace Kdocs.AgentPoste.Secours;

/// <summary>
/// Attachement aux sessions Office de l'utilisateur par la table des objets en cours (ROT) et liaison
/// tardive (aucune référence d'assembly Office à embarquer). Chaque appel s'exécute sur un fil STA et
/// sous le filtre de messages OLE : un Office occupé (boîte de dialogue, saisie en cours) est réessayé,
/// jamais interrompu.
///
/// Copies de secours, sans jamais modifier le document de l'utilisateur :
///   Excel      : Workbook.SaveCopyAs ;
///   PowerPoint : Presentation.SaveCopyAs ;
///   Word       : Document.Content.WordOpenXML (lecture seule) → .docx par le SDK Open XML.
/// </summary>
public sealed class OfficeComAutomate : IOfficeAutomate
{
    private readonly IJournal _journal;

    public OfficeComAutomate(IJournal journal) => _journal = journal;

    public IReadOnlyList<DocumentOffice> Lister()
    {
        var documents = new List<DocumentOffice>();
        DemarrageSta.Executer(() =>
        {
            foreach (var application in Rot.ObjetsActifs())
            {
                try
                {
                    var nom = LireNomApplication(application);
                    if (nom is null)
                    {
                        continue;
                    }

                    if (nom.Contains("Word", StringComparison.OrdinalIgnoreCase))
                    {
                        ListerWord(application, documents);
                    }
                    else if (nom.Contains("Excel", StringComparison.OrdinalIgnoreCase))
                    {
                        ListerExcel(application, documents);
                    }
                    else if (nom.Contains("PowerPoint", StringComparison.OrdinalIgnoreCase))
                    {
                        ListerPowerPoint(application, documents);
                    }
                }
                catch (Exception exception) when (EstErreurCom(exception))
                {
                    _journal.Avertissement("copie de secours", $"session Office ignorée : {exception.Message}");
                }
            }
        });

        // La même session peut apparaître sous plusieurs monikers dans la table des objets : on rend
        // chaque document UNE fois (type + chemin), sinon une copie serait prise plusieurs fois.
        var vus = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        var uniques = new List<DocumentOffice>(documents.Count);
        foreach (var document in documents)
        {
            if (vus.Add(((int)document.Type).ToString(System.Globalization.CultureInfo.InvariantCulture) + "|" + document.Chemin))
            {
                uniques.Add(document);
            }
        }

        return uniques;
    }

    public void EcrireCopieSecours(DocumentOffice document, string destination)
    {
        var trouve = false;
        DemarrageSta.Executer(() =>
        {
            foreach (var application in Rot.ObjetsActifs())
            {
                try
                {
                    var nom = LireNomApplication(application);
                    if (nom is null)
                    {
                        continue;
                    }

                    var dynamique = (dynamic)application;
                    var fait = document.Type switch
                    {
                        TypeOffice.Word when nom.Contains("Word", StringComparison.OrdinalIgnoreCase) =>
                            CopierWord(application, document.Chemin, destination),
                        TypeOffice.Excel when nom.Contains("Excel", StringComparison.OrdinalIgnoreCase) =>
                            CopierParSaveCopyAs(dynamique.Workbooks, document.Chemin, destination),
                        TypeOffice.PowerPoint when nom.Contains("PowerPoint", StringComparison.OrdinalIgnoreCase) =>
                            CopierParSaveCopyAs(dynamique.Presentations, document.Chemin, destination),
                        _ => false,
                    };
                    if (fait)
                    {
                        trouve = true;

                        return;
                    }
                }
                catch (Exception exception) when (EstErreurCom(exception))
                {
                    _journal.Avertissement("copie de secours", $"session Office ignorée : {exception.Message}");
                }
            }
        });

        if (!trouve)
        {
            throw new ErreurAgent(
                "copie de secours",
                $"le document « {document.Nom} » n’est plus ouvert dans Office");
        }
    }

    private static string? LireNomApplication(object application)
    {
        try
        {
            dynamic dynamique = application;
            return (string?)dynamique.Name;
        }
        catch (Exception exception) when (EstErreurCom(exception))
        {
            return null;
        }
    }

    private static void ListerWord(object application, List<DocumentOffice> documents)
    {
        dynamic applicationDynamique = application;
        foreach (dynamic document in applicationDynamique.Documents)
        {
            var chemin = (string)document.FullName;
            if (string.IsNullOrEmpty(chemin))
            {
                continue;
            }
            documents.Add(new DocumentOffice(TypeOffice.Word, chemin, LireEnregistre(document.Saved), (string)document.Name));
        }
    }

    private static void ListerExcel(object application, List<DocumentOffice> documents)
    {
        dynamic applicationDynamique = application;
        foreach (dynamic classeur in applicationDynamique.Workbooks)
        {
            var chemin = (string)classeur.FullName;
            if (string.IsNullOrEmpty(chemin))
            {
                continue;
            }
            documents.Add(new DocumentOffice(TypeOffice.Excel, chemin, LireEnregistre(classeur.Saved), (string)classeur.Name));
        }
    }

    private static void ListerPowerPoint(object application, List<DocumentOffice> documents)
    {
        dynamic applicationDynamique = application;
        foreach (dynamic presentation in applicationDynamique.Presentations)
        {
            var chemin = (string)presentation.FullName;
            if (string.IsNullOrEmpty(chemin))
            {
                continue;
            }
            documents.Add(new DocumentOffice(TypeOffice.PowerPoint, chemin, LireEnregistre(presentation.Saved), (string)presentation.Name));
        }
    }

    /// <summary>
    /// Word et Excel rendent <c>Saved</c> en booléen, PowerPoint en entier (MsoTriState) : on lit les deux
    /// sans jamais jeter sur une conversion.
    /// </summary>
    private static bool LireEnregistre(object? valeur)
    {
        if (valeur is bool booleen)
        {
            return booleen;
        }

        try
        {
            return Convert.ToInt32(valeur, System.Globalization.CultureInfo.InvariantCulture) != 0;
        }
        catch (FormatException)
        {
            return false;
        }
        catch (InvalidCastException)
        {
            return false;
        }
        catch (OverflowException)
        {
            return false;
        }
    }

    private static bool CopierWord(object application, string chemin, string destination)
    {
        dynamic applicationDynamique = application;
        foreach (dynamic document in applicationDynamique.Documents)
        {
            if (!string.Equals((string)document.FullName, chemin, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            var flatOpc = (string)document.Content.WordOpenXML;
            ConversionWord.EcrireDocxDepuisFlatOpc(flatOpc, destination);

            return true;
        }

        return false;
    }

    private static bool CopierParSaveCopyAs(dynamic collection, string chemin, string destination)
    {
        foreach (dynamic element in collection)
        {
            if (!string.Equals((string)element.FullName, chemin, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }

            element.SaveCopyAs(destination);

            return true;
        }

        return false;
    }

    private static bool EstErreurCom(Exception exception) =>
        exception is COMException
            or RuntimeBinderException
            or InvalidCastException
            or MissingMemberException
            or ArgumentException
            or InvalidOperationException;
}
