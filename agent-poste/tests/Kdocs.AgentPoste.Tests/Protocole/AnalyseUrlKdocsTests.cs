using Kdocs.AgentPoste.Protocole;

namespace Kdocs.AgentPoste.Tests.Protocole;

/// <summary>
/// Oracle de l'analyse stricte d'une URL kdocs:// (cahier des charges F13, point 6) : le lien ne
/// porte qu'une action et un identifiant numérique ; TOUT le reste est refusé et la raison est nommée.
/// Aucun test ne dépend d'Office, du réseau ou du registre.
/// </summary>
public sealed class AnalyseUrlKdocsTests
{
    [Theory]
    [InlineData("kdocs://ouvrir/1234", 1234)]
    [InlineData("kdocs://ouvrir/1", 1)]
    [InlineData("kdocs://ouvrir/2147483647", int.MaxValue)]
    [InlineData("KDOCS://OUVRIR/42", 42)]
    [InlineData("Kdocs://Ouvrir/7", 7)]
    public void Accepte_une_action_ouvrir_et_un_identifiant_numerique(string url, int attendu)
    {
        var resultat = AnalyseUrlKdocs.Analyser(url);

        Assert.True(resultat.Accepte, resultat.Refus);
        Assert.NotNull(resultat.Url);
        Assert.Equal("ouvrir", resultat.Url!.Action);
        Assert.Equal(attendu, resultat.Url.Document);
    }

    [Theory]
    // schéma, autorité, portée
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("http://ouvrir/1234")]
    [InlineData("file://ouvrir/1234")]
    [InlineData("kdocs:ouvrir/1234")]
    [InlineData("kdocs://")]
    [InlineData("kdocs:///1234")]
    [InlineData("kdocs://ouvrir")]
    [InlineData("kdocs://ouvrir/")]
    // identifiant non numérique ou hors bornes
    [InlineData("kdocs://ouvrir/abc")]
    [InlineData("kdocs://ouvrir/12a")]
    [InlineData("kdocs://ouvrir/a12")]
    [InlineData("kdocs://ouvrir/-12")]
    [InlineData("kdocs://ouvrir/+12")]
    [InlineData("kdocs://ouvrir/12.5")]
    [InlineData("kdocs://ouvrir/1 2")]
    [InlineData("kdocs://ouvrir/0")]
    [InlineData("kdocs://ouvrir/00")]
    [InlineData("kdocs://ouvrir/0012")]
    [InlineData("kdocs://ouvrir/99999999999")]
    [InlineData("kdocs://ouvrir/2147483648")]
    [InlineData("kdocs://ouvrir/\u0661\u0662")]
    // segments, traversée, espaces, fin de ligne
    [InlineData("kdocs://ouvrir/12/suite")]
    [InlineData("kdocs://ouvrir/12/")]
    [InlineData("kdocs://ouvrir/12/../13")]
    [InlineData("kdocs://ouvrir/..%2F13")]
    [InlineData("kdocs://ouvrir/%31%32")]
    [InlineData("kdocs://ouvrir/12?x=1")]
    [InlineData("kdocs://ouvrir/12#frag")]
    [InlineData(" kdocs://ouvrir/12")]
    [InlineData("kdocs://ouvrir/12 ")]
    [InlineData("kdocs://ouvrir/12\n")]
    [InlineData("kdocs://ouvrir/12\r\nX")]
    [InlineData("kdocs://ouvrir/12\\..\\13")]
    // autorité piégée : utilisateur, port, autre serveur
    [InlineData("kdocs://utilisateur@ouvrir/12")]
    [InlineData("kdocs://ouvrir:80/12")]
    [InlineData("kdocs://serveur-autre/12")]
    [InlineData("kdocs://ouvrir.evil.example/12")]
    [InlineData("kdocs://ouvrir./12")]
    [InlineData("kdocs://127.0.0.1/12")]
    // action hors liste
    [InlineData("kdocs://fermer/12")]
    [InlineData("kdocs://recuperer/12")]
    [InlineData("kdocs://ouvrir-mal/12")]
    [InlineData("kdocs://ouvrir_/12")]
    public void Refuse_tout_le_reste_avec_une_raison_nommee(string? url)
    {
        var resultat = AnalyseUrlKdocs.Analyser(url);

        Assert.False(resultat.Accepte, $"URL acceptée à tort : {url}");
        Assert.Null(resultat.Url);
        Assert.False(string.IsNullOrWhiteSpace(resultat.Refus));
    }

    [Fact]
    public void Refuse_une_url_demesuree()
    {
        var url = "kdocs://ouvrir/" + new string('1', 500);

        var resultat = AnalyseUrlKdocs.Analyser(url);

        Assert.False(resultat.Accepte);
    }

    [Fact]
    public void Refuse_les_caracteres_de_controle_et_non_ascii()
    {
        Assert.False(AnalyseUrlKdocs.Analyser("kdocs://ouvrir/12\t").Accepte);
        Assert.False(AnalyseUrlKdocs.Analyser("kdocs://ouvrir/12\u0000").Accepte);
        Assert.False(AnalyseUrlKdocs.Analyser("kdocs://ouvrir/12\u0007").Accepte);
        Assert.False(AnalyseUrlKdocs.Analyser("kdocs://ouvrir/12\u2028").Accepte);
    }
}
