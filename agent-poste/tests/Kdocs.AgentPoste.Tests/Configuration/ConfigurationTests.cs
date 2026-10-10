using System.Net;
using System.Text;
using Kdocs.AgentPoste.Configuration;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Tests.Support;

namespace Kdocs.AgentPoste.Tests.Configuration;

public sealed class AdresseServeurTests
{
    [Theory]
    [InlineData("https://ged.exemple.ch", "https://ged.exemple.ch")]
    [InlineData("https://ged.exemple.ch/", "https://ged.exemple.ch")]
    [InlineData("http://127.0.0.1:8870/", "http://127.0.0.1:8870")]
    [InlineData("  https://ged.exemple.ch/base/  ", "https://ged.exemple.ch/base")]
    public void Normalise_une_adresse_http_ou_https(string brut, string attendu) =>
        Assert.Equal(attendu, AdresseServeur.Normaliser(brut));

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("ged.exemple.ch")]
    [InlineData("ftp://ged.exemple.ch")]
    [InlineData("file:///c:/ged")]
    [InlineData("https://utilisateur:secret@ged.exemple.ch")]
    [InlineData("https://ged.exemple.ch?x=1")]
    [InlineData("https://ged.exemple.ch#frag")]
    public void Refuse_une_adresse_piegee(string? brut) =>
        Assert.Throws<ArgumentException>(() => AdresseServeur.Normaliser(brut));
}

public sealed class DepotConfigurationTests
{
    [Fact]
    public void Relit_ce_qu_il_a_ecrit()
    {
        using var dossier = new DossierTemporaire();
        var depot = new DepotConfigurationFichier(dossier.CheminDe("config.json"));
        var configuration = new ConfigurationPoste
        {
            AdresseServeur = "https://ged.exemple.ch",
            Appareil = "POSTE-01",
            Compte = "utilisateur@exemple.ch",
            GedNom = "GEDv2",
            EnroleLeUtc = new DateTimeOffset(2026, 10, 11, 9, 0, 0, TimeSpan.Zero),
        };

        depot.Ecrire(configuration);

        var relu = depot.Lire();
        Assert.NotNull(relu);
        Assert.Equal("https://ged.exemple.ch", relu!.AdresseServeur);
        Assert.Equal("POSTE-01", relu.Appareil);
        Assert.Equal("utilisateur@exemple.ch", relu.Compte);
        Assert.Equal("GEDv2", relu.GedNom);
        Assert.Equal(configuration.EnroleLeUtc, relu.EnroleLeUtc);
    }

    [Fact]
    public void Rend_null_sur_fichier_illisible_au_lieu_de_lever()
    {
        using var dossier = new DossierTemporaire();
        var chemin = dossier.CheminDe("config.json");
        File.WriteAllText(chemin, "{ ceci n’est pas du JSON");

        var configuration = new DepotConfigurationFichier(chemin).Lire();

        Assert.Null(configuration);
    }
}

public sealed class CoffreJetonDpapiTests
{
    [Fact]
    public void Chiffre_le_jeton_pour_l_utilisateur_et_le_relit()
    {
        using var dossier = new DossierTemporaire();
        var coffre = new CoffreJetonDpapi(dossier.CheminDe("jeton.dpapi"));
        const string jeton = "jeton-appareil-secret-0123456789abcdef";

        coffre.Ecrire(jeton);

        Assert.True(coffre.Existe);
        Assert.Equal(jeton, coffre.Lire());
        var surDisque = File.ReadAllText(dossier.CheminDe("jeton.dpapi"), Encoding.UTF8);
        Assert.DoesNotContain("jeton-appareil-secret", surDisque, StringComparison.Ordinal);
    }

    [Fact]
    public void Efface_le_jeton_sur_ree_enrolement_volontaire()
    {
        using var dossier = new DossierTemporaire();
        var coffre = new CoffreJetonDpapi(dossier.CheminDe("jeton.dpapi"));
        coffre.Ecrire("un-jeton");

        coffre.Effacer();

        Assert.False(coffre.Existe);
        Assert.Null(coffre.Lire());
    }
}

public sealed class EnroleurHttpTests
{
    [Fact]
    public async Task Fixe_l_adresse_et_range_le_jeton_chiffre()
    {
        using var dossier = new DossierTemporaire();
        var coffre = new CoffreJetonDpapi(dossier.CheminDe("jeton.dpapi"));
        var depot = new DepotConfigurationFichier(dossier.CheminDe("config.json"));
        var handler = new HttpMessageHandlerFaux(_ => HttpMessageHandlerFaux.Json(
            HttpStatusCode.OK,
            """{"jeton":"jeton-0123456789","compte":"utilisateur@exemple.ch","ged_nom":"GEDv2","expire_le":null}"""));
        var enroleur = new EnroleurHttp(
            new HttpClient(handler), depot, coffre, new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 8, 0, 0, TimeSpan.Zero)),
            new JournalMemoire());

        var configuration = await enroleur.EnrollerAsync(
            "https://ged.exemple.ch/", "code-echange", "K7M-4QX", "POSTE-01");

        Assert.Equal("https://ged.exemple.ch", configuration.AdresseServeur);
        Assert.Equal("POSTE-01", configuration.Appareil);
        Assert.Equal("utilisateur@exemple.ch", configuration.Compte);
        Assert.Equal("https://ged.exemple.ch/api/v1/appairage/jeton", handler.Requetes.Single().RequestUri!.ToString());
        Assert.Equal("jeton-0123456789", coffre.Lire());
        Assert.NotNull(depot.Lire());
    }

    [Fact]
    public async Task Nomme_le_refus_du_serveur_sans_rien_enregistrer()
    {
        using var dossier = new DossierTemporaire();
        var coffre = new CoffreJetonDpapi(dossier.CheminDe("jeton.dpapi"));
        var depot = new DepotConfigurationFichier(dossier.CheminDe("config.json"));
        var handler = new HttpMessageHandlerFaux(_ => HttpMessageHandlerFaux.Json(
            HttpStatusCode.BadRequest,
            """{"erreur":{"etape":"POST /api/v1/appairage/jeton","cause":"code d’appairage inconnu","reference":"r-42"}}"""));
        var enroleur = new EnroleurHttp(
            new HttpClient(handler), depot, coffre, HorlogeSysteme.Instance, new JournalMemoire());

        var erreur = await Assert.ThrowsAsync<ErreurServeur>(() => enroleur.EnrollerAsync(
            "https://ged.exemple.ch", "code-echange", "K7M-4QX", "POSTE-01"));

        Assert.Equal(400, erreur.Statut);
        Assert.Equal("code d’appairage inconnu", erreur.Cause);
        Assert.Equal("r-42", erreur.Reference);
        Assert.False(coffre.Existe);
        Assert.Null(depot.Lire());
    }
}

public sealed class RacineAgentTests
{
    [Theory]
    [InlineData("rapport.docx", "rapport.docx")]
    [InlineData(@"..\..\windows\system32\evil.docx", "evil.docx")]
    [InlineData("/etc/passwd", "passwd")]
    [InlineData("", "document")]
    [InlineData("..", "document")]
    [InlineData("con.docx", "_con.docx")]
    [InlineData("nom:interdit?.docx", "nom_interdit_.docx")]
    public void NomSain_interdit_toute_traversee(string nom, string attendu) =>
        Assert.Equal(attendu, RacineAgent.NomSain(nom));

    [Fact]
    public void Les_chemins_restent_sous_la_racine()
    {
        var racine = new RacineAgent(@"C:\ged-agent-essai");

        var chemin = racine.CheminTravail(1234, @"..\..\evil.docx");

        Assert.Equal(Path.Combine(@"C:\ged-agent-essai", "Travail", "1234", "evil.docx"), chemin);
        Assert.StartsWith(Path.Combine(@"C:\ged-agent-essai", "Travail"), chemin, StringComparison.Ordinal);
    }
}
