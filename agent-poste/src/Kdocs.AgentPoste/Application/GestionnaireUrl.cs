using Kdocs.AgentPoste.Protocole;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// Gestionnaire du protocole <c>kdocs://</c> : il analyse STRICTEMENT le lien reçu de Windows, journalise
/// tout refus, et n'exécute que l'action « ouvrir » sur l'identifiant validé. Le lien ne peut désigner ni
/// un autre serveur (l'adresse est fixée à l'enrôlement), ni un chemin, ni une commande.
/// </summary>
public sealed class GestionnaireUrl
{
    private readonly IOuvertureDocument _ouverture;
    private readonly IJournal _journal;

    public GestionnaireUrl(IOuvertureDocument ouverture, IJournal journal)
    {
        _ouverture = ouverture;
        _journal = journal;
    }

    public async Task<ResultatUrl> TraiterAsync(string? url, CancellationToken annulation = default)
    {
        var analyse = AnalyseUrlKdocs.Analyser(url);
        if (!analyse.Accepte || analyse.Url is null)
        {
            _journal.Avertissement("lien kdocs", $"refusé : {analyse.Refus} (lien « {Assainir(url)} »)");

            return analyse;
        }

        try
        {
            await _ouverture.OuvrirAsync(analyse.Url.Document, annulation).ConfigureAwait(false);
            _journal.Info("lien kdocs", $"action {analyse.Url.Action} sur le document {analyse.Url.Document}");
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("lien kdocs", $"document {analyse.Url.Document} : ouverture impossible", exception);
        }

        return analyse;
    }

    /// <summary>Nettoie un lien avant de le journaliser : pas de caractère de contrôle (injection de ligne), longueur bornée.</summary>
    internal static string Assainir(string? valeur)
    {
        if (string.IsNullOrEmpty(valeur))
        {
            return string.Empty;
        }

        var propre = new string(valeur.Where(c => !char.IsControl(c)).ToArray());

        return propre.Length <= 200 ? propre : propre[..200] + "…";
    }
}
