using System.Security.Cryptography;
using System.Text;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>Faux serveur d'édition : enregistre les gestes réellement demandés, sans réseau.</summary>
public sealed class FauxClientEdition : IClientEdition
{
    public Dictionary<int, EtatDocument> Etats { get; } = [];

    public Dictionary<int, byte[]> Contenus { get; } = [];

    /// <summary>Force l'empreinte annoncée par le serveur (pour prouver le contrôle d'intégrité).</summary>
    public Dictionary<int, string> EmpreinteForcee { get; } = [];

    public List<(int Document, string Chemin, string Empreinte, int VersionBase)> Depots { get; } = [];

    public List<(int Document, string Chemin)> Secours { get; } = [];

    public Func<int, string, string, int, ResultatDepot> ReponseDepot { get; set; } =
        (document, chemin, empreinte, versionBase) => new(versionBase + 1, empreinte, false, versionBase, versionBase, false);

    /// <summary>Quand non nul, chaque dépôt lève cette exception (panne réseau scriptée).</summary>
    public Exception? ErreurDepot { get; set; }

    public int CompteurOuverture { get; private set; }

    public int CompteurSignal { get; private set; }

    public int CompteurFermeture { get; private set; }

    public Task<EtatDocument> EtatAsync(int document, CancellationToken annulation = default)
    {
        if (Etats.TryGetValue(document, out var etat))
        {
            return Task.FromResult(etat);
        }

        return Task.FromResult(new EtatDocument(
            document, $"document-{document}.docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
            0, string.Empty, 1));
    }

    public Task<FichierTelecharge> TelechargerAsync(int document, string cheminDestination, CancellationToken annulation = default)
    {
        var contenu = Contenus.TryGetValue(document, out var octets) ? octets : Encoding.UTF8.GetBytes($"contenu-{document}");
        Directory.CreateDirectory(Path.GetDirectoryName(cheminDestination)!);
        File.WriteAllBytes(cheminDestination, contenu);
        var empreinte = EmpreinteForcee.TryGetValue(document, out var forcee)
            ? forcee
            : Convert.ToHexStringLower(SHA256.HashData(contenu));
        var version = Etats.TryGetValue(document, out var etat) ? etat.Version : 1;

        return Task.FromResult(new FichierTelecharge(cheminDestination, empreinte, version));
    }

    public Task<ResultatDepot> DeposerVersionAsync(
        int document,
        string cheminFichier,
        string empreinte,
        int versionBase,
        CancellationToken annulation = default)
    {
        Depots.Add((document, cheminFichier, empreinte, versionBase));
        if (ErreurDepot is not null)
        {
            throw ErreurDepot;
        }

        return Task.FromResult(ReponseDepot(document, cheminFichier, empreinte, versionBase));
    }

    public Task OuvrirAsync(int document, CancellationToken annulation = default)
    {
        CompteurOuverture++;

        return Task.CompletedTask;
    }

    public Task<bool> SignalerAsync(int document, CancellationToken annulation = default)
    {
        CompteurSignal++;

        return Task.FromResult(true);
    }

    public Task FermerAsync(int document, CancellationToken annulation = default)
    {
        CompteurFermeture++;

        return Task.CompletedTask;
    }

    public Task<MetaSecours> DeposerSecoursAsync(int document, string cheminFichier, CancellationToken annulation = default)
    {
        Secours.Add((document, cheminFichier));

        return Task.FromResult(new MetaSecours("POSTE-TEST", "2026-10-11 10:00:00", string.Empty, new FileInfo(cheminFichier).Length, Path.GetFileName(cheminFichier)));
    }

    public Task<IReadOnlyList<MetaSecours>> ListerSecoursAsync(int document, CancellationToken annulation = default) =>
        Task.FromResult<IReadOnlyList<MetaSecours>>([]);
}

/// <summary>Stabilité scriptée : le fichier est considéré stable (ou jamais stable) selon le test.</summary>
public sealed class FauxFichierStable : Kdocs.AgentPoste.Envoi.IFichierStable
{
    public bool Stable { get; set; } = true;

    public int Appels { get; private set; }

    public Task<bool> AttendreAsync(string chemin, TimeSpan delaiMax, CancellationToken annulation = default)
    {
        Appels++;

        return Task.FromResult(Stable);
    }
}

/// <summary>Ouvreur scripté : note le chemin au lieu de lancer Office.</summary>
public sealed class FauxOuvreur : IOuvreurFichier
{
    public List<string> Ouverts { get; } = [];

    public void Ouvrir(string chemin) => Ouverts.Add(chemin);
}
