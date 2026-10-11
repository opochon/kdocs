namespace Kdocs.AgentPoste.Envoi;

/// <summary>
/// Attend qu'un fichier soit STABLE avant de l'envoyer : Word enregistre par fichier temporaire puis
/// renommage, et l'agent ne doit jamais figer un contenu en cours d'écriture. La stabilité se prouve par
/// une taille et une date de modification inchangées sur plusieurs relevés, et un fichier lisible.
/// </summary>
public interface IFichierStable
{
    Task<bool> AttendreAsync(string chemin, TimeSpan delaiMax, CancellationToken annulation = default);
}

public sealed class FichierStableDisque : IFichierStable
{
    private readonly TimeSpan _intervalle;
    private readonly int _relevesStables;

    public FichierStableDisque(TimeSpan? intervalle = null, int relevesStables = 3)
    {
        _intervalle = intervalle ?? TimeSpan.FromMilliseconds(700);
        _relevesStables = Math.Max(2, relevesStables);
    }

    public async Task<bool> AttendreAsync(string chemin, TimeSpan delaiMax, CancellationToken annulation = default)
    {
        var fin = DateTime.UtcNow + delaiMax;
        (long Taille, DateTime Modifie) precedent = default;
        var stables = 0;
        var premier = true;

        while (DateTime.UtcNow < fin)
        {
            annulation.ThrowIfCancellationRequested();
            if (LireEtat(chemin, out var etat))
            {
                if (!premier && precedent.Taille == etat.Taille && precedent.Modifie == etat.Modifie)
                {
                    stables++;
                    if (stables >= _relevesStables)
                    {
                        return true;
                    }
                }
                else
                {
                    stables = 0;
                }

                precedent = etat;
                premier = false;
            }
            else
            {
                premier = true;
                stables = 0;
            }

            await Task.Delay(_intervalle, annulation).ConfigureAwait(false);
        }

        return false;
    }

    private static bool LireEtat(string chemin, out (long Taille, DateTime Modifie) etat)
    {
        etat = default;
        try
        {
            var info = new FileInfo(chemin);
            if (!info.Exists || info.Length == 0)
            {
                return false;
            }

            // Lisible en partage : on ne s'oppose pas à Word, mais un fichier verrouillé exclusivement
            // (écriture en cours) est refusé.
            using (new FileStream(chemin, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
            {
            }

            etat = (info.Length, info.LastWriteTimeUtc);

            return true;
        }
        catch (IOException)
        {
            return false;
        }
        catch (UnauthorizedAccessException)
        {
            return false;
        }
    }
}
