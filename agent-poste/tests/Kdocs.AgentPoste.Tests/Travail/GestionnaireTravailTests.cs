using System.Security.Cryptography;
using System.Text;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Travail;

/// <summary>
/// Oracle du dossier de travail : téléchargement, contrôle d'empreinte, fiche écrite à côté du document,
/// ouverture « comme un double-clic » et empreinte de base (le téléchargement lui-même n'est pas pris
/// pour une modification).
/// </summary>
public sealed class GestionnaireTravailTests
{
    private static string Sha(byte[] octets) => Convert.ToHexStringLower(SHA256.HashData(octets));

    [Fact]
    public async Task Telecharge_verifie_l_empreinte_puis_ouvre_le_fichier_local()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        var contenu = Encoding.UTF8.GetBytes("contenu du rapport");
        client.Contenus[42] = contenu;
        client.Etats[42] = new EtatDocument(42, "rapport.docx", "application/vnd.openxmlformats-officedocument.wordprocessingml.document", contenu.Length, Sha(contenu), 3);
        var ouvreur = new FauxOuvreur();
        var fiches = new DepotFichesDisque(racine);
        var gestionnaire = new GestionnaireTravail(
            racine, client, fiches, ouvreur, new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 9, 0, 0, TimeSpan.Zero)), new JournalMemoire());

        var fiche = await gestionnaire.OuvrirAsync(42);

        var chemin = racine.CheminTravail(42, "rapport.docx");
        Assert.Equal(chemin, fiche.Chemin);
        Assert.Equal(Sha(contenu), fiche.EmpreinteServeur);
        Assert.Equal(Sha(contenu), fiche.DerniereEmpreinteConnue);
        Assert.Equal(3, fiche.VersionServeur);
        Assert.Equal(contenu, File.ReadAllBytes(chemin));
        Assert.Equal([chemin], ouvreur.Ouverts);
        Assert.Equal(1, client.CompteurOuverture);
        Assert.Single(fiches.Lister());
    }

    [Fact]
    public async Task Refuse_un_fichier_dont_l_empreinte_ne_correspond_pas()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        var client = new FauxClientEdition();
        client.Contenus[42] = Encoding.UTF8.GetBytes("contenu corrompu");
        client.EmpreinteForcee[42] = new string('0', 64);
        var ouvreur = new FauxOuvreur();
        var gestionnaire = new GestionnaireTravail(
            racine, client, new DepotFichesDisque(racine), ouvreur, HorlogeSysteme.Instance, new JournalMemoire());

        var erreur = await Assert.ThrowsAsync<Kdocs.AgentPoste.ErreurAgent>(() => gestionnaire.OuvrirAsync(42));

        Assert.Contains("empreinte", erreur.Cause, StringComparison.OrdinalIgnoreCase);
        Assert.Empty(ouvreur.Ouverts);
        Assert.Equal(0, client.CompteurOuverture);
    }

    [Fact]
    public void Le_nom_du_document_ne_peut_pas_sortir_du_dossier_de_travail()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);

        var chemin = racine.CheminTravail(42, @"..\..\ailleurs.docx");

        Assert.StartsWith(racine.DossierTravailDe(42), chemin, StringComparison.Ordinal);
    }
}
