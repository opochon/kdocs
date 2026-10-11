using System.Security.Cryptography;

namespace Kdocs.AgentPoste;

/// <summary>Empreinte d'un contenu (SHA-256, hexadécimal minuscule) : la même que le serveur GEDv2.</summary>
public interface IEmpreinte
{
    string CalculerFichier(string chemin);
}

public sealed class EmpreinteSha256 : IEmpreinte
{
    public static readonly EmpreinteSha256 Instance = new();

    public string CalculerFichier(string chemin)
    {
        using var flux = new FileStream(chemin, FileMode.Open, FileAccess.Read, FileShare.Read);
        using var sha = SHA256.Create();

        return Convert.ToHexStringLower(sha.ComputeHash(flux));
    }
}
