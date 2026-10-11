using System.Diagnostics;
using System.Net.Http.Headers;
using System.Text.Json;
using Kdocs.AgentPoste.Configuration;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.F13;

/// <summary>Le parcours F13 parle au vrai serveur : une seule collection, exécutée seule.</summary>
[CollectionDefinition("F13", DisableParallelization = true)]
public sealed class CollectionF13 : ICollectionFixture<ParcoursF13Fixture>
{
}

/// <summary>
/// Prépare le parcours F13 : serveur PHP de test (base `gedv2_test`, fonds et espace de secours isolés),
/// jeu synthétique (compte, dossiers, documents, appairage, jetons) et racines d'agent configurées.
///
/// Aucune donnée réelle : tout vit sous `var/oracles/agent-poste/` et en base `gedv2_test`.
/// </summary>
public sealed class ParcoursF13Fixture : IDisposable
{
    public const int Port = 8873;

    private readonly Process _serveur;

    public ParcoursF13Fixture()
    {
        RacineDepot = TrouverRacineDepot();
        Serveur = $"http://127.0.0.1:{Port}";
        var fonds = Path.Combine(RacineDepot, "var", "oracles", "agent-poste", "documents");
        var secours = Path.Combine(RacineDepot, "var", "oracles", "agent-poste", "secours");

        _serveur = DemarrerServeur(fonds, secours);
        AttendreSante();
        LirePreparation(ExecuterPreparation(fonds, secours));

        RacineEnrolement = new DossierTemporaire();
        new RacineAgent(RacineEnrolement.Chemin).Preparer();
    }

    public string RacineDepot { get; }

    public string Serveur { get; }

    public string Manager { get; private set; } = string.Empty;

    public int Docx { get; private set; }

    public int DocxLigne { get; private set; }

    public int DocxConflit { get; private set; }

    public int DocxRevoque { get; private set; }

    public int Xlsx { get; private set; }

    public int Secret { get; private set; }

    public string JetonPrincipal { get; private set; } = string.Empty;

    public string JetonRevocable { get; private set; } = string.Empty;

    public string CodeAppairage { get; private set; } = string.Empty;

    public string VerificationAppairage { get; private set; } = string.Empty;

    public string Poste { get; private set; } = string.Empty;

    public DossierTemporaire RacineEnrolement { get; }

    public JournalMemoire Journal { get; } = new();

    public HttpClient Http { get; } = new() { Timeout = TimeSpan.FromSeconds(60) };

    /// <summary>
    /// Une racine d'agent NEUVE, configurée avec ce jeton : chaque test travaille sur sa propre copie du
    /// document, donc deux sessions Word ne se disputent jamais le même fichier.
    /// </summary>
    public DossierTemporaire CreerRacineAgent(string jeton)
    {
        var dossier = new DossierTemporaire();
        var racine = new RacineAgent(dossier.Chemin);
        racine.Preparer();
        new DepotConfigurationFichier(racine.FichierConfiguration).Ecrire(new ConfigurationPoste
        {
            AdresseServeur = Serveur,
            Appareil = Poste,
            Compte = Manager,
            GedNom = "GEDv2",
            EnroleLeUtc = DateTimeOffset.UtcNow,
        });
        new CoffreJetonDpapi(racine.FichierJeton).Ecrire(jeton);

        return dossier;
    }

    public ClientEditionHttp ClientPrincipal() => new(Serveur, JetonPrincipal, Journal, Http);

    public ClientEditionHttp ClientRevocable() => new(Serveur, JetonRevocable, Journal, Http);

    /// <summary>Appel authentifié par jeton d'appareil qui nomme le refus HTTP.</summary>
    public async Task<(int Statut, JsonElement? Corps)> AppelerAsync(
        string jeton,
        HttpMethod methode,
        string chemin,
        HttpContent? contenu = null)
    {
        using var requete = new HttpRequestMessage(methode, Serveur + chemin);
        requete.Headers.Authorization = new AuthenticationHeaderValue("Bearer", jeton);
        if (contenu is not null)
        {
            requete.Content = contenu;
        }

        using var reponse = await Http.SendAsync(requete);
        var corps = await reponse.Content.ReadAsStringAsync();
        if (!reponse.IsSuccessStatusCode)
        {
            return ((int)reponse.StatusCode, null);
        }

        using var document = JsonDocument.Parse(corps);

        return ((int)reponse.StatusCode, document.RootElement.Clone());
    }

    public async Task<JsonElement> EtatAsync(string jeton, int document)
    {
        var (statut, corps) = await AppelerAsync(jeton, HttpMethod.Get, $"/api/v1/edition/documents/{document}");
        Assert.Equal(200, statut);

        return corps!.Value;
    }

    public void Dispose()
    {
        try
        {
            if (!_serveur.HasExited)
            {
                _serveur.Kill(entireProcessTree: true);
                _serveur.WaitForExit(10_000);
            }
        }
        catch (InvalidOperationException)
        {
        }

        _serveur.Dispose();
        Http.Dispose();
        RacineEnrolement.Dispose();
    }

    private static string TrouverRacineDepot()
    {
        var repertoire = new DirectoryInfo(AppContext.BaseDirectory);
        while (repertoire is not null)
        {
            if (File.Exists(Path.Combine(repertoire.FullName, "AGENTS.md"))
                && Directory.Exists(Path.Combine(repertoire.FullName, "src")))
            {
                return repertoire.FullName;
            }

            repertoire = repertoire.Parent;
        }

        throw new InvalidOperationException("racine du dépôt introuvable depuis " + AppContext.BaseDirectory);
    }

    private Process DemarrerServeur(string fonds, string secours)
    {
        var info = new ProcessStartInfo("php")
        {
            WorkingDirectory = RacineDepot,
            UseShellExecute = false,
        };
        foreach (var argument in new[] { "-d", "opcache.enable=0", "-S", $"127.0.0.1:{Port}", "-t", "public", "public/index.php" })
        {
            info.ArgumentList.Add(argument);
        }
        info.Environment["DB_NAME"] = "gedv2_test";
        info.Environment["APP_ENV"] = "test";
        info.Environment["STORAGE_ROOT"] = fonds;
        info.Environment["SECOURS_ROOT"] = secours;

        return Process.Start(info)
            ?? throw new InvalidOperationException("le serveur PHP de test n’a pas pu démarrer");
    }

    private void AttendreSante()
    {
        var fin = DateTime.UtcNow + TimeSpan.FromSeconds(30);
        while (DateTime.UtcNow < fin)
        {
            try
            {
                using var reponse = Http.GetAsync(Serveur + "/sante").GetAwaiter().GetResult();
                if (reponse.IsSuccessStatusCode)
                {
                    return;
                }
            }
            catch (HttpRequestException)
            {
            }
            catch (TaskCanceledException)
            {
            }

            Thread.Sleep(300);
        }

        throw new InvalidOperationException($"le serveur PHP de test n’est pas prêt sur {Serveur}");
    }

    private string ExecuterPreparation(string fonds, string secours)
    {
        var info = new ProcessStartInfo("php")
        {
            WorkingDirectory = RacineDepot,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        info.ArgumentList.Add("agent-poste/tests/parcours/preparer-f13.php");
        info.Environment["DB_NAME"] = "gedv2_test";
        info.Environment["APP_ENV"] = "test";
        info.Environment["STORAGE_ROOT"] = fonds;
        info.Environment["SECOURS_ROOT"] = secours;

        using var processus = Process.Start(info)
            ?? throw new InvalidOperationException("la préparation F13 n’a pas pu démarrer");
        var sortie = processus.StandardOutput.ReadToEnd();
        var erreur = processus.StandardError.ReadToEnd();
        processus.WaitForExit(120_000);
        if (processus.ExitCode != 0)
        {
            throw new InvalidOperationException($"préparation F13 en échec ({processus.ExitCode}) : {erreur}");
        }

        return sortie;
    }

    private void LirePreparation(string json)
    {
        using var document = JsonDocument.Parse(json);
        var racine = document.RootElement;
        Docx = racine.GetProperty("docx").GetInt32();
        Xlsx = racine.GetProperty("xlsx").GetInt32();
        Secret = racine.GetProperty("secret").GetInt32();
        DocxLigne = racine.GetProperty("docx_ligne").GetInt32();
        DocxConflit = racine.GetProperty("docx_conflit").GetInt32();
        DocxRevoque = racine.GetProperty("docx_revoque").GetInt32();
        Manager = racine.GetProperty("manager").GetString()!;
        JetonPrincipal = racine.GetProperty("jeton_principal").GetString()!;
        JetonRevocable = racine.GetProperty("jeton_revocable").GetString()!;
        CodeAppairage = racine.GetProperty("code").GetString()!;
        VerificationAppairage = racine.GetProperty("verification").GetString()!;
        Poste = racine.GetProperty("poste").GetString()!;
    }
}
