using System.Security.Cryptography;
using System.Text;

namespace Kdocs.AgentPoste.Configuration;

/// <summary>
/// Coffre du jeton d'appareil. Le jeton est un secret qui vaut un accès : il n'est jamais écrit en clair.
/// </summary>
public interface ICoffreJeton
{
    bool Existe { get; }

    string? Lire();

    void Ecrire(string jeton);

    /// <summary>Retire le jeton du poste (ré-enrôlement volontaire). Ce n'est pas une donnée utilisateur.</summary>
    void Effacer();
}

/// <summary>
/// Jeton chiffré par DPAPI (Windows Data Protection) à portée de l'UTILISATEUR courant : le fichier
/// <c>jeton.dpapi</c> n'est déchiffrable que par ce compte Windows sur ce poste. Une copie du fichier
/// volée par un autre utilisateur ne rend rien.
/// </summary>
public sealed class CoffreJetonDpapi : ICoffreJeton
{
    // Entropie fixe : lie le chiffré à cette application.
    private static readonly byte[] Entropie = Encoding.UTF8.GetBytes("K-Docs/agent-poste/jeton-appareil/v1");

    private readonly string _chemin;

    public CoffreJetonDpapi(string chemin) => _chemin = chemin;

    public bool Existe => File.Exists(_chemin);

    public string? Lire()
    {
        if (!File.Exists(_chemin))
        {
            return null;
        }

        var protege = File.ReadAllBytes(_chemin);
        var clair = ProtectedData.Unprotect(protege, Entropie, DataProtectionScope.CurrentUser);

        return Encoding.UTF8.GetString(clair);
    }

    public void Ecrire(string jeton)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(jeton);
        var dossier = Path.GetDirectoryName(_chemin);
        if (!string.IsNullOrEmpty(dossier))
        {
            Directory.CreateDirectory(dossier);
        }

        var protege = ProtectedData.Protect(Encoding.UTF8.GetBytes(jeton), Entropie, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(_chemin, protege);
    }

    public void Effacer()
    {
        if (File.Exists(_chemin))
        {
            File.Delete(_chemin);
        }
    }
}

/// <summary>Coffre en mémoire des tests : aucun disque, aucun DPAPI.</summary>
public sealed class CoffreJetonMemoire : ICoffreJeton
{
    private string? _jeton;

    public bool Existe => _jeton is not null;

    public string? Lire() => _jeton;

    public void Ecrire(string jeton) => _jeton = jeton;

    public void Effacer() => _jeton = null;
}
