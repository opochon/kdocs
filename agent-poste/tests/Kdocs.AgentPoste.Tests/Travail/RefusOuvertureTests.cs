using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Travail;

/// <summary>
/// Oracle du refus d'ouverture hors liste blanche : le document n'est NI téléchargé, NI signalé ouvert,
/// NI passé au shell ; le refus est journalisé et expliqué dans « Mes documents en cours ».
/// </summary>
public sealed class RefusOuvertureTests
{
    public static TheoryData<string> NomsDangereux() => ExtensionsTests.NomsDangereux();

    [Theory]
    [MemberData(nameof(NomsDangereux))]
    public async Task Un_document_hors_liste_blanche_n_est_jamais_passe_au_shell(string nom)
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        client.Etats[7] = new EtatDocument(7, nom, "application/octet-stream", 12, "abc", 1);
        var ouvreur = new FauxOuvreur();
        var fiches = new DepotFichesDisque(racine);
        var refus = new DepotRefusDisque(racine);
        var journal = new JournalMemoire();
        var gestionnaire = new GestionnaireTravail(
            racine, client, fiches, ouvreur, HorlogeSysteme.Instance, journal, refus);

        var erreur = await Assert.ThrowsAsync<Kdocs.AgentPoste.ErreurAgent>(() => gestionnaire.OuvrirAsync(7));

        Assert.Empty(ouvreur.Ouverts);
        Assert.Equal(0, client.CompteurOuverture);
        Assert.False(
            Directory.Exists(racine.DossierTravailDe(7)),
            "rien n’est téléchargé pour un document hors liste blanche");
        Assert.Contains(
            journal.Lignes,
            l => l.Niveau == "AVERTISSEMENT" && l.Message.Contains("refus", StringComparison.OrdinalIgnoreCase));
        Assert.Contains("format", erreur.Cause, StringComparison.OrdinalIgnoreCase);

        var motif = Assert.Single(refus.Lister());
        Assert.Equal(7, motif.Document);
        Assert.Equal(nom, motif.Nom);
        Assert.Contains("format", motif.Cause, StringComparison.OrdinalIgnoreCase);

        var enCours = new ServiceDocumentsEnCours(
            fiches, new DepotFileDisque(racine.DossierFile), racine, refus).Lister();
        var ligne = Assert.Single(enCours);
        Assert.Equal(7, ligne.Document);
        Assert.Equal("refusé", ligne.Etat);
        Assert.Contains(motif.Cause, ligne.Refus ?? string.Empty, StringComparison.Ordinal);
    }

    [Fact]
    public async Task La_derniere_ouverture_d_un_document_hors_liste_blanche_reste_visible()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        client.Etats[9] = new EtatDocument(9, "courrier.docm", "application/octet-stream", 12, "abc", 1);
        var fiches = new DepotFichesDisque(racine);
        var refus = new DepotRefusDisque(racine);
        var horloge = new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 9, 0, 0, TimeSpan.Zero));
        var gestionnaire = new GestionnaireTravail(
            racine, client, fiches, new FauxOuvreur(), horloge, new JournalMemoire(), refus);

        await Assert.ThrowsAsync<Kdocs.AgentPoste.ErreurAgent>(() => gestionnaire.OuvrirAsync(9));
        horloge.Avancer(TimeSpan.FromMinutes(5));
        await Assert.ThrowsAsync<Kdocs.AgentPoste.ErreurAgent>(() => gestionnaire.OuvrirAsync(9));

        var enCours = new ServiceDocumentsEnCours(
            fiches, new DepotFileDisque(racine.DossierFile), racine, refus).Lister();
        Assert.Single(enCours);
        Assert.Equal("refusé", enCours[0].Etat);
    }

    [Fact]
    public async Task Un_document_editable_de_la_liste_blanche_continue_de_s_ouvrir()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        client.Etats[5] = new EtatDocument(5, "rapport.docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document", 4, "abc", 1);
        var ouvreur = new FauxOuvreur();
        var refus = new DepotRefusDisque(racine);
        var gestionnaire = new GestionnaireTravail(
            racine, client, new DepotFichesDisque(racine), ouvreur, HorlogeSysteme.Instance, new JournalMemoire(), refus);

        var fiche = await gestionnaire.OuvrirAsync(5);

        Assert.Equal(racine.CheminTravail(5, "rapport.docx"), fiche.Chemin);
        Assert.Single(ouvreur.Ouverts);
        Assert.Empty(refus.Lister());
    }
}
