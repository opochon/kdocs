using System.Text.Json;
using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Configuration;
using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        var options = OptionsAgent.Analyser(args);
        var racine = new RacineAgent();
        racine.Preparer();
        var journal = new JournalFichier(racine.DossierJournal);

        if (options.EstEnrolement)
        {
            return ExecuterEnrolementAsync(options, racine, journal).GetAwaiter().GetResult();
        }

        var url = options.Url;
        if (!string.IsNullOrEmpty(url) && CanalIpc.EnvoyerAsync(url).GetAwaiter().GetResult())
        {
            // Une instance écoute déjà : elle prend le lien, on se retire sans rien exécuter deux fois.
            return 0;
        }

        System.Windows.Forms.Application.EnableVisualStyles();
        System.Windows.Forms.Application.SetCompatibleTextRenderingDefault(false);
        using var application = new AgentPosteApplication(
            racine, journal, url, Environment.ProcessPath ?? "Kdocs.AgentPoste.exe");
        application.Demarrer();
        System.Windows.Forms.Application.Run(application);

        return 0;
    }

    /// <summary>Enrôlement en ligne de commande (installateur, script) : échange l'appairage et chiffre le jeton.</summary>
    private static async Task<int> ExecuterEnrolementAsync(OptionsAgent options, RacineAgent racine, IJournal journal)
    {
        using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(60) };
        var enroleur = new EnroleurHttp(
            http,
            new DepotConfigurationFichier(racine.FichierConfiguration),
            new CoffreJetonDpapi(racine.FichierJeton),
            HorlogeSysteme.Instance,
            journal);

        try
        {
            var configuration = await enroleur.EnrollerAsync(
                options.ServeurEnrolement!,
                options.CodeEnrolement ?? string.Empty,
                options.VerificationEnrolement ?? string.Empty,
                options.PosteEnrolement ?? Environment.MachineName);

            EcrireResultat(options.FichierSortie, new
            {
                ok = true,
                compte = configuration.Compte,
                ged_nom = configuration.GedNom,
                serveur = configuration.AdresseServeur,
                appareil = configuration.Appareil,
            });

            return 0;
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            journal.Erreur("enrôlement", "échec de l’enrôlement", exception);
            EcrireResultat(options.FichierSortie, new { ok = false, erreur = exception.Message });

            return 1;
        }
    }

    private static void EcrireResultat(string? chemin, object resultat)
    {
        if (string.IsNullOrEmpty(chemin))
        {
            return;
        }

        try
        {
            File.WriteAllText(chemin, JsonSerializer.Serialize(resultat, new JsonSerializerOptions { WriteIndented = true }));
        }
        catch (IOException exception)
        {
            System.Diagnostics.Debug.WriteLine($"[K-Docs] résultat d’enrôlement non écrit : {exception.Message}");
        }
    }
}
