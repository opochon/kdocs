using System.Text.Json;

namespace Kdocs.AgentPoste.Travail;

/// <summary>
/// Un refus d'ouverture hors liste blanche : le document, son nom tel que reçu du serveur, le motif et
/// l'instant. Il est conservé pour que « Mes documents en cours » puisse l'expliquer à l'utilisateur.
/// </summary>
public sealed record RefusOuverture(int Document, string Nom, string Cause, DateTimeOffset QuandUtc);

/// <summary>Conservation des refus d'ouverture (affichage dans « Mes documents en cours »).</summary>
public interface IDepotRefus
{
    void Enregistrer(RefusOuverture refus);

    /// <summary>Refus connus, du plus récent au plus ancien.</summary>
    IReadOnlyList<RefusOuverture> Lister();
}

/// <summary>
/// Refus d'ouverture sur disque : un fichier JSON par refus sous <c>refus\</c>, borné aux
/// <see cref="Maximum"/> plus récents. Un fichier illisible est signalé et ignoré (jamais un crash).
/// </summary>
public sealed class DepotRefusDisque : IDepotRefus
{
    /// <summary>Nombre de refus conservés : de quoi expliquer les derniers gestes, sans croissance sans fin.</summary>
    public const int Maximum = 50;

    private static readonly JsonSerializerOptions Options = new() { WriteIndented = true };

    private readonly RacineAgent _racine;
    private readonly IJournal? _journal;

    public DepotRefusDisque(RacineAgent racine, IJournal? journal = null)
    {
        _racine = racine;
        _journal = journal;
    }

    public void Enregistrer(RefusOuverture refus)
    {
        // Conservation au mieux : si le disque refuse l'écriture, le refus reste TRACÉ et l'appelant lève
        // quand même son erreur nommée (le refus d'ouverture ne dépend jamais de cette écriture).
        try
        {
            var dossier = _racine.DossierRefus;
            Directory.CreateDirectory(dossier);
            var nom = $"refus-{refus.Document.ToString(System.Globalization.CultureInfo.InvariantCulture)}-"
                + refus.QuandUtc.UtcTicks.ToString(System.Globalization.CultureInfo.InvariantCulture)
                + "-" + Guid.NewGuid().ToString("N")[..8] + ".json";
            var chemin = Path.Combine(dossier, nom);
            var temporaire = chemin + ".tmp";
            File.WriteAllText(temporaire, JsonSerializer.Serialize(refus, Options));
            File.Move(temporaire, chemin, overwrite: true);

            Purger(dossier);
        }
        catch (IOException exception)
        {
            _journal?.Avertissement("refus", $"refus non conservé ({refus.Nom}) : {exception.Message}");
        }
        catch (UnauthorizedAccessException exception)
        {
            _journal?.Avertissement("refus", $"refus non conservé ({refus.Nom}) : {exception.Message}");
        }
    }

    public IReadOnlyList<RefusOuverture> Lister()
    {
        var refus = new List<RefusOuverture>();
        try
        {
            if (!Directory.Exists(_racine.DossierRefus))
            {
                return refus;
            }

            foreach (var fichier in Directory.EnumerateFiles(_racine.DossierRefus, "refus-*.json"))
            {
                var lu = LireFichier(fichier);
                if (lu is not null)
                {
                    refus.Add(lu);
                }
            }
        }
        catch (IOException exception)
        {
            _journal?.Avertissement("refus", $"refus illisibles : {exception.Message}");
        }
        catch (UnauthorizedAccessException exception)
        {
            _journal?.Avertissement("refus", $"refus illisibles : {exception.Message}");
        }

        return refus.OrderByDescending(r => r.QuandUtc).ThenByDescending(r => r.Document).ToList();
    }

    private RefusOuverture? LireFichier(string chemin)
    {
        try
        {
            return JsonSerializer.Deserialize<RefusOuverture>(File.ReadAllText(chemin));
        }
        catch (JsonException exception)
        {
            _journal?.Avertissement(
                "refus", $"refus illisible ignoré ({Path.GetFileName(chemin)}) : {exception.Message}");

            return null;
        }
        catch (IOException exception)
        {
            _journal?.Avertissement(
                "refus", $"refus non lu ({Path.GetFileName(chemin)}) : {exception.Message}");

            return null;
        }
        catch (UnauthorizedAccessException exception)
        {
            _journal?.Avertissement(
                "refus", $"refus non lu ({Path.GetFileName(chemin)}) : {exception.Message}");

            return null;
        }
    }

    /// <summary>Ne garde que les <see cref="Maximum"/> refus les plus récents (le reste n'explique plus rien).</summary>
    private void Purger(string dossier)
    {
        try
        {
            var anciens = Directory.EnumerateFiles(dossier, "refus-*.json")
                .OrderByDescending(File.GetLastWriteTimeUtc)
                .Skip(Maximum)
                .ToList();
            foreach (var ancien in anciens)
            {
                File.Delete(ancien);
            }
        }
        catch (IOException exception)
        {
            _journal?.Avertissement("refus", $"purge des refus impossible : {exception.Message}");
        }
        catch (UnauthorizedAccessException exception)
        {
            _journal?.Avertissement("refus", $"purge des refus impossible : {exception.Message}");
        }
    }
}
