using System.Security.Cryptography;
using System.Text;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.Envoi;

/// <summary>
/// Oracle de la file d'envoi persistante : mise en file après stabilité, instantané figé, livraison dans
/// l'ordre, reprise après redémarrage, conflit signalé sans écrasement, effacement local seulement après
/// confirmation du serveur. Aucun réseau, aucune horloge réelle.
/// </summary>
public sealed class GestionnaireFileEnvoiTests
{
    private static string Sha(string texte) => Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(texte)));

    private sealed class Banc : IDisposable
    {
        public Banc()
        {
            Dossier = new DossierTemporaire();
            Racine = new Kdocs.AgentPoste.RacineAgent(Dossier.Chemin);
            Racine.Preparer();
            Depot = new DepotFileDisque(Racine.DossierFile);
            Client = new FauxClientEdition();
            Stable = new FauxFichierStable();
            Horloge = new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 8, 0, 0, TimeSpan.Zero));
            Journal = new JournalMemoire();
            Gestionnaire = new GestionnaireFileEnvoi(Client, Depot, Racine, Stable, Horloge, Journal);
        }

        public DossierTemporaire Dossier { get; }

        public Kdocs.AgentPoste.RacineAgent Racine { get; }

        public DepotFileDisque Depot { get; }

        public FauxClientEdition Client { get; }

        public FauxFichierStable Stable { get; }

        public HorlogeFigee Horloge { get; }

        public JournalMemoire Journal { get; }

        public GestionnaireFileEnvoi Gestionnaire { get; }

        public string EcrireTravail(string contenu)
        {
            var chemin = Racine.CheminTravail(7, "rapport.docx");
            Directory.CreateDirectory(Path.GetDirectoryName(chemin)!);
            File.WriteAllText(chemin, contenu);

            return chemin;
        }

        public void Dispose() => Dossier.Dispose();
    }

    [Fact]
    public async Task Fige_un_instantane_stable_et_le_persiste()
    {
        using var banc = new Banc();
        var chemin = banc.EcrireTravail("version A");

        var entree = await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        Assert.NotNull(entree);
        Assert.Equal(Sha("version A"), entree!.Empreinte);
        Assert.True(File.Exists(entree.CheminInstantane));
        Assert.Equal("version A", File.ReadAllText(entree.CheminInstantane));
        Assert.Single(banc.Depot.Charger());
    }

    [Fact]
    public async Task Ne_met_pas_en_file_un_fichier_jamais_stable()
    {
        using var banc = new Banc();
        banc.Stable.Stable = false;
        var chemin = banc.EcrireTravail("en cours d’écriture");

        var entree = await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        Assert.Null(entree);
        Assert.Empty(banc.Depot.Charger());
        Assert.Contains(banc.Journal.Lignes, l => l.Message.Contains("jamais stable", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Livre_dans_l_ordre_et_religue_la_version_de_base()
    {
        using var banc = new Banc();
        var chemin = banc.EcrireTravail("version A");
        await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);
        banc.Horloge.Avancer(TimeSpan.FromSeconds(3));
        File.WriteAllText(chemin, "version B");
        await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        var resultats = await banc.Gestionnaire.TraiterAsync();

        Assert.Equal(2, resultats.Count);
        Assert.All(resultats, r => Assert.True(r.Livre));
        Assert.Equal(2, banc.Client.Depots.Count);
        Assert.Equal(1, banc.Client.Depots[0].VersionBase);
        Assert.Equal(2, banc.Client.Depots[1].VersionBase);
        Assert.Equal(Sha("version A"), banc.Client.Depots[0].Empreinte);
        Assert.Equal(Sha("version B"), banc.Client.Depots[1].Empreinte);
        Assert.Empty(banc.Depot.Charger());
    }

    [Fact]
    public async Task Reprend_apres_redemarrage_de_l_agent()
    {
        using var banc = new Banc();
        var chemin = banc.EcrireTravail("version A");
        await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        // Nouvelle instance : c'est le redémarrage de l'agent, la file est relue du disque.
        var redemarre = new GestionnaireFileEnvoi(
            banc.Client, new DepotFileDisque(banc.Racine.DossierFile), banc.Racine, banc.Stable, banc.Horloge, banc.Journal);
        var resultats = await redemarre.TraiterAsync();

        Assert.Single(resultats);
        Assert.True(resultats[0].Livre);
        Assert.Single(banc.Client.Depots);
        Assert.Empty(banc.Depot.Charger());
    }

    [Fact]
    public async Task Efface_l_instantane_seulement_apres_confirmation_du_serveur()
    {
        using var banc = new Banc();
        banc.Client.ErreurDepot = new HttpRequestException("réseau coupé");
        var chemin = banc.EcrireTravail("version A");
        var entree = await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        await banc.Gestionnaire.TraiterAsync();
        Assert.True(File.Exists(entree!.CheminInstantane), "l’instantané doit survivre à une panne réseau");
        Assert.Single(banc.Depot.Charger());

        banc.Client.ErreurDepot = null;
        banc.Horloge.Avancer(TimeSpan.FromMinutes(1));
        var resultats = await banc.Gestionnaire.TraiterAsync();

        Assert.Single(resultats);
        Assert.True(resultats[0].Livre);
        Assert.False(File.Exists(entree.CheminInstantane), "l’instantané n’est effacé qu’après confirmation");
        Assert.Empty(banc.Depot.Charger());
    }

    [Fact]
    public async Task Reessaie_avec_un_delai_apres_une_panne_reseau()
    {
        using var banc = new Banc();
        banc.Client.ErreurDepot = new HttpRequestException("réseau coupé");
        var chemin = banc.EcrireTravail("version A");
        await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        await banc.Gestionnaire.TraiterAsync();
        var apresPanne = banc.Depot.Charger().Single();
        Assert.Equal(EtatEntree.Echec, apresPanne.Etat);
        Assert.Equal(1, apresPanne.Tentatives);
        Assert.True(apresPanne.ProchaineTentativeUtc > banc.Horloge.MaintenantUtc);

        // Avant le délai : aucun nouvel essai.
        await banc.Gestionnaire.TraiterAsync();
        Assert.Single(banc.Client.Depots);

        banc.Client.ErreurDepot = null;
        banc.Horloge.Avancer(TimeSpan.FromMinutes(5));
        var resultats = await banc.Gestionnaire.TraiterAsync();

        Assert.Single(resultats);
        Assert.True(resultats[0].Livre);
        Assert.Empty(banc.Depot.Charger());
    }

    [Fact]
    public async Task Ne_renvoie_pas_apres_un_refus_definitif_et_garde_la_copie()
    {
        using var banc = new Banc();
        banc.Client.ErreurDepot = new Kdocs.AgentPoste.ErreurServeur(401, "POST versions", "jeton révoqué");
        var chemin = banc.EcrireTravail("version A");
        await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        var premiere = await banc.Gestionnaire.TraiterAsync();
        Assert.False(premiere[0].Livre);
        var conservee = banc.Depot.Charger().Single();
        Assert.True(conservee.RefusDefinitif);
        Assert.True(File.Exists(conservee.CheminInstantane), "appareil révoqué : la copie locale reste");

        banc.Client.ErreurDepot = null;
        banc.Horloge.Avancer(TimeSpan.FromHours(2));
        await banc.Gestionnaire.TraiterAsync();

        Assert.Single(banc.Client.Depots);
        Assert.Single(banc.Depot.Charger());
    }

    [Fact]
    public async Task Signale_un_conflit_sans_rien_ecraser()
    {
        using var banc = new Banc();
        banc.Client.ReponseDepot =
            (document, chemin, empreinte, versionBase) => new(versionBase + 1, empreinte, Conflit: true, versionBase, versionBase + 4, false);
        var chemin = banc.EcrireTravail("version locale");
        var entree = await banc.Gestionnaire.MettreEnFileAsync(7, chemin, versionBase: 1);

        var resultats = await banc.Gestionnaire.TraiterAsync();

        Assert.True(resultats[0].Livre);
        Assert.True(resultats[0].Conflit);
        Assert.Contains("version parallèle", resultats[0].Message ?? string.Empty, StringComparison.Ordinal);
        Assert.False(File.Exists(entree!.CheminInstantane));
        Assert.Empty(banc.Depot.Charger());
    }
}
