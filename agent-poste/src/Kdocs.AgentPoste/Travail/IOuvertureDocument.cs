namespace Kdocs.AgentPoste.Travail;

/// <summary>Ouverture d'un document de la GED depuis le poste (utilisée par le gestionnaire d'URL).</summary>
public interface IOuvertureDocument
{
    Task<FicheDocumentTravail> OuvrirAsync(int document, CancellationToken annulation = default);
}
