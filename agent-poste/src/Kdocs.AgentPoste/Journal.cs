using System.Diagnostics;
using System.Text;

namespace Kdocs.AgentPoste;

/// <summary>Journal de l'agent : une catégorie (étape) et un message, jamais un jeton ni un secret.</summary>
public interface IJournal
{
    void Info(string categorie, string message);

    void Avertissement(string categorie, string message);

    void Erreur(string categorie, string message, Exception? erreur = null);
}

/// <summary>
/// Journal sur disque, un fichier par jour sous le dossier de l'agent. Il ne lève JAMAIS : un journal
/// qui fait tomber l'agent serait pire que pas de journal. En dernier recours, la ligne part dans le
/// débogueur (jamais un échec muet total).
/// </summary>
public sealed class JournalFichier : IJournal
{
    private readonly string _dossier;
    private readonly object _verrou = new();

    public JournalFichier(string dossier) => _dossier = dossier;

    public void Info(string categorie, string message) => Ecrire("INFO", categorie, message, null);

    public void Avertissement(string categorie, string message) => Ecrire("AVERTISSEMENT", categorie, message, null);

    public void Erreur(string categorie, string message, Exception? erreur = null) =>
        Ecrire("ERREUR", categorie, message, erreur);

    private void Ecrire(string niveau, string categorie, string message, Exception? erreur)
    {
        var ligne = string.Format(
            System.Globalization.CultureInfo.InvariantCulture,
            "{0:yyyy-MM-ddTHH:mm:sszzz}\t{1}\t{2}\t{3}{4}",
            DateTimeOffset.Now, niveau, categorie, message,
            erreur is null ? string.Empty : $"\t{erreur.GetType().Name}: {erreur.Message}");
        try
        {
            lock (_verrou)
            {
                Directory.CreateDirectory(_dossier);
                File.AppendAllText(
                    Path.Combine(_dossier, $"agent-{DateTime.Now:yyyyMMdd}.log"),
                    ligne + Environment.NewLine,
                    new UTF8Encoding(false));
            }
        }
        catch (Exception ex)
        {
            // Aucun échec muet : la ligne reste visible en débogage même si le disque refuse l'écriture.
            Debug.WriteLine($"[K-Docs journal indisponible] {ligne} : {ex.Message}");
        }
    }
}

/// <summary>Journal en mémoire : sert aux tests et aux passes sans disque.</summary>
public sealed class JournalMemoire : IJournal
{
    private readonly List<LigneJournal> _lignes = [];

    public IReadOnlyList<LigneJournal> Lignes
    {
        get
        {
            lock (_lignes)
            {
                return _lignes.ToArray();
            }
        }
    }

    public void Info(string categorie, string message) => Ajouter("INFO", categorie, message);

    public void Avertissement(string categorie, string message) => Ajouter("AVERTISSEMENT", categorie, message);

    public void Erreur(string categorie, string message, Exception? erreur = null) =>
        Ajouter("ERREUR", categorie, erreur is null ? message : $"{message} : {erreur.Message}");

    private void Ajouter(string niveau, string categorie, string message)
    {
        lock (_lignes)
        {
            _lignes.Add(new LigneJournal(niveau, categorie, message));
        }
    }
}

public sealed record LigneJournal(string Niveau, string Categorie, string Message);
