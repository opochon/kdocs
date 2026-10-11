using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// Entretient le verrou INFORMATIF « ouvert par X sur POSTE depuis HH:MM » : ouverture signalée quand un
/// document de travail est ouvert dans Office, signal régulier tant qu'il l'est, fermeture quand il ne
/// l'est plus. Aucun déverrouillage manuel ; sans signal, le serveur affiche « peut-être abandonné ».
/// </summary>
public sealed class ServiceOuvertures
{
    private readonly IClientEdition _client;
    private readonly RacineAgent _racine;
    private readonly IJournal _journal;
    private readonly HashSet<int> _ouverts = [];

    public ServiceOuvertures(IClientEdition client, RacineAgent racine, IJournal journal)
    {
        _client = client;
        _racine = racine;
        _journal = journal;
    }

    public async Task EntretenirAsync(IReadOnlyList<DocumentOffice> documentsOffice, CancellationToken annulation = default)
    {
        var maintenant = documentsOffice
            .Select(document => _racine.DocumentDansTravail(document.Chemin))
            .Where(id => id is not null)
            .Select(id => id!.Value)
            .ToHashSet();

        foreach (var document in maintenant.Except(_ouverts))
        {
            await _client.OuvrirAsync(document, annulation).ConfigureAwait(false);
            _journal.Info("ouverture", $"document {document} signalé ouvert");
        }

        foreach (var document in maintenant.Intersect(_ouverts))
        {
            await _client.SignalerAsync(document, annulation).ConfigureAwait(false);
        }

        foreach (var document in _ouverts.Except(maintenant))
        {
            await _client.FermerAsync(document, annulation).ConfigureAwait(false);
            _journal.Info("ouverture", $"document {document} signalé fermé");
        }

        _ouverts.Clear();
        _ouverts.UnionWith(maintenant);
    }

    public IReadOnlySet<int> Ouverts => _ouverts;
}
