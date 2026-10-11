using Kdocs.AgentPoste.Protocole;

namespace Kdocs.AgentPoste.Tests.Protocole;

/// <summary>
/// Oracle de l'enregistrement du protocole : les valeurs écrites sous HKCU\Software\Classes\kdocs sont
/// exactement celles attendues par Windows, et la commande reprend le lien en argument. Testé sur un
/// registre en mémoire (la vraie ruche est touchée par le parcours F13, jamais par un test unitaire).
/// </summary>
public sealed class ProtocoleKdocsTests
{
    [Fact]
    public void Ecrit_les_cles_attendues_par_windows()
    {
        var registre = new RegistreMemoire();
        var protocole = new ProtocoleKdocs(registre);

        protocole.Enregistrer(@"C:\apps\Kdocs.AgentPoste.exe");

        Assert.Equal("URL:K-Docs Protocol", registre.Lire(ProtocoleKdocs.Cle, string.Empty));
        Assert.Equal(string.Empty, registre.Lire(ProtocoleKdocs.Cle, "URL Protocol"));
        Assert.Equal(
            "\"C:\\apps\\Kdocs.AgentPoste.exe\" \"%1\"",
            registre.Lire(ProtocoleKdocs.Cle + @"\shell\open\command", string.Empty));
    }

    [Fact]
    public void EstEnregistre_rend_la_commande()
    {
        var registre = new RegistreMemoire();
        var protocole = new ProtocoleKdocs(registre);

        Assert.False(protocole.EstEnregistre(out var avant));
        Assert.Null(avant);

        protocole.Enregistrer(@"C:\apps\Kdocs.AgentPoste.exe");

        Assert.True(protocole.EstEnregistre(out var apres));
        Assert.Equal("\"C:\\apps\\Kdocs.AgentPoste.exe\" \"%1\"", apres);
    }

    [Fact]
    public void Refuse_un_chemin_vide()
    {
        var protocole = new ProtocoleKdocs(new RegistreMemoire());

        Assert.Throws<ArgumentException>(() => protocole.Enregistrer("  "));
    }
}

public sealed class OptionsAgentTests
{
    [Fact]
    public void Sans_argument_demarre_l_icone()
    {
        var options = Kdocs.AgentPoste.Application.OptionsAgent.Analyser([]);

        Assert.True(options.Icone);
        Assert.Null(options.Url);
        Assert.False(options.EstEnrolement);
    }

    [Fact]
    public void Reconnait_un_lien_kdocs()
    {
        var options = Kdocs.AgentPoste.Application.OptionsAgent.Analyser(["kdocs://ouvrir/1234"]);

        Assert.Equal("kdocs://ouvrir/1234", options.Url);
    }

    [Fact]
    public void Reconnait_les_options_d_enrolement()
    {
        var options = Kdocs.AgentPoste.Application.OptionsAgent.Analyser(
        [
            "--enrolement", "--serveur", "https://ged.exemple.ch", "--code", "abc",
            "--verification", "K7M-4QX", "--poste", "POSTE-01", "--sortie", @"C:\temp\res.json",
        ]);

        Assert.True(options.EstEnrolement);
        Assert.Equal("https://ged.exemple.ch", options.ServeurEnrolement);
        Assert.Equal("abc", options.CodeEnrolement);
        Assert.Equal("K7M-4QX", options.VerificationEnrolement);
        Assert.Equal("POSTE-01", options.PosteEnrolement);
        Assert.Equal(@"C:\temp\res.json", options.FichierSortie);
    }
}
