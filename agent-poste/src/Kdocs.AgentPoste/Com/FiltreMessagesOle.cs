using System.Runtime.InteropServices;

namespace Kdocs.AgentPoste.Com;

/// <summary>
/// Filtre de messages OLE (Microsoft : <c>IOleMessageFilter</c>, <c>CoRegisterMessageFilter</c>).
/// Office refuse parfois un appel quand une boîte de dialogue est ouverte ou qu'une cellule est en
/// cours de saisie : <c>RPC_E_CALL_REJECTED</c> ou <c>RPC_E_SERVERCALL_RETRYLATER</c>. Le filtre
/// demande à réessayer plus tard ; l'agent n'interrompt jamais l'utilisateur.
/// </summary>
[ComImport]
[Guid("00000016-0000-0000-C000-000000000046")]
[InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
internal interface IOleMessageFilter
{
    [PreserveSig]
    int HandleInComingCall(int typeAppel, IntPtr tacheAppelante, int tic, IntPtr infoInterface);

    [PreserveSig]
    int RetryRejectedCall(IntPtr tacheAppelee, int tic, int typeRejet);

    [PreserveSig]
    int MessagePending(IntPtr tacheAppelee, int tic, int typeAttente);
}

internal sealed class FiltreMessagesOle : IOleMessageFilter
{
    private const int ServerCallIsHandled = 0;
    private const int ServerCallRetryLater = 2;
    private const int PendingMessageWaitDefProcess = 0;
    private const int RetenterDansMs = 100;

    [DllImport("ole32.dll")]
    private static extern int CoRegisterMessageFilter(IOleMessageFilter? nouveau, out IOleMessageFilter? ancien);

    // Le filtre doit rester vivant tant qu'il est enregistré auprès de COM.
    private static FiltreMessagesOle? _enregistre;

    public static void Enregistrer()
    {
        _enregistre ??= new FiltreMessagesOle();
        _ = CoRegisterMessageFilter(_enregistre, out _);
    }

    public static void Retirer()
    {
        _ = CoRegisterMessageFilter(null, out _);
        _enregistre = null;
    }

    public int HandleInComingCall(int typeAppel, IntPtr tacheAppelante, int tic, IntPtr infoInterface) =>
        ServerCallIsHandled;

    public int RetryRejectedCall(IntPtr tacheAppelee, int tic, int typeRejet) =>
        typeRejet == ServerCallRetryLater ? RetenterDansMs : -1;

    public int MessagePending(IntPtr tacheAppelee, int tic, int typeAttente) => PendingMessageWaitDefProcess;
}

/// <summary>Exécute une opération COM sur un fil COM « appartement monothread » (STA).</summary>
internal static class DemarrageSta
{
    public static void Executer(Action action)
    {
        Exception? erreur = null;
        var fil = new Thread(() =>
        {
            try
            {
                FiltreMessagesOle.Enregistrer();
                action();
            }
            catch (Exception exception)
            {
                erreur = exception;
            }
            finally
            {
                FiltreMessagesOle.Retirer();
            }
        });
        fil.SetApartmentState(ApartmentState.STA);
        fil.IsBackground = true;
        fil.Start();
        fil.Join();

        if (erreur is not null)
        {
            System.Runtime.ExceptionServices.ExceptionDispatchInfo.Capture(erreur).Throw();
        }
    }
}
