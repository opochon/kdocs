namespace Kdocs.AgentPoste.Travail;

/// <summary>
/// Fiche d'un document ouvert depuis la GED, écrite à côté de lui (<c>&lt;nom&gt;.kdocs.json</c>).
/// Elle retient l'adresse du serveur et l'empreinte de BASE : le premier téléchargement ne doit pas
/// être pris pour une modification. Elle suit aussi la dernière empreinte CONNUE (envoyée ou reçue) :
/// la surveillance ne déclenche un envoi que si le contenu s'en écarte.
/// </summary>
public sealed class FicheDocumentTravail
{
    public int Document { get; set; }

    public string Nom { get; set; } = string.Empty;

    public string Chemin { get; set; } = string.Empty;

    public string Serveur { get; set; } = string.Empty;

    /// <summary>Empreinte du contenu au moment de l'ouverture (celle du serveur).</summary>
    public string EmpreinteServeur { get; set; } = string.Empty;

    public int VersionServeur { get; set; }

    /// <summary>Dernière empreinte dont on sait qu'elle est connue du serveur.</summary>
    public string DerniereEmpreinteConnue { get; set; } = string.Empty;

    public int DerniereVersionConnue { get; set; }

    public DateTimeOffset OuvertLeUtc { get; set; }

    public DateTimeOffset? DernierEnvoiUtc { get; set; }

    /// <summary>La dernière livraison a été signalée en conflit (version parallèle créée côté serveur).</summary>
    public bool Conflit { get; set; }

    public DateTimeOffset? DernierConflitLeUtc { get; set; }

    public string? Message { get; set; }
}

/// <summary>Lecture des fiches de travail (une par document ouvert).</summary>
public interface IDepotFiches
{
    FicheDocumentTravail? Lire(int document);

    IReadOnlyList<FicheDocumentTravail> Lister();

    void Ecrire(FicheDocumentTravail fiche);
}
