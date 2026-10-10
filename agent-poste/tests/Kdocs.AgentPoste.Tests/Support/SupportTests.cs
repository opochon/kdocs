using System.Net;
using System.Text;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>Réponse HTTP scriptée pour les tests, sans réseau : on inspecte la requête réellement envoyée.</summary>
public sealed class HttpMessageHandlerFaux : HttpMessageHandler
{
    private readonly Func<HttpRequestMessage, HttpResponseMessage> _reponse;

    public HttpMessageHandlerFaux(Func<HttpRequestMessage, HttpResponseMessage> reponse) => _reponse = reponse;

    public List<HttpRequestMessage> Requetes { get; } = [];

    public string? DernierCorps { get; private set; }

    public static HttpResponseMessage Json(HttpStatusCode statut, string corps) => new(statut)
    {
        Content = new StringContent(corps, Encoding.UTF8, "application/json"),
    };

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage requete, CancellationToken annulation)
    {
        Requetes.Add(requete);
        if (requete.Content is not null)
        {
            DernierCorps = await requete.Content.ReadAsStringAsync(annulation);
        }

        return _reponse(requete);
    }
}

/// <summary>Dossier temporaire jetable des tests (jamais dans le dépôt).</summary>
public sealed class DossierTemporaire : IDisposable
{
    public DossierTemporaire()
    {
        Chemin = Path.Combine(Path.GetTempPath(), "kdocs-tests-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Chemin);
    }

    public string Chemin { get; }

    public string CheminDe(params string[] morceaux) => Path.Combine([Chemin, .. morceaux]);

    public void Dispose()
    {
        try
        {
            if (Directory.Exists(Chemin))
            {
                Directory.Delete(Chemin, recursive: true);
            }
        }
        catch (IOException)
        {
            // Un fichier encore tenu par un test ne doit pas faire échouer la suite.
        }
        catch (UnauthorizedAccessException)
        {
        }
    }
}
