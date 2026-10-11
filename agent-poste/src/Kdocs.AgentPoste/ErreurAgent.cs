namespace Kdocs.AgentPoste;

/// <summary>
/// Erreur de l'agent : elle NOMME l'étape et la cause (règle « une erreur se nomme, jamais un 500 muet »).
/// Elle porte un message lisible par l'utilisateur.
/// </summary>
public class ErreurAgent : Exception
{
    public ErreurAgent(string etape, string cause)
        : base($"{etape} : {cause}")
    {
        Etape = etape;
        Cause = cause;
    }

    public ErreurAgent(string etape, string cause, Exception inner)
        : base($"{etape} : {cause}", inner)
    {
        Etape = etape;
        Cause = cause;
    }

    public string Etape { get; }

    public string Cause { get; }
}

/// <summary>Réponse d'erreur du serveur GEDv2 : statut HTTP, étape, cause et référence affichable.</summary>
public sealed class ErreurServeur : ErreurAgent
{
    public ErreurServeur(int statut, string etape, string cause, string? reference = null)
        : base(etape, cause)
    {
        Statut = statut;
        Reference = reference;
    }

    public int Statut { get; }

    public string? Reference { get; }

    /// <summary>Un 401/403 est un refus de droit : réessayer n'y changera rien (appareil révoqué p. ex.).</summary>
    public bool EstRefusDefinitif => Statut is 400 or 401 or 403 or 404 or 409;

    public string MessageUtilisateur => Reference is null || Reference.Length == 0
        ? $"{Etape} : {Cause}"
        : $"{Etape} : {Cause} (référence {Reference})";
}
