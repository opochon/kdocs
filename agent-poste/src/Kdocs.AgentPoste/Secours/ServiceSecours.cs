using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste.Secours;

/// <summary>Résultat d'une copie de secours : ce qui a été écrit localement et si le serveur l'a reçue.</summary>
public sealed record CopieSecours(int Document, TypeOffice Type, string CheminLocal, string Empreinte, bool Envoyee, string? Message);

/// <summary>
/// Copies de secours toutes les deux minutes : pour chaque document ouvert venant du dossier de travail
/// et MODIFIÉ sans être enregistré (<c>Saved = False</c>), une copie est écrite dans
/// <c>%LOCALAPPDATA%\K-Docs\Secours\&lt;id&gt;\</c> sans jamais toucher au document de l'utilisateur, puis la
/// dernière copie part au serveur dans l'espace de secours, hors du fonds.
///
/// Une copie de secours n'est PAS une version : elle ne remplace rien et ne change pas l'état
/// « enregistré ». Aucune donnée utilisateur n'est supprimée.
/// </summary>
public sealed class ServiceSecours
{
    private readonly IOfficeAutomate _office;
    private readonly IClientEdition _client;
    private readonly RacineAgent _racine;
    private readonly IHorloge _horloge;
    private readonly IJournal _journal;

    public ServiceSecours(
        IOfficeAutomate office,
        IClientEdition client,
        RacineAgent racine,
        IHorloge horloge,
        IJournal journal)
    {
        _office = office;
        _client = client;
        _racine = racine;
        _horloge = horloge;
        _journal = journal;
    }

    public static TimeSpan Intervalle { get; } = TimeSpan.FromMinutes(2);

    public async Task<IReadOnlyList<CopieSecours>> ExecuterPasseAsync(CancellationToken annulation = default)
    {
        var copies = new List<CopieSecours>();
        foreach (var document in _office.Lister())
        {
            annulation.ThrowIfCancellationRequested();
            if (document.Enregistre)
            {
                // Enregistré : rien de non validé à sauvegarder, la surveillance en fait une version.
                continue;
            }

            var idDocument = _racine.DocumentDansTravail(document.Chemin);
            if (idDocument is null)
            {
                continue;
            }

            var copie = await CopierUnDocumentAsync(idDocument.Value, document, annulation).ConfigureAwait(false);
            if (copie is not null)
            {
                copies.Add(copie);
            }
        }

        return copies;
    }

    private async Task<CopieSecours?> CopierUnDocumentAsync(
        int document,
        DocumentOffice ouvert,
        CancellationToken annulation)
    {
        var dossier = _racine.DossierSecoursDe(document);
        Directory.CreateDirectory(dossier);
        var destination = Path.Combine(
            dossier,
            string.Format(System.Globalization.CultureInfo.InvariantCulture, "{0:yyyyMMdd-HHmmss}", _horloge.MaintenantUtc)
            + Extension(ouvert));

        try
        {
            _office.EcrireCopieSecours(ouvert, destination);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("copie de secours", $"document {document} : copie impossible", exception);

            return new CopieSecours(document, ouvert.Type, destination, string.Empty, false, exception.Message);
        }

        var info = new FileInfo(destination);
        if (!info.Exists || info.Length == 0)
        {
            _journal.Erreur("copie de secours", $"document {document} : copie vide, ignorée");

            return new CopieSecours(document, ouvert.Type, destination, string.Empty, false, "copie vide");
        }

        var empreinte = EmpreinteSha256.Instance.CalculerFichier(destination);
        try
        {
            await _client.DeposerSecoursAsync(document, destination, annulation).ConfigureAwait(false);
            _journal.Info("copie de secours", $"document {document} : copie {Path.GetFileName(destination)} déposée");

            return new CopieSecours(document, ouvert.Type, destination, empreinte, true, null);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Avertissement(
                "copie de secours",
                $"document {document} : copie locale gardée, envoi reporté ({exception.Message})");

            return new CopieSecours(document, ouvert.Type, destination, empreinte, false, exception.Message);
        }
    }

    private static string Extension(DocumentOffice document)
    {
        var extension = document.Type == TypeOffice.Word
            ? ".docx"
            : Path.GetExtension(document.Chemin);

        return extension switch
        {
            ".xlsx" or ".xlsm" or ".xls" => extension,
            ".pptx" or ".pptm" or ".ppt" => extension,
            ".docx" or ".docm" => extension,
            _ => document.Type switch
            {
                TypeOffice.Word => ".docx",
                TypeOffice.Excel => ".xlsx",
                _ => ".pptx",
            },
        };
    }
}
