using System.Text.Json;
using System.Text.Json.Serialization;

namespace Kdocs.AgentPoste.Envoi;

/// <summary>File d'envoi persistante : une entrée JSON par envoi, plus son instantané à côté.</summary>
public interface IDepotFile
{
    IReadOnlyList<EntreeFile> Charger();

    void Enregistrer(EntreeFile entree);

    /// <summary>Retire l'entrée (envoi livré) : c'est de la comptabilité interne, pas une donnée utilisateur.</summary>
    void Supprimer(string id);
}

/// <summary>File sur disque sous <c>File\*.json</c>, instantanés sous <c>File\instantanes\&lt;id&gt;\</c>.</summary>
public sealed class DepotFileDisque : IDepotFile
{
    private static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true,
        Converters = { new JsonStringEnumConverter() },
    };

    private readonly string _dossier;
    private readonly IJournal? _journal;

    public DepotFileDisque(string dossierFile, IJournal? journal = null)
    {
        _dossier = dossierFile;
        _journal = journal;
    }

    public static string DossierInstantanes(string dossierFile) => Path.Combine(dossierFile, "instantanes");

    public IReadOnlyList<EntreeFile> Charger()
    {
        var entrees = new List<EntreeFile>();
        if (!Directory.Exists(_dossier))
        {
            return entrees;
        }

        foreach (var fichier in Directory.EnumerateFiles(_dossier, "*.json"))
        {
            try
            {
                var entree = JsonSerializer.Deserialize<EntreeFile>(File.ReadAllText(fichier), Options);
                if (entree is not null && !string.IsNullOrEmpty(entree.Id))
                {
                    entrees.Add(entree);
                }
            }
            catch (JsonException exception)
            {
                // Entrée illisible : jamais un crash, mais JAMAIS muette non plus (l'instantané, lui, reste).
                _journal?.Avertissement(
                    "file d’envoi",
                    $"entrée illisible ignorée ({Path.GetFileName(fichier)}) : {exception.Message}");
            }
            catch (IOException exception)
            {
                _journal?.Avertissement(
                    "file d’envoi",
                    $"entrée non lue ({Path.GetFileName(fichier)}) : {exception.Message}");
            }
        }

        return entrees;
    }

    public void Enregistrer(EntreeFile entree)
    {
        Directory.CreateDirectory(_dossier);
        var chemin = CheminEntree(entree.Id);
        var temporaire = chemin + ".tmp";
        File.WriteAllText(temporaire, JsonSerializer.Serialize(entree, Options));
        File.Move(temporaire, chemin, overwrite: true);
    }

    public void Supprimer(string id)
    {
        var chemin = CheminEntree(id);
        if (File.Exists(chemin))
        {
            File.Delete(chemin);
        }
    }

    private string CheminEntree(string id) => Path.Combine(_dossier, id + ".json");
}
