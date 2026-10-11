using System.Collections.Concurrent;

namespace Kdocs.AgentPoste.Surveillance;

/// <summary>
/// Surveillance du dossier de travail : chaque écriture d'un document (Ctrl+S) doit devenir une version.
/// Un délai de stabilisation regroupe les écritures rapprochées (Word écrit par fichier temporaire puis
/// renommage) ; les fichiers techniques (.kdocs.json, ~$, .tmp, .part) sont ignorés.
/// </summary>
public interface ISurveillanceDossier : IDisposable
{
    event Action<string>? FichierModifie;

    void Demarrer();

    void Arreter();
}

public sealed class SurveillanceDossier : ISurveillanceDossier
{
    private readonly RacineAgent _racine;
    private readonly IJournal _journal;
    private readonly TimeSpan _delai;
    private readonly ConcurrentDictionary<string, DateTimeOffset> _enAttente = new(StringComparer.OrdinalIgnoreCase);
    private readonly System.Timers.Timer _minuteur;
    private FileSystemWatcher? _observateur;
    private bool _demarre;

    public SurveillanceDossier(RacineAgent racine, IJournal journal, TimeSpan? delai = null)
    {
        _racine = racine;
        _journal = journal;
        _delai = delai ?? TimeSpan.FromSeconds(1.5);
        _minuteur = new System.Timers.Timer(500) { AutoReset = true };
        _minuteur.Elapsed += (_, _) => Purger();
    }

    public event Action<string>? FichierModifie;

    public void Demarrer()
    {
        if (_demarre)
        {
            return;
        }

        Directory.CreateDirectory(_racine.DossierTravail);
        _observateur = new FileSystemWatcher(_racine.DossierTravail)
        {
            IncludeSubdirectories = true,
            NotifyFilter = NotifyFilters.LastWrite | NotifyFilters.FileName | NotifyFilters.Size,
            InternalBufferSize = 64 * 1024,
        };
        _observateur.Created += (_, e) => Noter(e.FullPath);
        _observateur.Changed += (_, e) => Noter(e.FullPath);
        _observateur.Renamed += (_, e) => Noter(e.FullPath);
        _observateur.Error += (_, e) => _journal.Avertissement("surveillance", $"observateur en erreur : {e.GetException().Message}");
        _observateur.EnableRaisingEvents = true;
        _minuteur.Start();
        _demarre = true;
        _journal.Info("surveillance", $"dossier de travail surveillé : {_racine.DossierTravail}");
    }

    public void Arreter()
    {
        if (!_demarre)
        {
            return;
        }

        _minuteur.Stop();
        if (_observateur is not null)
        {
            _observateur.EnableRaisingEvents = false;
            _observateur.Dispose();
            _observateur = null;
        }

        _demarre = false;
    }

    public void Dispose()
    {
        Arreter();
        _minuteur.Dispose();
    }

    /// <summary>Un chemin est-il un document de travail (et non un fichier technique de l'agent) ?</summary>
    public static bool EstDocumentDeTravail(string chemin)
    {
        var nom = Path.GetFileName(chemin);
        if (nom.EndsWith(RacineAgent.ExtensionFiche, StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }
        if (nom.StartsWith("~$", StringComparison.Ordinal) || nom.StartsWith(".", StringComparison.Ordinal))
        {
            return false;
        }

        var extension = Path.GetExtension(nom);
        if (extension.Equals(".tmp", StringComparison.OrdinalIgnoreCase)
            || extension.Equals(".part", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        return nom.Length > 0;
    }

    private void Noter(string chemin)
    {
        if (!EstDocumentDeTravail(chemin))
        {
            return;
        }

        _enAttente[chemin] = DateTimeOffset.UtcNow;
    }

    private void Purger()
    {
        var maintenant = DateTimeOffset.UtcNow;
        foreach (var (chemin, vu) in _enAttente)
        {
            if (maintenant - vu < _delai)
            {
                continue;
            }
            if (!_enAttente.TryRemove(chemin, out _))
            {
                continue;
            }
            if (!File.Exists(chemin))
            {
                continue;
            }

            try
            {
                FichierModifie?.Invoke(chemin);
            }
            catch (Exception exception)
            {
                _journal.Erreur("surveillance", $"traitement de {chemin} impossible", exception);
            }
        }
    }
}
