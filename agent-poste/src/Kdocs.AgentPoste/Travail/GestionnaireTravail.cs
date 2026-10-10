using System.Diagnostics;
using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste.Travail;

/// <summary>Ouverture d'un fichier « comme un double-clic » (associations Windows de l'utilisateur).</summary>
public interface IOuvreurFichier
{
    void Ouvrir(string chemin);
}

public sealed class OuvreurShell : IOuvreurFichier
{
    public void Ouvrir(string chemin) =>
        Process.Start(new ProcessStartInfo(chemin) { UseShellExecute = true });
}

/// <summary>
/// Dossier de travail : télécharge le document depuis le serveur, VÉRIFIE l'empreinte, écrit la fiche
/// (empreinte de base) puis ouvre le fichier local comme un double-clic. Word n'ouvre jamais qu'un
/// fichier local : aucune session web, aucun lien qui expire.
/// </summary>
public sealed class GestionnaireTravail
{
    private readonly RacineAgent _racine;
    private readonly IClientEdition _client;
    private readonly IDepotFiches _fiches;
    private readonly IOuvreurFichier _ouvreur;
    private readonly IHorloge _horloge;
    private readonly IJournal _journal;

    public GestionnaireTravail(
        RacineAgent racine,
        IClientEdition client,
        IDepotFiches fiches,
        IOuvreurFichier ouvreur,
        IHorloge horloge,
        IJournal journal)
    {
        _racine = racine;
        _client = client;
        _fiches = fiches;
        _ouvreur = ouvreur;
        _horloge = horloge;
        _journal = journal;
    }

    public async Task<FicheDocumentTravail> OuvrirAsync(int document, CancellationToken annulation = default)
    {
        const string etape = "ouverture depuis la GED";
        var etat = await _client.EtatAsync(document, annulation).ConfigureAwait(false);
        var chemin = _racine.CheminTravail(document, etat.Nom);
        var telecharge = await _client.TelechargerAsync(document, chemin, annulation).ConfigureAwait(false);

        var empreinte = EmpreinteSha256.Instance.CalculerFichier(chemin);
        if (!string.IsNullOrEmpty(telecharge.Empreinte)
            && !string.Equals(empreinte, telecharge.Empreinte, StringComparison.OrdinalIgnoreCase))
        {
            throw new ErreurAgent(
                etape,
                "le fichier téléchargé ne correspond pas à l’empreinte annoncée par le serveur");
        }

        var version = telecharge.Version > 0 ? telecharge.Version : etat.Version;
        var fiche = new FicheDocumentTravail
        {
            Document = document,
            Nom = etat.Nom,
            Chemin = chemin,
            Serveur = string.Empty,
            EmpreinteServeur = empreinte,
            VersionServeur = version,
            DerniereEmpreinteConnue = empreinte,
            DerniereVersionConnue = version,
            OuvertLeUtc = _horloge.MaintenantUtc,
        };
        _fiches.Ecrire(fiche);

        await _client.OuvrirAsync(document, annulation).ConfigureAwait(false);
        _ouvreur.Ouvrir(chemin);
        _journal.Info(etape, $"document {document} ouvert dans {chemin} (version {version})");

        return fiche;
    }
}
