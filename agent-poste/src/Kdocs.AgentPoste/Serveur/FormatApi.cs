using System.Text.Json;

namespace Kdocs.AgentPoste.Serveur;

/// <summary>
/// Lecture des réponses de l'API GEDv2. Le serveur nomme toujours ses erreurs sous la forme
/// <c>{"erreur":{"etape":…,"cause":…,"reference":…}}</c> ; on la reprend telle quelle, sinon on nomme
/// une cause de repli. Jamais une exception avalée sans trace : l'appelant journalise.
/// </summary>
internal static class FormatApi
{
    public static ErreurServeur LireErreur(int statut, string etapeParDefaut, string corps)
    {
        try
        {
            using var document = JsonDocument.Parse(corps);
            if (document.RootElement.ValueKind == JsonValueKind.Object
                && document.RootElement.TryGetProperty("erreur", out var erreur)
                && erreur.ValueKind == JsonValueKind.Object)
            {
                return new ErreurServeur(
                    statut,
                    LireChaine(erreur, "etape") ?? etapeParDefaut,
                    LireChaine(erreur, "cause") ?? "cause inconnue",
                    LireChaine(erreur, "reference"));
            }
        }
        catch (JsonException)
        {
            // Corps non JSON : on retombe sur la cause générique ci-dessous.
        }

        return new ErreurServeur(statut, etapeParDefaut, $"réponse HTTP {statut} sans cause lisible");
    }

    public static string? LireChaine(JsonElement objet, string propriete) =>
        objet.TryGetProperty(propriete, out var valeur) && valeur.ValueKind == JsonValueKind.String
            ? valeur.GetString()
            : null;

    public static int? LireEntier(JsonElement objet, string propriete) =>
        objet.TryGetProperty(propriete, out var valeur) && valeur.ValueKind == JsonValueKind.Number
            && valeur.TryGetInt32(out var entier)
                ? entier
                : null;

    public static bool? LireBooleen(JsonElement objet, string propriete) =>
        objet.TryGetProperty(propriete, out var valeur)
        && valeur.ValueKind is JsonValueKind.True or JsonValueKind.False
            ? valeur.GetBoolean()
            : null;
}
