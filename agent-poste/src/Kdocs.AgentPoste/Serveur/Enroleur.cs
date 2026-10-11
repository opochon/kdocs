using System.Net.Http.Json;
using System.Text.Json;
using Kdocs.AgentPoste.Configuration;

namespace Kdocs.AgentPoste.Serveur;

/// <summary>
/// Enrôlement du poste : échange d'un appairage existant (créé depuis « Mon profil › Appareils reliés »)
/// contre un JETON D'APPAREIL lié au compte. Le jeton est rangé chiffré (DPAPI) et l'adresse du serveur
/// est fixée ici, une fois pour toutes.
/// </summary>
public interface IEnroleur
{
    Task<ConfigurationPoste> EnrollerAsync(
        string adresse,
        string code,
        string verification,
        string poste,
        CancellationToken annulation = default);
}

public sealed class EnroleurHttp : IEnroleur
{
    private readonly HttpClient _http;
    private readonly IDepotConfiguration _depot;
    private readonly ICoffreJeton _coffre;
    private readonly IHorloge _horloge;
    private readonly IJournal _journal;

    public EnroleurHttp(
        HttpClient http,
        IDepotConfiguration depot,
        ICoffreJeton coffre,
        IHorloge horloge,
        IJournal journal)
    {
        _http = http;
        _depot = depot;
        _coffre = coffre;
        _horloge = horloge;
        _journal = journal;
    }

    public async Task<ConfigurationPoste> EnrollerAsync(
        string adresse,
        string code,
        string verification,
        string poste,
        CancellationToken annulation = default)
    {
        const string etape = "enrôlement du poste";
        var serveur = AdresseServeur.Normaliser(adresse);
        if (string.IsNullOrWhiteSpace(code))
        {
            throw new ErreurAgent(etape, "code d’appairage requis");
        }
        if (string.IsNullOrWhiteSpace(verification))
        {
            throw new ErreurAgent(etape, "code de vérification requis");
        }
        if (string.IsNullOrWhiteSpace(poste))
        {
            throw new ErreurAgent(etape, "nom du poste requis");
        }

        using var reponse = await _http
            .PostAsJsonAsync(
                serveur + "/api/v1/appairage/jeton",
                new { code = code.Trim(), verification = verification.Trim(), poste = poste.Trim() },
                annulation)
            .ConfigureAwait(false);
        var corps = await reponse.Content.ReadAsStringAsync(annulation).ConfigureAwait(false);
        if (!reponse.IsSuccessStatusCode)
        {
            var erreur = FormatApi.LireErreur((int)reponse.StatusCode, etape, corps);
            _journal.Avertissement(etape, erreur.MessageUtilisateur);

            throw erreur;
        }

        string? jeton;
        string? compte;
        string? gedNom;
        try
        {
            using var document = JsonDocument.Parse(corps);
            var racine = document.RootElement;
            jeton = FormatApi.LireChaine(racine, "jeton");
            compte = FormatApi.LireChaine(racine, "compte");
            gedNom = FormatApi.LireChaine(racine, "ged_nom");
        }
        catch (JsonException exception)
        {
            throw new ErreurAgent(etape, "réponse d’appairage illisible", exception);
        }

        if (string.IsNullOrWhiteSpace(jeton) || string.IsNullOrWhiteSpace(compte))
        {
            throw new ErreurAgent(etape, "réponse d’appairage sans jeton ou sans compte");
        }

        var configuration = new ConfigurationPoste
        {
            AdresseServeur = serveur,
            Appareil = poste.Trim(),
            Compte = compte,
            GedNom = string.IsNullOrWhiteSpace(gedNom) ? null : gedNom,
            EnroleLeUtc = _horloge.MaintenantUtc,
        };
        _coffre.Ecrire(jeton);
        _depot.Ecrire(configuration);
        _journal.Info(etape, $"poste « {configuration.Appareil} » relié au compte {compte} sur {serveur}");

        return configuration;
    }
}
