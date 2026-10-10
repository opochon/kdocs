namespace Kdocs.AgentPoste.Configuration;

/// <summary>
/// Adresse du serveur à l'enrôlement : un http(s) absolu, sans identifiants, sans paramètres, sans
/// fragment. Elle est ensuite FIXÉE : l'agent ne parle plus qu'à elle.
/// </summary>
public static class AdresseServeur
{
    /// <summary>Normalise l'adresse (sans « / » final). @throws ArgumentException nommée.</summary>
    public static string Normaliser(string? adresse)
    {
        if (string.IsNullOrWhiteSpace(adresse))
        {
            throw new ArgumentException("adresse du serveur requise", nameof(adresse));
        }

        var brut = adresse.Trim();
        if (!Uri.TryCreate(brut, UriKind.Absolute, out var uri))
        {
            throw new ArgumentException("adresse du serveur illisible", nameof(adresse));
        }
        if (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps)
        {
            throw new ArgumentException("adresse du serveur : http ou https attendu", nameof(adresse));
        }
        if (string.IsNullOrEmpty(uri.Host))
        {
            throw new ArgumentException("adresse du serveur : nom d’hôte attendu", nameof(adresse));
        }
        if (!string.IsNullOrEmpty(uri.UserInfo))
        {
            throw new ArgumentException("adresse du serveur : identifiants interdits", nameof(adresse));
        }
        if (!string.IsNullOrEmpty(uri.Query))
        {
            throw new ArgumentException("adresse du serveur : paramètres interdits", nameof(adresse));
        }
        if (!string.IsNullOrEmpty(uri.Fragment))
        {
            throw new ArgumentException("adresse du serveur : fragment interdit", nameof(adresse));
        }

        return uri.GetLeftPart(UriPartial.Path).TrimEnd('/');
    }
}
