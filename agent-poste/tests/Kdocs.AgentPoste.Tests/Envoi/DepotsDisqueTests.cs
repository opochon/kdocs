using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.Envoi;

/// <summary>
/// Oracle des dépôts sur disque : une entrée de file ou une fiche illisible n'est JAMAIS avalée en
/// silence (elle est tracée), et ne fait jamais tomber l'agent.
/// </summary>
public sealed class DepotsDisqueTests
{
    [Fact]
    public void Une_entree_de_file_illisible_est_tracee_et_ignoree()
    {
        using var dossier = new DossierTemporaire();
        File.WriteAllText(Path.Combine(dossier.Chemin, "cassee.json"), "{ pas du JSON");
        var journal = new JournalMemoire();
        var depot = new DepotFileDisque(dossier.Chemin, journal);

        var entrees = depot.Charger();

        Assert.Empty(entrees);
        Assert.Contains(journal.Lignes, l => l.Message.Contains("illisible", StringComparison.Ordinal));
    }

    [Fact]
    public void Une_fiche_de_travail_illisible_est_tracee_et_ignoree()
    {
        using var dossier = new DossierTemporaire();
        var racine = new Kdocs.AgentPoste.RacineAgent(dossier.Chemin);
        racine.Preparer();
        Directory.CreateDirectory(racine.DossierTravailDe(7));
        File.WriteAllText(Path.Combine(racine.DossierTravailDe(7), "casse" + Kdocs.AgentPoste.RacineAgent.ExtensionFiche), "{ pas du JSON");
        var journal = new JournalMemoire();
        var depot = new DepotFichesDisque(racine, journal);

        var fiches = depot.Lister();

        Assert.Empty(fiches);
        Assert.Contains(journal.Lignes, l => l.Message.Contains("illisible", StringComparison.Ordinal));
    }
}
