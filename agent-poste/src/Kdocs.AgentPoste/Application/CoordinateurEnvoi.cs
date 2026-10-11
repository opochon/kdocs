using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// Fait le lien entre une modification constatée dans le dossier de travail et la file d'envoi.
///
///   - rien à faire si le contenu est identique à la dernière empreinte CONNUE du serveur (le simple
///     téléchargement à l'ouverture ne déclenche donc jamais d'envoi) ;
///   - sinon, mise en file (fichier figé) puis essai immédiat ; la file reprend toute seule ensuite ;
///   - après une livraison, la fiche retient l'empreinte et la version : l'historique reste linéaire ;
///   - un conflit signalé par le serveur est noté dans la fiche (version parallèle, rien d'écrasé).
/// </summary>
public sealed class CoordinateurEnvoi
{
    private readonly IDepotFiches _fiches;
    private readonly GestionnaireFileEnvoi _file;
    private readonly IEmpreinte _empreinte;
    private readonly IHorloge _horloge;
    private readonly IJournal _journal;

    public CoordinateurEnvoi(
        IDepotFiches fiches,
        GestionnaireFileEnvoi file,
        IEmpreinte empreinte,
        IHorloge horloge,
        IJournal journal)
    {
        _fiches = fiches;
        _file = file;
        _empreinte = empreinte;
        _horloge = horloge;
        _journal = journal;
    }

    /// <summary>Traite une modification de fichier : met en file si le contenu a changé, puis livre.</summary>
    public async Task<IReadOnlyList<ResultatEnvoi>> TraiterFichierAsync(
        string chemin,
        CancellationToken annulation = default)
    {
        var fiche = FicheDe(chemin);
        if (fiche is null)
        {
            _journal.Avertissement("coordination", $"aucune fiche pour {chemin}, modification ignorée");

            return [];
        }
        if (!File.Exists(chemin))
        {
            return [];
        }

        var empreinte = _empreinte.CalculerFichier(chemin);
        if (string.Equals(empreinte, fiche.DerniereEmpreinteConnue, StringComparison.OrdinalIgnoreCase))
        {
            return [];
        }

        var entree = await _file.MettreEnFileAsync(fiche.Document, chemin, fiche.DerniereVersionConnue, annulation)
            .ConfigureAwait(false);
        if (entree is null)
        {
            return [];
        }

        return await TraiterFileAsync(annulation).ConfigureAwait(false);
    }

    /// <summary>Traite les entrées dues de la file (reprise après coupure ou redémarrage).</summary>
    public async Task<IReadOnlyList<ResultatEnvoi>> TraiterFileAsync(CancellationToken annulation = default)
    {
        var resultats = await _file.TraiterAsync(annulation).ConfigureAwait(false);
        foreach (var resultat in resultats)
        {
            if (!resultat.Livre)
            {
                continue;
            }

            var fiche = FicheDe(resultat.Entree.CheminTravail) ?? _fiches.Lire(resultat.Entree.Document);
            if (fiche is null)
            {
                continue;
            }

            fiche.DerniereEmpreinteConnue = resultat.Entree.Empreinte;
            fiche.DerniereVersionConnue = resultat.Version > 0 ? resultat.Version : fiche.DerniereVersionConnue;
            fiche.DernierEnvoiUtc = _horloge.MaintenantUtc;
            fiche.Conflit = resultat.Conflit;
            fiche.Message = resultat.Message;
            if (resultat.Conflit)
            {
                fiche.DernierConflitLeUtc = _horloge.MaintenantUtc;
            }
            _fiches.Ecrire(fiche);
        }

        return resultats;
    }

    private FicheDocumentTravail? FicheDe(string chemin)
    {
        var cible = Path.GetFullPath(chemin);

        return _fiches.Lister()
            .FirstOrDefault(f => string.Equals(Path.GetFullPath(f.Chemin), cible, StringComparison.OrdinalIgnoreCase));
    }
}
