using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Application;

/// <summary>Un document en cours : son état d'envoi, un éventuel conflit, et la dernière copie de secours.</summary>
public sealed record DocumentEnCours(
    int Document,
    string Nom,
    string Chemin,
    string Etat,
    bool Conflit,
    int EnAttente,
    DateTimeOffset? DernierEnvoiUtc,
    string? CopieSecours,
    DateTimeOffset? CopieSecoursLeUtc);

/// <summary>
/// « Mes documents en cours » de l'icône de barre des tâches : chaque document ouvert (fiche de travail),
/// avec son état (envoyé ou en attente) et la dernière copie de secours disponible.
/// </summary>
public sealed class ServiceDocumentsEnCours
{
    private readonly IDepotFiches _fiches;
    private readonly IDepotFile _file;
    private readonly RacineAgent _racine;

    public ServiceDocumentsEnCours(IDepotFiches fiches, IDepotFile file, RacineAgent racine)
    {
        _fiches = fiches;
        _file = file;
        _racine = racine;
    }

    public IReadOnlyList<DocumentEnCours> Lister()
    {
        var attente = _file.Charger()
            .GroupBy(e => e.Document)
            .ToDictionary(g => g.Key, g => g.Count());

        return _fiches.Lister()
            .OrderBy(f => f.Document)
            .Select(fiche =>
            {
                var enAttente = attente.TryGetValue(fiche.Document, out var nombre) ? nombre : 0;
                var copie = DerniereCopie(fiche.Document);

                return new DocumentEnCours(
                    fiche.Document,
                    fiche.Nom,
                    fiche.Chemin,
                    enAttente > 0 ? "en attente" : "envoyé",
                    fiche.Conflit,
                    enAttente,
                    fiche.DernierEnvoiUtc,
                    copie?.Chemin,
                    copie?.Quand);
            })
            .ToList();
    }

    /// <summary>Dernière copie de secours locale d'un document, ou null.</summary>
    public (string Chemin, DateTimeOffset Quand)? DerniereCopie(int document)
    {
        var dossier = _racine.DossierSecoursDe(document);
        if (!Directory.Exists(dossier))
        {
            return null;
        }

        return Directory.EnumerateFiles(dossier)
            .Select(chemin => new FileInfo(chemin))
            .Where(info => info.Exists && info.Length > 0)
            .OrderByDescending(info => info.LastWriteTimeUtc)
            .Select(info => ((string Chemin, DateTimeOffset Quand)?)(info.FullName, new DateTimeOffset(info.LastWriteTime)))
            .FirstOrDefault();
    }
}
