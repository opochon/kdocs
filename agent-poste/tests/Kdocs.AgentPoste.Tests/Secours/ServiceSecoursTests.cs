using System.Text;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.Secours;

/// <summary>
/// Oracle des copies de secours : seuls les documents du dossier de travail MODIFIÉS sans enregistrement
/// sont copiés ; la copie locale est écrite hors du document utilisateur et la dernière part au serveur ;
/// une erreur d'envoi garde la copie locale ; rien n'est supprimé.
/// </summary>
public sealed class ServiceSecoursTests
{
    private sealed class Banc : IDisposable
    {
        public Banc()
        {
            Dossier = new DossierTemporaire();
            Racine = new Kdocs.AgentPoste.RacineAgent(Dossier.Chemin);
            Racine.Preparer();
            Office = new FauxOfficeAutomate();
            Client = new FauxClientEdition();
            Horloge = new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 14, 32, 5, TimeSpan.Zero));
            Journal = new JournalMemoire();
            Service = new ServiceSecours(Office, Client, Racine, Horloge, Journal);
        }

        public DossierTemporaire Dossier { get; }

        public Kdocs.AgentPoste.RacineAgent Racine { get; }

        public FauxOfficeAutomate Office { get; }

        public FauxClientEdition Client { get; }

        public HorlogeFigee Horloge { get; }

        public JournalMemoire Journal { get; }

        public ServiceSecours Service { get; }

        public string CheminTravail(int document) => Racine.CheminTravail(document, "rapport.docx");

        public void Dispose() => Dossier.Dispose();
    }

    [Fact]
    public async Task Copie_un_document_non_enregistre_du_dossier_de_travail_et_l_envoie()
    {
        using var banc = new Banc();
        var chemin = banc.CheminTravail(5);
        banc.Office.Documents.Add(new DocumentOffice(TypeOffice.Word, chemin, Enregistre: false, "rapport.docx"));

        var copies = await banc.Service.ExecuterPasseAsync();

        var copie = Assert.Single(copies);
        Assert.Equal(5, copie.Document);
        Assert.True(copie.Envoyee);
        Assert.True(File.Exists(copie.CheminLocal));
        Assert.EndsWith(".docx", copie.CheminLocal, StringComparison.OrdinalIgnoreCase);
        Assert.StartsWith(banc.Racine.DossierSecoursDe(5), copie.CheminLocal, StringComparison.Ordinal);
        Assert.Equal((5, copie.CheminLocal), Assert.Single(banc.Client.Secours));
    }

    [Fact]
    public async Task Ignore_un_document_enregistre_ou_hors_du_dossier_de_travail()
    {
        using var banc = new Banc();
        banc.Office.Documents.Add(new DocumentOffice(TypeOffice.Word, banc.CheminTravail(5), Enregistre: true, "enregistre.docx"));
        banc.Office.Documents.Add(new DocumentOffice(TypeOffice.Excel, @"C:\ailleurs\classeur.xlsx", Enregistre: false, "classeur.xlsx"));

        var copies = await banc.Service.ExecuterPasseAsync();

        Assert.Empty(copies);
        Assert.Empty(banc.Office.Copies);
        Assert.Empty(banc.Client.Secours);
    }

    [Fact]
    public async Task Garde_la_copie_locale_si_le_serveur_refuse()
    {
        using var banc = new Banc();
        banc.Office.Documents.Add(new DocumentOffice(TypeOffice.PowerPoint, banc.CheminTravail(9), Enregistre: false, "presentation.pptx"));
        // Le dépôt de secours est intercepté par un faux client qui échoue.
        var clientDefaillant = new ClientSecoursDefaillant();
        var service = new ServiceSecours(banc.Office, clientDefaillant, banc.Racine, banc.Horloge, banc.Journal);

        var copies = await service.ExecuterPasseAsync();

        var copie = Assert.Single(copies);
        Assert.False(copie.Envoyee);
        Assert.NotNull(copie.Message);
        Assert.True(File.Exists(copie.CheminLocal), "la copie locale reste même si le serveur refuse");
    }

    [Fact]
    public async Task Ne_remplace_jamais_le_document_de_l_utilisateur()
    {
        using var banc = new Banc();
        var chemin = banc.CheminTravail(5);
        Directory.CreateDirectory(Path.GetDirectoryName(chemin)!);
        File.WriteAllText(chemin, "document de l’utilisateur intact");
        var avant = File.GetLastWriteTimeUtc(chemin);
        banc.Office.Documents.Add(new DocumentOffice(TypeOffice.Word, chemin, Enregistre: false, "rapport.docx"));

        await banc.Service.ExecuterPasseAsync();

        Assert.Equal("document de l’utilisateur intact", File.ReadAllText(chemin));
        Assert.Equal(avant, File.GetLastWriteTimeUtc(chemin));
    }

    private sealed class ClientSecoursDefaillant : Kdocs.AgentPoste.Serveur.IClientEdition
    {
        public Task<Kdocs.AgentPoste.Serveur.EtatDocument> EtatAsync(int document, CancellationToken annulation = default) =>
            throw new NotSupportedException();

        public Task<Kdocs.AgentPoste.Serveur.FichierTelecharge> TelechargerAsync(int document, string cheminDestination, CancellationToken annulation = default) =>
            throw new NotSupportedException();

        public Task<Kdocs.AgentPoste.Serveur.ResultatDepot> DeposerVersionAsync(int document, string cheminFichier, string empreinte, int versionBase, CancellationToken annulation = default) =>
            throw new NotSupportedException();

        public Task OuvrirAsync(int document, CancellationToken annulation = default) => Task.CompletedTask;

        public Task<bool> SignalerAsync(int document, CancellationToken annulation = default) => Task.FromResult(false);

        public Task FermerAsync(int document, CancellationToken annulation = default) => Task.CompletedTask;

        public Task<Kdocs.AgentPoste.Serveur.MetaSecours> DeposerSecoursAsync(int document, string cheminFichier, CancellationToken annulation = default) =>
            throw new Kdocs.AgentPoste.ErreurServeur(500, "POST secours", "serveur indisponible");

        public Task<IReadOnlyList<Kdocs.AgentPoste.Serveur.MetaSecours>> ListerSecoursAsync(int document, CancellationToken annulation = default) =>
            Task.FromResult<IReadOnlyList<Kdocs.AgentPoste.Serveur.MetaSecours>>([]);
    }
}
