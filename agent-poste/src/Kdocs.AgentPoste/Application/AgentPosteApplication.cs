using System.Windows.Forms;
using Kdocs.AgentPoste.Configuration;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Protocole;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Surveillance;
using Kdocs.AgentPoste.Travail;
using Kdocs.AgentPoste.Tray;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// L'agent en session : enregistre le protocole, surveille le dossier de travail, entretient la file
/// d'envoi et les copies de secours, signale les ouvertures, et tient l'icône de la barre des tâches.
/// Tous les traitements lourds sont des passes courtes relancées par minuterie (hébergement mutualisé :
/// aucun démon, aucun appel long).
/// </summary>
public sealed class AgentPosteApplication : ApplicationContext
{
    private readonly RacineAgent _racine;
    private readonly IJournal _journal;
    private readonly ClientEditionHttp? _client;
    private readonly GestionnaireUrl? _gestionnaireUrl;
    private readonly CoordinateurEnvoi? _coordinateur;
    private readonly ServiceSecours? _secours;
    private readonly ServiceOuvertures? _ouvertures;
    private readonly IOfficeAutomate _office;
    private readonly SurveillanceDossier _surveillance;
    private readonly ServeurIpc _ipc;
    private readonly IconeBarreTaches _icone;
    private readonly ServiceDocumentsEnCours _documents;
    private readonly ServiceRecuperation _recuperation;
    private readonly System.Threading.Timer _minuteurFile;
    private readonly System.Threading.Timer _minuteurSecours;
    private readonly SemaphoreSlim _verrouFile = new(1, 1);
    private readonly SemaphoreSlim _verrouSecours = new(1, 1);
    private readonly string? _urlInitial;

    public AgentPosteApplication(RacineAgent racine, IJournal journal, string? urlInitial, string cheminExecutable)
    {
        _racine = racine;
        _journal = journal;
        _urlInitial = urlInitial;

        var configuration = new DepotConfigurationFichier(racine.FichierConfiguration).Lire();
        var jeton = new CoffreJetonDpapi(racine.FichierJeton).Lire();
        if (configuration is not null && !string.IsNullOrEmpty(jeton))
        {
            _client = new ClientEditionHttp(configuration.AdresseServeur, jeton, journal);
        }
        else
        {
            journal.Avertissement("démarrage", "poste non enrôlé : ouvrir les liens et la file restent indisponibles");
        }

        var fiches = new DepotFichesDisque(racine);
        var depotFile = new DepotFileDisque(racine.DossierFile);
        _documents = new ServiceDocumentsEnCours(fiches, depotFile, racine);
        _recuperation = new ServiceRecuperation(_documents, fiches, new OuvreurShell(), journal);
        _office = new OfficeComAutomate(journal);

        if (_client is not null)
        {
            var travail = new GestionnaireTravail(racine, _client, fiches, new OuvreurShell(), HorlogeSysteme.Instance, journal);
            _gestionnaireUrl = new GestionnaireUrl(travail, journal);
            var file = new GestionnaireFileEnvoi(
                _client, depotFile, racine, new FichierStableDisque(), HorlogeSysteme.Instance, journal);
            _coordinateur = new CoordinateurEnvoi(fiches, file, EmpreinteSha256.Instance, HorlogeSysteme.Instance, journal);
            _secours = new ServiceSecours(_office, _client, racine, HorlogeSysteme.Instance, journal);
            _ouvertures = new ServiceOuvertures(_client, racine, journal);
        }

        _surveillance = new SurveillanceDossier(racine, journal);
        _ipc = new ServeurIpc(TraiterUrlAsync, journal);
        _icone = new IconeBarreTaches(_documents, _recuperation, racine.DossierTravail, Quitter);
        _minuteurFile = new System.Threading.Timer(_ => LancerPasseFile(), null, Timeout.Infinite, Timeout.Infinite);
        _minuteurSecours = new System.Threading.Timer(_ => LancerPasseSecours(), null, Timeout.Infinite, Timeout.Infinite);

        EnregistrerProtocole(cheminExecutable);
    }

    public void Demarrer()
    {
        _surveillance.FichierModifie += SurFichierModifie;
        _surveillance.Demarrer();
        _ipc.Demarrer();
        _minuteurFile.Change(TimeSpan.FromSeconds(10), TimeSpan.FromSeconds(20));
        _minuteurSecours.Change(TimeSpan.FromSeconds(5), ServiceSecours.Intervalle);
        if (!string.IsNullOrEmpty(_urlInitial))
        {
            _ = TraiterUrlAsync(_urlInitial);
        }

        _journal.Info("démarrage", "agent K-Docs prêt");
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _minuteurFile.Dispose();
            _minuteurSecours.Dispose();
            _surveillance.Dispose();
            _ipc.Dispose();
            _icone.Dispose();
            _client?.Dispose();
        }

        base.Dispose(disposing);
    }

    private void EnregistrerProtocole(string cheminExecutable)
    {
        try
        {
            new ProtocoleKdocs(new RegistreHkcu()).Enregistrer(cheminExecutable);
            _journal.Info("protocole", "kdocs:// enregistré sous HKCU\\Software\\Classes\\kdocs");
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("protocole", "enregistrement du lien kdocs:// impossible", exception);
        }
    }

    private void SurFichierModifie(string chemin) => _ = TraiterFichierAsync(chemin);

    private async Task TraiterFichierAsync(string chemin)
    {
        if (_coordinateur is null)
        {
            return;
        }

        try
        {
            await _coordinateur.TraiterFichierAsync(chemin).ConfigureAwait(false);
            _icone.Rafraichir();
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("surveillance", $"traitement de {chemin} impossible", exception);
        }
    }

    private async Task TraiterUrlAsync(string url)
    {
        if (_gestionnaireUrl is null)
        {
            _journal.Avertissement("lien kdocs", $"lien ignoré (poste non enrôlé) : {GestionnaireUrl.Assainir(url)}");

            return;
        }

        await _gestionnaireUrl.TraiterAsync(url).ConfigureAwait(false);
    }

    private void LancerPasseFile() => _ = PasserFileAsync();

    private async Task PasserFileAsync()
    {
        if (_coordinateur is null || !_verrouFile.Wait(0))
        {
            return;
        }

        try
        {
            await _coordinateur.TraiterFileAsync().ConfigureAwait(false);
            _icone.Rafraichir();
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("file d’envoi", "passe impossible", exception);
        }
        finally
        {
            _verrouFile.Release();
        }
    }

    private void LancerPasseSecours() => _ = PasserSecoursAsync();

    private async Task PasserSecoursAsync()
    {
        if (_secours is null || _ouvertures is null || !_verrouSecours.Wait(0))
        {
            return;
        }

        try
        {
            var copies = await _secours.ExecuterPasseAsync().ConfigureAwait(false);
            var documents = _office.Lister();
            await _ouvertures.EntretenirAsync(documents).ConfigureAwait(false);
            if (copies.Count > 0)
            {
                _icone.Rafraichir();
            }
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _journal.Erreur("copie de secours", "passe impossible", exception);
        }
        finally
        {
            _verrouSecours.Release();
        }
    }

    private void Quitter()
    {
        _journal.Info("arrêt", "agent K-Docs arrêté par l’utilisateur");
        _icone.Dispose();
        Dispose();
        ExitThread();
    }
}
