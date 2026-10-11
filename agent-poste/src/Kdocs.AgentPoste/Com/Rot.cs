using System.Runtime.InteropServices;
using System.Runtime.InteropServices.ComTypes;

namespace Kdocs.AgentPoste.Com;

/// <summary>
/// Table des objets en cours d'exécution (ROT) : c'est le seul moyen, en .NET moderne, de s'attacher
/// aux instances Office DÉJÀ ouvertes par l'utilisateur (l'ancien <c>Marshal.GetActiveObject</c> n'est
/// plus pris en charge). On n'ouvre jamais une nouvelle instance : on lit celle de l'utilisateur.
/// </summary>
internal static class Rot
{
    [DllImport("ole32.dll")]
    private static extern int GetRunningObjectTable(int reserved, out IRunningObjectTable table);

    public static IReadOnlyList<object> ObjetsActifs()
    {
        var resultats = new List<object>();
        IRunningObjectTable? table = null;
        IEnumMoniker? enumMoniker = null;
        try
        {
            if (GetRunningObjectTable(0, out table) != 0 || table is null)
            {
                return resultats;
            }

            table.EnumRunning(out enumMoniker);
            if (enumMoniker is null)
            {
                return resultats;
            }

            var monikers = new IMoniker[1];
            while (enumMoniker.Next(1, monikers, IntPtr.Zero) == 0 && monikers[0] is not null)
            {
                try
                {
                    if (table.GetObject(monikers[0], out var objet) == 0 && objet is not null)
                    {
                        resultats.Add(objet);
                    }
                }
                catch (COMException)
                {
                    // Objet disparu entre l'énumération et la lecture : on l'ignore.
                }
                finally
                {
                    Marshal.ReleaseComObject(monikers[0]);
                    monikers[0] = null!;
                }
            }
        }
        catch (COMException)
        {
            return resultats;
        }
        finally
        {
            if (enumMoniker is not null)
            {
                Marshal.ReleaseComObject(enumMoniker);
            }
            if (table is not null)
            {
                Marshal.ReleaseComObject(table);
            }
        }

        return resultats;
    }
}
