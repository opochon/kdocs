using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Application;

/// <summary>
/// Oracle des services de l'icône de barre des tâches : « Mes documents en cours » (état envoyé ou en
/// attente), « Récupérer la copie de secours », et l'entretien du verrou informatif d'ouverture.
/// </summary>
public sealed class ServicesBarreTachesTests
{
    private static FicheDocumentTravail Fiche(Kdocs.AgentPoste.RacineAgent racine, int document, string contenu)
    {
        var chemin = racine.CheminTravail(document, "rapport.docx");
        Directory.CreateDirectory(Path.GetDirectoryName(chemin)!);
        File.WriteAllText(chemin, contenu);

        return new FicheDocumentTravail
        {
            Document = document,
            Nom = "rapport.docx",
            Chemin = chemin,
            EmpreinteServeur = "base",
            DerniereEmpreinteConnue = "base",
            VersionServeur = 1,
            DerniereVersionConnue = 1,
            OuvertLeUtc = DateTimeOffset.UnixEpoch,
        };
    }

    [Fact]
    public void Mes_documents_en_cours_distingue_envoye_et_en_attente()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var fiches = new DepotFichesDisque(racine);
        fiches.Ecrire(Fiche(racine, 7, "contenu"));
        var file = new DepotFileDisque(racine.DossierFile);
        file.Enregistrer(new EntreeFile
        {
            Id = "e1",
            Document = 7,
            CheminTravail = racine.CheminTravail(7, "rapport.docx"),
            CheminInstantane = racine.CheminTravail(7, "rapport.docx"),
            Empreinte = "abc",
            VersionBase = 1,
            CreeLeUtc = DateTimeOffset.UnixEpoch,
        });
        var service = new ServiceDocumentsEnCours(fiches, file, racine);

        var avecAttente = service.Lister().Single();
        Assert.Equal("en attente", avecAttente.Etat);
        Assert.Equal(1, avecAttente.EnAttente);

        file.Supprimer("e1");
        var apres = service.Lister().Single();
        Assert.Equal("envoyé", apres.Etat);
        Assert.Equal(0, apres.EnAttente);
    }

    [Fact]
    public void Recupere_la_derniere_copie_de_secours_et_l_ouvre()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var fiches = new DepotFichesDisque(racine);
        fiches.Ecrire(Fiche(racine, 7, "version courante"));
        var dossierSecours = racine.DossierSecoursDe(7);
        Directory.CreateDirectory(dossierSecours);
        var ancienne = Path.Combine(dossierSecours, "20261011-1400.docx");
        var recente = Path.Combine(dossierSecours, "20261011-1432.docx");
        File.WriteAllText(ancienne, "copie ancienne");
        File.WriteAllText(recente, "copie de 14 h 32");
        File.SetLastWriteTimeUtc(ancienne, new DateTime(2026, 10, 11, 12, 0, 0, DateTimeKind.Utc));
        File.SetLastWriteTimeUtc(recente, new DateTime(2026, 10, 11, 12, 32, 0, DateTimeKind.Utc));
        var heureLocale = new DateTimeOffset(new DateTime(2026, 10, 11, 12, 32, 0, DateTimeKind.Utc)).ToLocalTime();
        var ouvreur = new FauxOuvreur();
        var service = new ServiceDocumentsEnCours(fiches, new DepotFileDisque(racine.DossierFile), racine);
        var recuperation = new ServiceRecuperation(service, fiches, ouvreur, new JournalMemoire());

        var chemin = recuperation.Recuperer(7);

        Assert.Equal(racine.CheminTravail(7, "rapport.docx"), chemin);
        Assert.Equal("copie de 14 h 32", File.ReadAllText(chemin!));
        Assert.Equal([chemin!], ouvreur.Ouverts);
        var fiche = fiches.Lire(7)!;
        Assert.Contains(heureLocale.ToString("HH:mm"), fiche.Message ?? string.Empty, StringComparison.Ordinal);
    }

    [Fact]
    public void Recuperer_sans_copie_ne_fait_rien()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var fiches = new DepotFichesDisque(racine);
        var ouvreur = new FauxOuvreur();
        var service = new ServiceDocumentsEnCours(fiches, new DepotFileDisque(racine.DossierFile), racine);
        var recuperation = new ServiceRecuperation(service, fiches, ouvreur, new JournalMemoire());

        Assert.Null(recuperation.Recuperer(7));
        Assert.Empty(ouvreur.Ouverts);
    }

    [Fact]
    public void La_recuperation_n_ouvre_jamais_un_format_non_editable()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var fiches = new DepotFichesDisque(racine);
        // Fiche héritée d'avant le durcissement : le nom de travail est un exécutable.
        var cheminExe = racine.CheminTravail(7, "facture.pdf.exe");
        Directory.CreateDirectory(Path.GetDirectoryName(cheminExe)!);
        File.WriteAllText(cheminExe, "faux exécutable");
        fiches.Ecrire(new FicheDocumentTravail
        {
            Document = 7,
            Nom = "facture.pdf.exe",
            Chemin = cheminExe,
            EmpreinteServeur = "base",
            DerniereEmpreinteConnue = "base",
            VersionServeur = 1,
            DerniereVersionConnue = 1,
            OuvertLeUtc = DateTimeOffset.UnixEpoch,
        });
        var dossierSecours = racine.DossierSecoursDe(7);
        Directory.CreateDirectory(dossierSecours);
        File.WriteAllText(Path.Combine(dossierSecours, "20261011-1432.docx"), "copie de secours");
        var ouvreur = new FauxOuvreur();
        var service = new ServiceDocumentsEnCours(fiches, new DepotFileDisque(racine.DossierFile), racine);
        var recuperation = new ServiceRecuperation(service, fiches, ouvreur, new JournalMemoire());

        Assert.Null(recuperation.Recuperer(7));
        Assert.Empty(ouvreur.Ouverts);
        Assert.Equal("faux exécutable", File.ReadAllText(cheminExe));
    }

    [Fact]
    public async Task Le_verrou_informatif_s_ouvre_se_prolonge_et_se_leve()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        var service = new ServiceOuvertures(client, racine, new JournalMemoire());
        var document = new DocumentOffice(TypeOffice.Word, racine.CheminTravail(7, "rapport.docx"), false, "rapport.docx");

        await service.EntretenirAsync([document]);
        Assert.Equal(1, client.CompteurOuverture);
        Assert.Equal(0, client.CompteurSignal);
        Assert.Equal(0, client.CompteurFermeture);

        await service.EntretenirAsync([document]);
        Assert.Equal(1, client.CompteurOuverture);
        Assert.Equal(1, client.CompteurSignal);
        Assert.Equal(0, client.CompteurFermeture);

        await service.EntretenirAsync([]);
        Assert.Equal(1, client.CompteurOuverture);
        Assert.Equal(1, client.CompteurSignal);
        Assert.Equal(1, client.CompteurFermeture);
    }
}
