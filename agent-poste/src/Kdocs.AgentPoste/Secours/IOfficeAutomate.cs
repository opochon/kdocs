namespace Kdocs.AgentPoste.Secours;

public enum TypeOffice
{
    Word,
    Excel,
    PowerPoint,
}

/// <summary>Document ouvert dans Office : son type, son chemin local, et s'il a des modifications non enregistrées.</summary>
public sealed record DocumentOffice(TypeOffice Type, string Chemin, bool Enregistre, string Nom);

/// <summary>
/// Attachement à Office par son interface d'automatisation (COM), côté session utilisateur. On LIT
/// l'état des documents ouverts ; on n'ouvre jamais de nouvelle instance Office.
/// </summary>
public interface IOfficeAutomate
{
    IReadOnlyList<DocumentOffice> Lister();

    /// <summary>Écrit une copie de secours SANS toucher au document de l'utilisateur.</summary>
    void EcrireCopieSecours(DocumentOffice document, string destination);
}
