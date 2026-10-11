using System.Globalization;
using System.Net.Http.Headers;
using System.Text.Json;

namespace Kdocs.AgentPoste.Serveur;

/// <summary>État d'un document côté serveur (contrat <c>GET …/documents/{id}</c>).</summary>
public sealed record EtatDocument(int Document, string Nom, string Mime, long Taille, string Empreinte, int Version);

/// <summary>Résultat d'un dépôt de version (contrat <c>POST …/versions</c>).</summary>
public sealed record ResultatDepot(int Version, string Empreinte, bool Conflit, int VersionBase, int VersionAvant, bool Deja);

/// <summary>Métadonnées d'une copie de secours (contrat <c>POST/GET …/secours</c>).</summary>
public sealed record MetaSecours(string Appareil, string Quand, string Empreinte, long Taille, string Nom);

/// <summary>Fichier téléchargé : chemin local réellement écrit, plus les en-têtes du serveur.</summary>
public sealed record FichierTelecharge(string Chemin, string Empreinte, int Version);

/// <summary>
/// Porte de l'API d'édition (jeton d'appareil). Une seule instance, partagée par l'agent.
/// </summary>
public interface IClientEdition
{
    Task<EtatDocument> EtatAsync(int document, CancellationToken annulation = default);

    Task<FichierTelecharge> TelechargerAsync(int document, string cheminDestination, CancellationToken annulation = default);

    Task<ResultatDepot> DeposerVersionAsync(
        int document,
        string cheminFichier,
        string empreinte,
        int versionBase,
        CancellationToken annulation = default);

    Task OuvrirAsync(int document, CancellationToken annulation = default);

    Task<bool> SignalerAsync(int document, CancellationToken annulation = default);

    Task FermerAsync(int document, CancellationToken annulation = default);

    Task<MetaSecours> DeposerSecoursAsync(int document, string cheminFichier, CancellationToken annulation = default);

    Task<IReadOnlyList<MetaSecours>> ListerSecoursAsync(int document, CancellationToken annulation = default);
}

/// <summary>
/// Client HTTP de l'API du poste. Authentification par le JETON D'APPAREIL
/// (<c>Authorization: Bearer …</c>), jamais par une session web ; le poste est celui du jeton côté
/// serveur, jamais une valeur du corps. Aucune URL n'est construite depuis une entrée utilisateur :
/// seul l'identifiant numérique (déjà validé) entre dans le chemin.
/// </summary>
public sealed class ClientEditionHttp : IClientEdition, IDisposable
{
    private readonly string _adresse;
    private readonly string _jeton;
    private readonly HttpClient _http;
    private readonly IJournal _journal;
    private readonly bool _possedeHttp;

    public ClientEditionHttp(string adresse, string jeton, IJournal journal, HttpClient? http = null)
    {
        _adresse = adresse.TrimEnd('/');
        _jeton = jeton;
        _journal = journal;
        _possedeHttp = http is null;
        _http = http ?? new HttpClient { Timeout = TimeSpan.FromMinutes(2) };
    }

    public async Task<EtatDocument> EtatAsync(int document, CancellationToken annulation = default)
    {
        var etape = $"GET /api/v1/edition/documents/{document}";
        using var reponse = await EnvoyerAsync(HttpMethod.Get, Chemin(document), null, annulation).ConfigureAwait(false);
        var racine = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);

        return new EtatDocument(
            FormatApi.LireEntier(racine, "document") ?? document,
            FormatApi.LireChaine(racine, "nom") ?? "document",
            FormatApi.LireChaine(racine, "mime") ?? "application/octet-stream",
            FormatApi.LireEntier(racine, "taille") ?? 0,
            FormatApi.LireChaine(racine, "empreinte") ?? string.Empty,
            FormatApi.LireEntier(racine, "version") ?? 0);
    }

    public async Task<FichierTelecharge> TelechargerAsync(
        int document,
        string cheminDestination,
        CancellationToken annulation = default)
    {
        var etape = $"GET /api/v1/edition/documents/{document}/fichier";
        using var reponse = await EnvoyerAsync(HttpMethod.Get, $"{Chemin(document)}/fichier", null, annulation)
            .ConfigureAwait(false);

        var dossier = Path.GetDirectoryName(cheminDestination);
        if (!string.IsNullOrEmpty(dossier))
        {
            Directory.CreateDirectory(dossier);
        }

        var temporaire = cheminDestination + ".part";
        await using (var sortie = new FileStream(temporaire, FileMode.Create, FileAccess.Write, FileShare.None))
        {
            await reponse.Content.CopyToAsync(sortie, annulation).ConfigureAwait(false);
        }

        File.Move(temporaire, cheminDestination, overwrite: true);

        var empreinte = LireEntete(reponse, "X-Ged-Empreinte");
        var version = LireEnteteEntier(reponse, "X-Ged-Version");

        return new FichierTelecharge(cheminDestination, empreinte, version);
    }

    public async Task<ResultatDepot> DeposerVersionAsync(
        int document,
        string cheminFichier,
        string empreinte,
        int versionBase,
        CancellationToken annulation = default)
    {
        var etape = $"POST /api/v1/edition/documents/{document}/versions";
        using var contenu = ConstruireMultipart(cheminFichier, versionBase);
        using var reponse = await EnvoyerAsync(HttpMethod.Post, $"{Chemin(document)}/versions", contenu, annulation)
            .ConfigureAwait(false);
        var racine = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);

        return new ResultatDepot(
            FormatApi.LireEntier(racine, "version") ?? 0,
            FormatApi.LireChaine(racine, "empreinte") ?? string.Empty,
            FormatApi.LireBooleen(racine, "conflit") ?? false,
            FormatApi.LireEntier(racine, "version_base") ?? versionBase,
            FormatApi.LireEntier(racine, "version_avant") ?? 0,
            FormatApi.LireBooleen(racine, "deja") ?? false);
    }

    public async Task OuvrirAsync(int document, CancellationToken annulation = default)
    {
        var etape = $"POST /api/v1/edition/documents/{document}/ouverture";
        using var reponse = await EnvoyerAsync(HttpMethod.Post, $"{Chemin(document)}/ouverture", null, annulation)
            .ConfigureAwait(false);
        _ = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);
    }

    public async Task<bool> SignalerAsync(int document, CancellationToken annulation = default)
    {
        var etape = $"POST /api/v1/edition/documents/{document}/signal";
        using var reponse = await EnvoyerAsync(HttpMethod.Post, $"{Chemin(document)}/signal", null, annulation)
            .ConfigureAwait(false);
        var racine = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);

        return FormatApi.LireBooleen(racine, "prolonge") ?? false;
    }

    public async Task FermerAsync(int document, CancellationToken annulation = default)
    {
        var etape = $"POST /api/v1/edition/documents/{document}/fermeture";
        using var reponse = await EnvoyerAsync(HttpMethod.Post, $"{Chemin(document)}/fermeture", null, annulation)
            .ConfigureAwait(false);
        _ = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);
    }

    public async Task<MetaSecours> DeposerSecoursAsync(
        int document,
        string cheminFichier,
        CancellationToken annulation = default)
    {
        var etape = $"POST /api/v1/edition/documents/{document}/secours";
        using var contenu = new MultipartFormDataContent();
        await using var flux = OuvrirLecture(cheminFichier);
        using var fichier = new StreamContent(flux);
        fichier.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");
        contenu.Add(fichier, "fichier", Path.GetFileName(cheminFichier));
        using var reponse = await EnvoyerAsync(HttpMethod.Post, $"{Chemin(document)}/secours", contenu, annulation)
            .ConfigureAwait(false);
        var racine = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);

        return new MetaSecours(
            FormatApi.LireChaine(racine, "appareil") ?? string.Empty,
            FormatApi.LireChaine(racine, "quand") ?? string.Empty,
            FormatApi.LireChaine(racine, "empreinte") ?? string.Empty,
            FormatApi.LireEntier(racine, "taille") ?? 0,
            FormatApi.LireChaine(racine, "nom") ?? Path.GetFileName(cheminFichier));
    }

    public async Task<IReadOnlyList<MetaSecours>> ListerSecoursAsync(
        int document,
        CancellationToken annulation = default)
    {
        var etape = $"GET /api/v1/edition/documents/{document}/secours";
        using var reponse = await EnvoyerAsync(HttpMethod.Get, $"{Chemin(document)}/secours", null, annulation)
            .ConfigureAwait(false);
        var racine = await LireJsonAsync(reponse, etape, annulation).ConfigureAwait(false);

        var copies = new List<MetaSecours>();
        if (racine.TryGetProperty("copies", out var liste) && liste.ValueKind == JsonValueKind.Array)
        {
            foreach (var copie in liste.EnumerateArray())
            {
                if (copie.ValueKind != JsonValueKind.Object)
                {
                    continue;
                }
                copies.Add(new MetaSecours(
                    FormatApi.LireChaine(copie, "appareil") ?? string.Empty,
                    FormatApi.LireChaine(copie, "quand") ?? string.Empty,
                    FormatApi.LireChaine(copie, "empreinte") ?? string.Empty,
                    FormatApi.LireEntier(copie, "taille") ?? 0,
                    FormatApi.LireChaine(copie, "nom") ?? string.Empty));
            }
        }

        return copies;
    }

    public void Dispose()
    {
        if (_possedeHttp)
        {
            _http.Dispose();
        }
    }

    private static string Chemin(int document) =>
        "/api/v1/edition/documents/" + document.ToString(CultureInfo.InvariantCulture);

    private static FileStream OuvrirLecture(string chemin) =>
        new(chemin, FileMode.Open, FileAccess.Read, FileShare.ReadWrite);

    private static MultipartFormDataContent ConstruireMultipart(string cheminFichier, int versionBase)
    {
        var contenu = new MultipartFormDataContent();
        var flux = OuvrirLecture(cheminFichier);
        try
        {
            var fichier = new StreamContent(flux);
            fichier.Headers.ContentType = new MediaTypeHeaderValue("application/octet-stream");
            contenu.Add(fichier, "fichier", Path.GetFileName(cheminFichier));
            contenu.Add(
                new StringContent(versionBase.ToString(CultureInfo.InvariantCulture)),
                "version_base");

            return contenu;
        }
        catch
        {
            flux.Dispose();
            contenu.Dispose();

            throw;
        }
    }

    private async Task<HttpResponseMessage> EnvoyerAsync(
        HttpMethod methode,
        string chemin,
        HttpContent? contenu,
        CancellationToken annulation)
    {
        using var requete = new HttpRequestMessage(methode, _adresse + chemin);
        requete.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _jeton);
        requete.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        if (contenu is not null)
        {
            requete.Content = contenu;
        }

        var reponse = await _http.SendAsync(requete, HttpCompletionOption.ResponseHeadersRead, annulation)
            .ConfigureAwait(false);
        if (reponse.IsSuccessStatusCode)
        {
            return reponse;
        }

        var corps = await reponse.Content.ReadAsStringAsync(annulation).ConfigureAwait(false);
        var statut = (int)reponse.StatusCode;
        reponse.Dispose();
        var erreur = FormatApi.LireErreur(statut, methode.Method + " " + chemin, corps);
        _journal.Avertissement(erreur.Etape, erreur.MessageUtilisateur);

        throw erreur;
    }

    private static async Task<JsonElement> LireJsonAsync(
        HttpResponseMessage reponse,
        string etape,
        CancellationToken annulation)
    {
        var corps = await reponse.Content.ReadAsStringAsync(annulation).ConfigureAwait(false);
        try
        {
            using var document = JsonDocument.Parse(corps);

            return document.RootElement.Clone();
        }
        catch (JsonException exception)
        {
            throw new ErreurAgent(etape, "réponse du serveur illisible", exception);
        }
    }

    private static string LireEntete(HttpResponseMessage reponse, string nom) =>
        reponse.Headers.TryGetValues(nom, out var valeurs) ? string.Join(',', valeurs) : string.Empty;

    private static int LireEnteteEntier(HttpResponseMessage reponse, string nom) =>
        int.TryParse(LireEntete(reponse, nom), NumberStyles.Integer, CultureInfo.InvariantCulture, out var valeur)
            ? valeur
            : 0;
}
