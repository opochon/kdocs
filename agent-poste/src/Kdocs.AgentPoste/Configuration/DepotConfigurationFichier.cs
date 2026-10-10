using System.Text.Json;

namespace Kdocs.AgentPoste.Configuration;

/// <summary>
/// Configuration sur disque (<c>config.json</c>), écrite en entier puis renommée : jamais un fichier
/// tronqué si le poste s'éteint en pleine écriture. Aucune donnée utilisateur n'y est supprimée.
/// </summary>
public sealed class DepotConfigurationFichier : IDepotConfiguration
{
    private static readonly JsonSerializerOptions Options = new() { WriteIndented = true };

    private readonly string _chemin;

    public DepotConfigurationFichier(string chemin) => _chemin = chemin;

    public ConfigurationPoste? Lire()
    {
        if (!File.Exists(_chemin))
        {
            return null;
        }

        try
        {
            var json = File.ReadAllText(_chemin);

            return JsonSerializer.Deserialize<ConfigurationPoste>(json);
        }
        catch (JsonException)
        {
            return null;
        }
        catch (IOException)
        {
            return null;
        }
    }

    public void Ecrire(ConfigurationPoste configuration)
    {
        var dossier = Path.GetDirectoryName(_chemin);
        if (!string.IsNullOrEmpty(dossier))
        {
            Directory.CreateDirectory(dossier);
        }

        var temporaire = _chemin + ".tmp";
        File.WriteAllText(temporaire, JsonSerializer.Serialize(configuration, Options));
        File.Move(temporaire, _chemin, overwrite: true);
    }
}
