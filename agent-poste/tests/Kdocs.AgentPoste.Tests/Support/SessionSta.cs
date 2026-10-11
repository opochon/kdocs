namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>
/// Session COM sur un fil STA qui RESTE VIVANT pendant que le test interroge l'agent. La préparation
/// signale « prêt » ; le test travaille ; la fermeture (fermer Office) est rejouée dans tous les cas.
/// Sert aux oracles Office réels (Word, Excel, PowerPoint).
/// </summary>
public sealed class SessionSta : IDisposable
{
    private readonly ManualResetEventSlim _pret = new(false);
    private readonly ManualResetEventSlim _fermer = new(false);
    private readonly Thread _fil;
    private Exception? _erreur;

    public SessionSta(Action<Action> preparer, Action? terminer = null)
    {
        _fil = new Thread(() =>
        {
            try
            {
                preparer(() => _pret.Set());
                _fermer.Wait(TimeSpan.FromMinutes(5));
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
                    terminer?.Invoke();
                }
                catch (Exception)
                {
                    // La fermeture ne doit jamais masquer l'erreur d'origine ni faire échouer le test.
                }
            }
        })
        {
            IsBackground = true,
        };
        _fil.SetApartmentState(ApartmentState.STA);
        _fil.Start();
        _pret.Wait(TimeSpan.FromMinutes(2));
    }

    public void Verifier()
    {
        if (_erreur is not null)
        {
            throw new InvalidOperationException("session Office impossible", _erreur);
        }
    }

    public void Dispose()
    {
        _fermer.Set();
        _fil.Join(TimeSpan.FromMinutes(1));
    }

    public static object CreerApplication(string progId)
    {
        var type = Type.GetTypeFromProgID(progId)
            ?? throw new InvalidOperationException($"{progId} n’est pas installé sur ce poste");

        return Activator.CreateInstance(type)!;
    }

    /// <summary>
    /// Une application Office du nom donné est-elle DÉJÀ ouverte dans la session ? Sert à ne jamais
    /// quitter une instance de l'utilisateur : on ne ferme que ce qu'on a ouvert soi-même.
    /// </summary>
    public static bool OfficeDejaOuvert(string nom)
    {
        var present = false;
        Kdocs.AgentPoste.Com.DemarrageSta.Executer(() =>
        {
            foreach (var objet in Kdocs.AgentPoste.Com.Rot.ObjetsActifs())
            {
                try
                {
                    dynamic application = objet;
                    if (((string)application.Name).Contains(nom, StringComparison.OrdinalIgnoreCase))
                    {
                        present = true;

                        return;
                    }
                }
                catch (Exception)
                {
                }
            }
        });

        return present;
    }
}
