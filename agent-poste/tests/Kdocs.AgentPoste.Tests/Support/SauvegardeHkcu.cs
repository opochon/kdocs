using Microsoft.Win32;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>
/// Photographie une clé HKCU (sous-clés, valeurs et genres, récursivement) et la restitue À L'IDENTIQUE,
/// que le test réussisse ou échoue (Dispose). Les oracles qui touchent la VRAIE ruche — le parcours F13
/// enregistre <c>HKCU\Software\Classes\kdocs</c> — ne doivent rien laisser derrière eux, exactement comme
/// <c>agent-poste/tools/tester-installateur.ps1</c> le fait côté PowerShell (export avant, import après).
/// </summary>
public sealed class SauvegardeHkcu : IDisposable
{
    /// <summary>Sépare le chemin de sous-clé du nom de valeur (un nom de valeur peut contenir '\').</summary>
    private const char Separateur = '\u0000';

    private readonly string _sousChemin;
    private readonly bool _existait;
    private readonly Dictionary<string, ValeurHkcu> _valeurs = new(StringComparer.OrdinalIgnoreCase);
    private readonly HashSet<string> _sousCles = new(StringComparer.OrdinalIgnoreCase);

    public SauvegardeHkcu(string sousChemin)
    {
        _sousChemin = sousChemin;
        using var cle = Registry.CurrentUser.OpenSubKey(sousChemin);
        _existait = cle is not null;
        if (cle is not null)
        {
            Capturer(cle, string.Empty);
        }
    }

    public bool Existait => _existait;

    public void Dispose() => Restaurer();

    /// <summary>Rend la clé exactement telle qu'elle a été trouvée (supprimée si elle n'existait pas).</summary>
    public void Restaurer()
    {
        if (!_existait)
        {
            Registry.CurrentUser.DeleteSubKeyTree(_sousChemin, throwOnMissingSubKey: false);
            return;
        }

        using var racine = Registry.CurrentUser.CreateSubKey(_sousChemin, writable: true)
            ?? throw new InvalidOperationException($"SauvegardeHkcu : impossible de recréer HKCU\\{_sousChemin}");
        Restaurer(racine, string.Empty);
    }

    /// <summary>Vrai si la clé vivante est exactement celle photographiée : mêmes sous-clés, valeurs et genres.</summary>
    public bool EstIdentique()
    {
        using var cle = Registry.CurrentUser.OpenSubKey(_sousChemin);
        if ((cle is not null) != _existait)
        {
            return false;
        }

        return cle is null || Comparer(cle, string.Empty);
    }

    private void Capturer(RegistryKey cle, string relatif)
    {
        _sousCles.Add(relatif);
        foreach (var nom in cle.GetValueNames())
        {
            _valeurs[CleValeur(relatif, nom)] = new ValeurHkcu(
                cle.GetValue(nom, null, RegistryValueOptions.DoNotExpandEnvironmentNames) ?? string.Empty,
                cle.GetValueKind(nom));
        }

        foreach (var nom in cle.GetSubKeyNames())
        {
            using var sous = cle.OpenSubKey(nom);
            if (sous is not null)
            {
                Capturer(sous, Enfant(relatif, nom));
            }
        }
    }

    private void Restaurer(RegistryKey cle, string relatif)
    {
        var sousClesAttendues = SousClesDe(relatif);
        foreach (var nom in cle.GetSubKeyNames())
        {
            if (!sousClesAttendues.Contains(nom))
            {
                cle.DeleteSubKeyTree(nom, throwOnMissingSubKey: false);
            }
        }

        var valeursAttendues = ValeursDe(relatif);
        foreach (var nom in cle.GetValueNames())
        {
            if (!valeursAttendues.ContainsKey(nom))
            {
                cle.DeleteValue(nom, throwOnMissingValue: false);
            }
        }

        foreach (var (nom, attendue) in valeursAttendues)
        {
            cle.SetValue(nom, attendue.Valeur, attendue.Genre);
        }

        foreach (var nom in sousClesAttendues)
        {
            using var sous = cle.CreateSubKey(nom, writable: true)
                ?? throw new InvalidOperationException(
                    $"SauvegardeHkcu : impossible de recréer HKCU\\{_sousChemin}\\{Enfant(relatif, nom)}");
            Restaurer(sous, Enfant(relatif, nom));
        }
    }

    private bool Comparer(RegistryKey cle, string relatif)
    {
        if (!MemesEnsembles(cle.GetSubKeyNames(), SousClesDe(relatif)))
        {
            return false;
        }

        var valeursAttendues = ValeursDe(relatif);
        if (!MemesEnsembles(cle.GetValueNames(), valeursAttendues.Keys))
        {
            return false;
        }

        foreach (var nom in cle.GetValueNames())
        {
            var attendue = valeursAttendues[nom];
            var vivante = cle.GetValue(nom, null, RegistryValueOptions.DoNotExpandEnvironmentNames);
            if (cle.GetValueKind(nom) != attendue.Genre || !ValeursEgaLes(attendue.Valeur, vivante))
            {
                return false;
            }
        }

        foreach (var nom in cle.GetSubKeyNames())
        {
            using var sous = cle.OpenSubKey(nom);
            if (sous is null || !Comparer(sous, Enfant(relatif, nom)))
            {
                return false;
            }
        }

        return true;
    }

    /// <summary>Sous-clés directes de <paramref name="relatif"/>, noms seuls.</summary>
    private IReadOnlyList<string> SousClesDe(string relatif) =>
        _sousCles.Where(s => !string.Equals(s, relatif, StringComparison.OrdinalIgnoreCase) && Parent(s) == relatif)
            .Select(NomSousCle)
            .ToList();

    /// <summary>Valeurs directes de <paramref name="relatif"/>, nom → valeur photographiée.</summary>
    private IReadOnlyDictionary<string, ValeurHkcu> ValeursDe(string relatif) =>
        _valeurs.Where(kv => SousCleDeValeur(kv.Key) == relatif)
            .ToDictionary(kv => NomValeur(kv.Key), kv => kv.Value, StringComparer.OrdinalIgnoreCase);

    private static bool MemesEnsembles(IEnumerable<string> gauche, IEnumerable<string> droite)
    {
        var g = gauche.OrderBy(x => x, StringComparer.OrdinalIgnoreCase).ToArray();
        var d = droite.OrderBy(x => x, StringComparer.OrdinalIgnoreCase).ToArray();

        return g.SequenceEqual(d, StringComparer.OrdinalIgnoreCase);
    }

    private static bool ValeursEgaLes(object? gauche, object? droite) => (gauche, droite) switch
    {
        (byte[] g, byte[] d) => g.AsSpan().SequenceEqual(d),
        _ => Equals(gauche, droite),
    };

    private static string Enfant(string relatif, string nom) =>
        relatif.Length == 0 ? nom : relatif + '\\' + nom;

    private static string Parent(string relatif)
    {
        var separateur = relatif.LastIndexOf('\\');

        return separateur < 0 ? string.Empty : relatif[..separateur];
    }

    private static string NomSousCle(string relatif)
    {
        var separateur = relatif.LastIndexOf('\\');

        return separateur < 0 ? relatif : relatif[(separateur + 1)..];
    }

    private static string CleValeur(string relatif, string nom) => relatif + Separateur + nom;

    private static string SousCleDeValeur(string cle) => cle[..cle.IndexOf(Separateur)];

    private static string NomValeur(string cle) => cle[(cle.IndexOf(Separateur) + 1)..];

    private sealed record ValeurHkcu(object Valeur, RegistryValueKind Genre);
}
