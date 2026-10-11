using System.IO.Pipes;

namespace Kdocs.AgentPoste.Application;

/// <summary>
/// Canal local entre l'instance qui gère le poste (l'icône) et un réveil par <c>kdocs://</c> : Windows
/// lance un nouveau processus pour chaque lien ; ce processus transmet le lien à l'instance unique, qui
/// seule surveille le dossier et entretient la file. Un seul agent, donc aucune double exécution.
/// </summary>
public static class CanalIpc
{
    public const string Nom = "Kdocs.AgentPoste";

    /// <summary>Transmet un message à l'instance unique. Rend false si aucune instance n'écoute.</summary>
    public static async Task<bool> EnvoyerAsync(string message, int delaiMs = 2000)
    {
        try
        {
            using var client = new NamedPipeClientStream(".", Nom, PipeDirection.Out);
            await client.ConnectAsync(delaiMs).ConfigureAwait(false);
            using var ecrivain = new StreamWriter(client) { AutoFlush = true };
            await ecrivain.WriteLineAsync(message).ConfigureAwait(false);

            return true;
        }
        catch (TimeoutException)
        {
            return false;
        }
        catch (IOException)
        {
            return false;
        }
    }
}

/// <summary>Serveur du canal : écoute en boucle et confie chaque message reçu au traitement.</summary>
public sealed class ServeurIpc : IDisposable
{
    private readonly Func<string, Task> _traiter;
    private readonly IJournal _journal;
    private readonly CancellationTokenSource _annulation = new();

    public ServeurIpc(Func<string, Task> traiter, IJournal journal)
    {
        _traiter = traiter;
        _journal = journal;
    }

    public void Demarrer() => _ = Task.Run(BouclerAsync);

    public void Dispose() => _annulation.Cancel();

    private async Task BouclerAsync()
    {
        while (!_annulation.IsCancellationRequested)
        {
            try
            {
                using var serveur = new NamedPipeServerStream(
                    CanalIpc.Nom, PipeDirection.In, 1, PipeTransmissionMode.Byte, PipeOptions.Asynchronous);
                await serveur.WaitForConnectionAsync(_annulation.Token).ConfigureAwait(false);
                using var lecteur = new StreamReader(serveur);
                var message = await lecteur.ReadLineAsync(_annulation.Token).ConfigureAwait(false);
                if (!string.IsNullOrWhiteSpace(message))
                {
                    await _traiter(message).ConfigureAwait(false);
                }
            }
            catch (OperationCanceledException)
            {
                break;
            }
            catch (IOException exception)
            {
                _journal.Avertissement("canal local", $"message non reçu : {exception.Message}");
            }
        }
    }
}
