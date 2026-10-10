namespace Kdocs.AgentPoste.Configuration;

/// <summary>
/// Configuration d'un poste enrôlé. L'adresse du serveur est FIXÉE à l'enrôlement : l'agent ne parle
/// qu'à ce serveur (un lien kdocs:// ne peut pas en désigner un autre). Le jeton d'appareil n'est pas
/// ici : il vit chiffré à part (DPAPI, voir <see cref="ICoffreJeton"/>).
/// </summary>
public sealed record ConfigurationPoste
{
    public required string AdresseServeur { get; init; }

    public required string Appareil { get; init; }

    public required string Compte { get; init; }

    public string? GedNom { get; init; }

    public DateTimeOffset EnroleLeUtc { get; init; }
}

/// <summary>Lecture/écriture de la configuration d'enrôlement.</summary>
public interface IDepotConfiguration
{
    ConfigurationPoste? Lire();

    void Ecrire(ConfigurationPoste configuration);
}
