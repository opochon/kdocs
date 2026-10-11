namespace Kdocs.AgentPoste.Protocole;

/// <summary>URL kdocs:// acceptée : une action de la liste blanche et un identifiant numérique positif.</summary>
/// <param name="Action">Action, toujours minuscule et issue de la liste blanche (aujourd'hui « ouvrir »).</param>
/// <param name="Document">Identifiant de document, entier strictement positif.</param>
public sealed record UrlKdocs(string Action, int Document);

/// <summary>
/// Résultat d'analyse d'une URL : acceptée (avec l'URL interprétée) ou refusée (avec une raison
/// nommée, journalisable). Aucune exception : un lien piégé ne doit jamais faire tomber l'agent.
/// </summary>
public sealed class ResultatUrl
{
    private ResultatUrl(bool accepte, UrlKdocs? url, string? refus)
    {
        Accepte = accepte;
        Url = url;
        Refus = refus;
    }

    public bool Accepte { get; }

    public UrlKdocs? Url { get; }

    /// <summary>Raison du refus, jamais nulle quand <see cref="Accepte"/> est faux.</summary>
    public string? Refus { get; }

    public static ResultatUrl Accepter(UrlKdocs url) => new(true, url, null);

    public static ResultatUrl Refuser(string raison) => new(false, null, raison);
}
