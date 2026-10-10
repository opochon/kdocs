using System.Globalization;
using System.Text.RegularExpressions;

namespace Kdocs.AgentPoste.Protocole;

/// <summary>
/// Analyse STRICTE d'une URL kdocs:// (Microsoft Learn « Registering an Application to a URI Scheme » :
/// le gestionnaire reçoit le lien par la ligne de commande et ne doit rien en exécuter).
///
/// Forme acceptée, et elle seule : <c>kdocs://ouvrir/&lt;entier positif&gt;</c>. Tout le reste est
/// refusé avec une raison nommée : autre schéma, autre action, hôte ou port, informations utilisateur,
/// paramètres, fragment, segment supplémentaire, traversée de dossier, encodage pourcent, caractères de
/// contrôle ou non ASCII, identifiant nul, à zéro initial ou hors bornes. L'analyse ne touche ni le
/// réseau, ni le registre, ni le disque : elle est donc testable sans dépendance.
/// </summary>
public static class AnalyseUrlKdocs
{
    /// <summary>Schéma de l'application (minuscule, comme écrit sous HKCU\Software\Classes).</summary>
    public const string Schema = "kdocs";

    /// <summary>Longueur maximale d'une URL acceptée (la forme canonique en fait moins de 25).</summary>
    public const int LongueurMax = 64;

    /// <summary>Actions autorisées. Liste blanche : aucune autre valeur n'est interprétée.</summary>
    public static readonly IReadOnlySet<string> ActionsAutorisees =
        new HashSet<string>(StringComparer.Ordinal) { "ouvrir" };

    // Ancré \A...\z (jamais $, qui tolère un saut de ligne final) : la forme entière doit tenir.
    private static readonly Regex Forme = new(
        @"\Akdocs://(?<action>[A-Za-z][A-Za-z0-9_-]{0,31})/(?<id>[0-9]{1,10})\z",
        RegexOptions.Compiled | RegexOptions.CultureInvariant | RegexOptions.IgnoreCase);

    /// <summary>
    /// Analyse une URL brute telle que Windows la transmet. Ne lève jamais : rend un refus nommé.
    /// </summary>
    /// <param name="url">Lien reçu (peut être nul ou vide).</param>
    public static ResultatUrl Analyser(string? url)
    {
        if (string.IsNullOrEmpty(url))
        {
            return ResultatUrl.Refuser("URL absente");
        }
        if (url.Length > LongueurMax)
        {
            return ResultatUrl.Refuser($"URL trop longue ({url.Length} caractères, maximum {LongueurMax})");
        }
        if (!url.StartsWith(Schema + "://", StringComparison.OrdinalIgnoreCase))
        {
            return ResultatUrl.Refuser("schéma attendu : kdocs://");
        }

        var correspondance = Forme.Match(url);
        if (!correspondance.Success)
        {
            return ResultatUrl.Refuser("forme attendue : kdocs://ouvrir/<identifiant numérique>");
        }

        var action = correspondance.Groups["action"].Value.ToLowerInvariant();
        if (!ActionsAutorisees.Contains(action))
        {
            return ResultatUrl.Refuser($"action refusée : {action}");
        }

        var identifiant = correspondance.Groups["id"].Value;
        if (identifiant.Length > 1 && identifiant[0] == '0')
        {
            return ResultatUrl.Refuser("identifiant à zéro initial");
        }
        if (!int.TryParse(identifiant, NumberStyles.None, CultureInfo.InvariantCulture, out var document) || document < 1)
        {
            return ResultatUrl.Refuser($"identifiant hors bornes : {identifiant}");
        }

        return ResultatUrl.Accepter(new UrlKdocs(action, document));
    }
}
