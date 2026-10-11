using System.Text.Json;

namespace Kdocs.AgentPoste.Travail;

/// <summary>
/// Fiches de travail sur disque : balayage de <c>Travail\&lt;id&gt;\*.kdocs.json</c>. Une fiche illisible est
/// ignorée (jamais un crash) mais signalée au journal par l'appelant si besoin.
/// </summary>
public sealed class DepotFichesDisque : IDepotFiches
{
    private static readonly JsonSerializerOptions Options = new() { WriteIndented = true };

    private readonly RacineAgent _racine;
    private readonly IJournal? _journal;

    public DepotFichesDisque(RacineAgent racine, IJournal? journal = null)
    {
        _racine = racine;
        _journal = journal;
    }

    public FicheDocumentTravail? Lire(int document)
    {
        var fiche = LireFichier(_racine.CheminFiche(document, NomConnu(document) ?? "document"));

        return fiche;
    }

    public IReadOnlyList<FicheDocumentTravail> Lister()
    {
        var fiches = new List<FicheDocumentTravail>();
        if (!Directory.Exists(_racine.DossierTravail))
        {
            return fiches;
        }

        foreach (var fichier in Directory.EnumerateFiles(_racine.DossierTravail, "*" + RacineAgent.ExtensionFiche, SearchOption.AllDirectories))
        {
            var fiche = LireFichier(fichier);
            if (fiche is not null)
            {
                fiches.Add(fiche);
            }
        }

        return fiches;
    }

    public void Ecrire(FicheDocumentTravail fiche)
    {
        var dossier = Path.GetDirectoryName(fiche.Chemin);
        if (!string.IsNullOrEmpty(dossier))
        {
            Directory.CreateDirectory(dossier);
        }

        var cheminFiche = fiche.Chemin + RacineAgent.ExtensionFiche;
        var temporaire = cheminFiche + ".tmp";
        File.WriteAllText(temporaire, JsonSerializer.Serialize(fiche, Options));
        File.Move(temporaire, cheminFiche, overwrite: true);
    }

    private FicheDocumentTravail? LireFichier(string cheminFiche)
    {
        if (!File.Exists(cheminFiche))
        {
            return null;
        }

        try
        {
            return JsonSerializer.Deserialize<FicheDocumentTravail>(File.ReadAllText(cheminFiche));
        }
        catch (JsonException exception)
        {
            _journal?.Avertissement(
                "travail",
                $"fiche illisible ignorée ({Path.GetFileName(cheminFiche)}) : {exception.Message}");

            return null;
        }
        catch (IOException exception)
        {
            _journal?.Avertissement(
                "travail",
                $"fiche non lue ({Path.GetFileName(cheminFiche)}) : {exception.Message}");

            return null;
        }
    }

    /// <summary>Retrouve le nom du document depuis les fiches déjà présentes (repli : « document »).</summary>
    private string? NomConnu(int document)
    {
        if (!Directory.Exists(_racine.DossierTravailDe(document)))
        {
            return null;
        }

        foreach (var fichier in Directory.EnumerateFiles(_racine.DossierTravailDe(document), "*" + RacineAgent.ExtensionFiche))
        {
            var fiche = LireFichier(fichier);
            if (fiche is not null && !string.IsNullOrEmpty(fiche.Nom))
            {
                return fiche.Nom;
            }
        }

        return null;
    }
}
