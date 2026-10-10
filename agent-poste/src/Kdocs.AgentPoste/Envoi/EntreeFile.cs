namespace Kdocs.AgentPoste.Envoi;

/// <summary>État d'une entrée de la file d'envoi. Un envoi réussi SORT de la file (l'instant contenu est effacé).</summary>
public enum EtatEntree
{
    EnAttente,
    EnCours,

    /// <summary>Dernier essai refusé : refus définitif, ou en attente d'une reprise (voir ProchaineTentativeUtc).</summary>
    Echec,
}

/// <summary>
/// Un envoi en attente : le contenu a été figé dans un INSTANTANÉ (copie du fichier stable) pour ne
/// jamais envoyer un fichier que Word est en train d'écrire. La file est persistée sur disque : elle
/// reprend après une coupure réseau ou un redémarrage.
/// </summary>
public sealed class EntreeFile
{
    public string Id { get; set; } = string.Empty;

    public int Document { get; set; }

    public string CheminTravail { get; set; } = string.Empty;

    /// <summary>Copie figée du contenu à envoyer (jamais le fichier ouvert par l'utilisateur).</summary>
    public string CheminInstantane { get; set; } = string.Empty;

    public string Empreinte { get; set; } = string.Empty;

    /// <summary>Version de base au moment de la mise en file (relignée après un envoi réussi du même document).</summary>
    public int VersionBase { get; set; }

    public EtatEntree Etat { get; set; } = EtatEntree.EnAttente;

    public int Tentatives { get; set; }

    public DateTimeOffset ProchaineTentativeUtc { get; set; }

    public string? DerniereErreur { get; set; }

    /// <summary>Vrai quand le refus est définitif (400/401/403/404) : on ne réessaie plus tout seul.</summary>
    public bool RefusDefinitif { get; set; }

    public DateTimeOffset CreeLeUtc { get; set; }
}
