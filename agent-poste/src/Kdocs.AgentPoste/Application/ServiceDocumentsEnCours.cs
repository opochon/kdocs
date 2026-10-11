using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Application;

/// <summary>Un document en cours : son état d'envoi, un éventuel conflit, la dernière copie de secours,
/// ou le motif d'un refus d'ouverture (format hors liste blanche).</summary>
public sealed record DocumentEnCours(
    int Document,
    string Nom,
    string Chemin,
    string Etat,
    bool Conflit,
    int EnAttente,
    DateTimeOffset? DernierEnvoiUtc,
    string? CopieSecours,
    DateTimeOffset? CopieSecoursLeUtc,
    string? Refus = null);

/// <summary>
/// « Mes documents en cours » de l'icône de barre des tâches : chaque document ouvert (fiche de travail),
/// avec son état (envoyé ou en attente) et la dernière copie de secours disponible. Les documents refusés
/// (format hors liste blanche) y apparaissent aussi, avec leur motif : l'utilisateur comprend pourquoi un
/// document de la GED ne s'est pas ouvert sur son poste.
/// </summary>
public sealed class ServiceDocumentsEnCours
{
    private readonly IDepotFiches _fiches;
    private readonly IDepotFile _file;
    private readonly RacineAgent _racine;
    private readonly IDepotRefus? _refus;

    public ServiceDocumentsEnCours(IDepotFiches fiches, IDepotFile file, RacineAgent racine, IDepotRefus? refus = null)
    {
        _fiches = fiches;
        _file = file;
        _racine = racine;
        _refus = refus;
    }

    public IReadOnlyList<DocumentEnCours> Lister()
    {
        var attente = _file.Charger()
            .GroupBy(e => e.Document)
            .ToDictionary(g => g.Key, g => g.Count());

        var fiches = _fiches.Lister().OrderBy(f => f.Document).ToList();
        var ouverts = fiches.Select(f => f.Document).ToHashSet();

        var lignes = fiches
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

        // Le dernier refus par document, pour les documents qui n'ont PAS de fiche de travail (sinon
        // l'ouverture aurait réussi et le refus n'a plus lieu d'être affiché).
        var refus = _refus?.Lister()
            .GroupBy(r => r.Document)
            .Select(groupe => groupe.OrderByDescending(r => r.QuandUtc).First())
            .Where(dernier => !ouverts.Contains(dernier.Document))
            .Select(dernier => new DocumentEnCours(
                dernier.Document, dernier.Nom, string.Empty, "refusé", false, 0, null, null, null, dernier.Cause));
        if (refus is not null)
        {
            lignes.AddRange(refus);
        }

        return lignes.OrderBy(ligne => ligne.Document).ToList();
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
