using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Application;

/// <summary>
/// Oracle du gestionnaire d'URL : un lien valide ouvre le bon document ; tout lien piégé est refusé,
/// journalisé, et n'entraîne AUCUNE ouverture.
/// </summary>
public sealed class GestionnaireUrlTests
{
    private sealed class FauxOuverture : IOuvertureDocument
    {
        public List<int> Ouverts { get; } = [];

        public Task<FicheDocumentTravail> OuvrirAsync(int document, CancellationToken annulation = default)
        {
            Ouverts.Add(document);

            return Task.FromResult(new FicheDocumentTravail { Document = document });
        }
    }

    [Theory]
    [InlineData("kdocs://ouvrir/1234", 1234)]
    [InlineData("kdocs://ouvrir/7", 7)]
    public async Task Ouvre_un_lien_valide(string url, int attendu)
    {
        var ouverture = new FauxOuverture();
        var journal = new JournalMemoire();
        var gestionnaire = new GestionnaireUrl(ouverture, journal);

        var resultat = await gestionnaire.TraiterAsync(url);

        Assert.True(resultat.Accepte);
        Assert.Equal([attendu], ouverture.Ouverts);
        Assert.DoesNotContain(journal.Lignes, l => l.Message.Contains("refusé", StringComparison.Ordinal));
    }

    [Theory]
    [InlineData("kdocs://fermer/12")]
    [InlineData("kdocs://ouvrir/abc")]
    [InlineData("kdocs://ouvrir/12/../13")]
    [InlineData("http://evil.exemple/ouvrir/12")]
    [InlineData("kdocs://serveur-autre/12")]
    [InlineData("kdocs://ouvrir/12\r\nkdocs://ouvrir/13")]
    public async Task Refuse_et_journalise_sans_rien_ouvrir(string url)
    {
        var ouverture = new FauxOuverture();
        var journal = new JournalMemoire();
        var gestionnaire = new GestionnaireUrl(ouverture, journal);

        var resultat = await gestionnaire.TraiterAsync(url);

        Assert.False(resultat.Accepte);
        Assert.Empty(ouverture.Ouverts);
        Assert.Contains(journal.Lignes, l => l.Message.Contains("refusé", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Ne_jamais_ouvrir_si_l_ouverture_echoue_et_tracer_l_erreur()
    {
        var journal = new JournalMemoire();
        var gestionnaire = new GestionnaireUrl(new OuvertureDefaillante(), journal);

        var resultat = await gestionnaire.TraiterAsync("kdocs://ouvrir/9");

        Assert.True(resultat.Accepte);
        Assert.Contains(journal.Lignes, l => l.Niveau == "ERREUR" && l.Message.Contains("ouverture impossible", StringComparison.Ordinal));
    }

    private sealed class OuvertureDefaillante : IOuvertureDocument
    {
        public Task<FicheDocumentTravail> OuvrirAsync(int document, CancellationToken annulation = default) =>
            throw new ErreurAgent("ouverture", "serveur injoignable");
    }
}
