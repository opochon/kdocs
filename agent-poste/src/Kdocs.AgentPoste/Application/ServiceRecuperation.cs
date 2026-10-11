using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// « Récupérer la copie de secours » : restaure la dernière copie locale dans le dossier de travail et
/// l'ouvre. Rien n'est supprimé ; rien n'est envoyé tant que l'utilisateur n'enregistre pas lui-même.
/// Le document de travail précédent n'est remplacé que si l'utilisateur le demande explicitement.
/// </summary>
public sealed class ServiceRecuperation
{
    private readonly ServiceDocumentsEnCours _documents;
    private readonly IDepotFiches _fiches;
    private readonly IOuvreurFichier _ouvreur;
    private readonly IJournal _journal;

    public ServiceRecuperation(
        ServiceDocumentsEnCours documents,
        IDepotFiches fiches,
        IOuvreurFichier ouvreur,
        IJournal journal)
    {
        _documents = documents;
        _fiches = fiches;
        _ouvreur = ouvreur;
        _journal = journal;
    }

    /// <summary>Restaure la dernière copie de secours et l'ouvre. Rend le chemin restauré, ou null.</summary>
    public string? Recuperer(int document)
    {
        var copie = _documents.DerniereCopie(document);
        if (copie is null)
        {
            _journal.Avertissement("récupération", $"document {document} : aucune copie de secours disponible");

            return null;
        }

        var fiche = _fiches.Lire(document);
        if (fiche is null || string.IsNullOrEmpty(fiche.Chemin))
        {
            _journal.Avertissement("récupération", $"document {document} : fiche de travail introuvable");

            return null;
        }

        // Liste blanche : une fiche héritée d'un format non éditable (installée avant le durcissement)
        // ne doit pas rouvrir un exécutable ou un script par le shell.
        if (!ExtensionsEditables.Autorisee(fiche.Chemin))
        {
            _journal.Avertissement(
                "récupération",
                $"document {document} : format non éditable, copie de secours non ouverte ({Path.GetFileName(fiche.Chemin)})");

            return null;
        }

        var dossier = Path.GetDirectoryName(fiche.Chemin);
        if (!string.IsNullOrEmpty(dossier))
        {
            Directory.CreateDirectory(dossier);
        }

        File.Copy(copie.Value.Chemin, fiche.Chemin, overwrite: true);
        fiche.DerniereEmpreinteConnue = EmpreinteSha256.Instance.CalculerFichier(fiche.Chemin);
        fiche.Message = $"copie de secours de {copie.Value.Quand:HH:mm} récupérée";
        _fiches.Ecrire(fiche);
        _ouvreur.Ouvrir(fiche.Chemin);
        _journal.Info(
            "récupération",
            $"document {document} : copie de secours de {copie.Value.Quand:HH:mm} restaurée dans {fiche.Chemin}");

        return fiche.Chemin;
    }
}
