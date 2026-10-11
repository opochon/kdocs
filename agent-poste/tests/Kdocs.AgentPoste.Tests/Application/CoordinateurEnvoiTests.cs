using System.Security.Cryptography;
using System.Text;
using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Application;

/// <summary>
/// Oracle de la coordination : un Ctrl+S (contenu différent de la dernière empreinte connue) devient une
/// version ; un simple téléchargement (contenu identique) n'en crée pas ; un conflit est noté dans la
/// fiche sans écraser.
/// </summary>
public sealed class CoordinateurEnvoiTests
{
    private static string Sha(string texte) => Convert.ToHexStringLower(SHA256.HashData(Encoding.UTF8.GetBytes(texte)));

    private sealed class Banc : IDisposable
    {
        public Banc()
        {
            Dossier = new DossierTemporaire();
            Racine = new Kdocs.AgentPoste.RacineAgent(Dossier.Chemin);
            Racine.Preparer();
            Fiches = new DepotFichesDisque(Racine);
            Client = new FauxClientEdition();
            Horloge = new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 10, 0, 0, TimeSpan.Zero));
            Journal = new JournalMemoire();
            FileEnvoi = new GestionnaireFileEnvoi(
                Client, new DepotFileDisque(Racine.DossierFile), Racine, new FauxFichierStable(), Horloge, Journal);
            Coordinateur = new CoordinateurEnvoi(Fiches, FileEnvoi, EmpreinteSha256.Instance, Horloge, Journal);
        }

        public DossierTemporaire Dossier { get; }

        public Kdocs.AgentPoste.RacineAgent Racine { get; }

        public DepotFichesDisque Fiches { get; }

        public FauxClientEdition Client { get; }

        public HorlogeFigee Horloge { get; }

        public JournalMemoire Journal { get; }

        public GestionnaireFileEnvoi FileEnvoi { get; }

        public CoordinateurEnvoi Coordinateur { get; }

        public string Chemin => Racine.CheminTravail(7, "rapport.docx");

        public void OuvrirFiche(string contenu)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(Chemin)!);
            File.WriteAllText(Chemin, contenu);
            Fiches.Ecrire(new FicheDocumentTravail
            {
                Document = 7,
                Nom = "rapport.docx",
                Chemin = Chemin,
                EmpreinteServeur = Sha(contenu),
                DerniereEmpreinteConnue = Sha(contenu),
                VersionServeur = 1,
                DerniereVersionConnue = 1,
                OuvertLeUtc = Horloge.MaintenantUtc,
            });
        }

        public void Dispose() => Dossier.Dispose();
    }

    [Fact]
    public async Task Un_simple_telechargement_ne_cree_pas_de_version()
    {
        using var banc = new Banc();
        banc.OuvrirFiche("contenu initial");

        var resultats = await banc.Coordinateur.TraiterFichierAsync(banc.Chemin);

        Assert.Empty(resultats);
        Assert.Empty(banc.Client.Depots);
    }

    [Fact]
    public async Task Un_ctrl_s_devient_une_version_et_met_a_jour_la_fiche()
    {
        using var banc = new Banc();
        banc.OuvrirFiche("contenu initial");
        File.WriteAllText(banc.Chemin, "contenu modifié");

        var resultats = await banc.Coordinateur.TraiterFichierAsync(banc.Chemin);

        Assert.Single(resultats);
        Assert.True(resultats[0].Livre);
        Assert.Single(banc.Client.Depots);
        var fiche = banc.Fiches.Lire(7)!;
        Assert.Equal(Sha("contenu modifié"), fiche.DerniereEmpreinteConnue);
        Assert.Equal(2, fiche.DerniereVersionConnue);
        Assert.False(fiche.Conflit);
        Assert.NotNull(fiche.DernierEnvoiUtc);
        Assert.Empty(new DepotFileDisque(banc.Racine.DossierFile).Charger());
    }

    [Fact]
    public async Task Un_conflit_est_note_sans_ecraser()
    {
        using var banc = new Banc();
        banc.OuvrirFiche("contenu initial");
        banc.Client.ReponseDepot =
            (document, chemin, empreinte, versionBase) => new(versionBase + 1, empreinte, true, versionBase, versionBase + 3, false);
        File.WriteAllText(banc.Chemin, "modification pendant que d’autres modifient");

        var resultats = await banc.Coordinateur.TraiterFichierAsync(banc.Chemin);

        Assert.Single(resultats);
        Assert.True(resultats[0].Conflit);
        var fiche = banc.Fiches.Lire(7)!;
        Assert.True(fiche.Conflit);
        Assert.NotNull(fiche.DernierConflitLeUtc);
        Assert.Contains("version parallèle", fiche.Message ?? string.Empty, StringComparison.Ordinal);
    }
}
