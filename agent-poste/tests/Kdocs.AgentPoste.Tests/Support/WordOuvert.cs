using System.Collections.Concurrent;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>
/// Un document ouvert dans Word réel, sur un fil STA dédié qui reste vivant : le test lui envoie des
/// actions (modifier, enregistrer…) et lit son état sans jamais partager le pointeur COM entre fils.
/// La fermeture de Word est rejouée dans tous les cas.
/// </summary>
public sealed class WordOuvert : IDisposable
{
    private readonly BlockingCollection<Action> _travaux = new();
    private readonly ManualResetEventSlim _pret = new(false);
    private readonly Thread _fil;
    private Exception? _erreur;
    private dynamic? _document;

    public WordOuvert(string chemin)
    {
        _fil = new Thread(() =>
        {
            dynamic? word = null;
            try
            {
                word = SessionSta.CreerApplication("Word.Application");
                word.Visible = false;
                word.DisplayAlerts = 0;
                _document = word.Documents.Open(chemin);
                _pret.Set();
                foreach (var travail in _travaux.GetConsumingEnumerable())
                {
                    travail();
                }
            }
            catch (Exception exception)
            {
                _erreur = exception;
                _pret.Set();
            }
            finally
            {
                try
                {
                    _document?.Close(0);
                }
                catch (Exception)
                {
                    // Le document a pu être fermé par le test : on n'échoue pas là-dessus.
                }
                try
                {
                    word?.Quit();
                }
                catch (Exception)
                {
                }
            }
        })
        {
            IsBackground = true,
        };
        _fil.SetApartmentState(ApartmentState.STA);
        _fil.Start();
        _pret.Wait(TimeSpan.FromMinutes(2));
        if (_erreur is not null)
        {
            throw new InvalidOperationException("ouverture du document dans Word impossible", _erreur);
        }
    }

    /// <summary>
    /// Enregistre le document dans Word (l'équivalent d'un Ctrl+S). Word 16 lève parfois une exception de
    /// convertisseur tout en écrivant réellement le fichier : on l'absorbe, l'appelant vérifie le contenu.
    /// </summary>
    public void Enregistrer() => Executer(doc =>
    {
        try
        {
            ((dynamic)doc).Save();
        }
        catch (System.Runtime.InteropServices.COMException)
        {
            // Enregistrement effectué malgré l'exception de convertisseur (constaté sur Word 16).
        }
    });

    /// <summary>Exécute une action sur le document dans le fil STA de Word.</summary>
    public void Executer(Action<dynamic> action)
    {
        Exception? erreur = null;
        using var fini = new ManualResetEventSlim(false);
        _travaux.Add(() =>
        {
            try
            {
                action(_document!);
            }
            catch (Exception exception)
            {
                erreur = exception;
            }
            finally
            {
                fini.Set();
            }
        });
        fini.Wait(TimeSpan.FromMinutes(2));
        if (erreur is not null)
        {
            throw new InvalidOperationException("action Word impossible", erreur);
        }
    }

    public void Dispose()
    {
        _travaux.CompleteAdding();
        _fil.Join(TimeSpan.FromMinutes(1));
        _travaux.Dispose();
        _pret.Dispose();
    }
}
