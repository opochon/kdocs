namespace Kdocs.AgentPoste.Application;

/// <summary>Ce que la ligne de commande demande à l'agent : icône, ouverture d'un lien, ou enrôlement.</summary>
public sealed record OptionsAgent(
    string? Url,
    bool Icone,
    string? ServeurEnrolement,
    string? CodeEnrolement,
    string? VerificationEnrolement,
    string? PosteEnrolement,
    string? FichierSortie)
{
    public bool EstEnrolement => ServeurEnrolement is not null;

    /// <summary>
    /// Analyse les arguments tels que Windows les transmet. Un lien <c>kdocs://</c> est reconnu ; sinon on
    /// démarre l'icône (le comportement par défaut de l'application de session).
    /// </summary>
    public static OptionsAgent Analyser(string[] args)
    {
        if (args.Length == 0)
        {
            return new OptionsAgent(null, true, null, null, null, null, null);
        }

        if (args[0].StartsWith("kdocs://", StringComparison.OrdinalIgnoreCase))
        {
            return new OptionsAgent(args[0], true, null, null, null, null, null);
        }

        if (string.Equals(args[0], "--enrolement", StringComparison.OrdinalIgnoreCase))
        {
            return new OptionsAgent(
                null,
                false,
                Valeur(args, "--serveur"),
                Valeur(args, "--code"),
                Valeur(args, "--verification"),
                Valeur(args, "--poste"),
                Valeur(args, "--sortie"));
        }

        return new OptionsAgent(null, true, null, null, null, null, null);
    }

    private static string? Valeur(string[] args, string nom)
    {
        for (var i = 0; i < args.Length - 1; i++)
        {
            if (string.Equals(args[i], nom, StringComparison.OrdinalIgnoreCase))
            {
                var valeur = args[i + 1];
                if (!string.IsNullOrWhiteSpace(valeur))
                {
                    return valeur;
                }
            }
        }

        return null;
    }
}
