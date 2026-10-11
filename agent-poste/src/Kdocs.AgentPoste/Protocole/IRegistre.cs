using Microsoft.Win32;

namespace Kdocs.AgentPoste.Protocole;

/// <summary>Accès minimal au registre, abstrait pour être testable sans toucher à la vraie ruche.</summary>
public interface IRegistre
{
    void Ecrire(string sousChemin, string nom, string valeur);

    string? Lire(string sousChemin, string nom);

    bool CleExiste(string sousChemin);
}

/// <summary>Registre de l'utilisateur courant (HKCU) : aucun droit administrateur n'est requis.</summary>
public sealed class RegistreHkcu : IRegistre
{
    public void Ecrire(string sousChemin, string nom, string valeur)
    {
        using var cle = Registry.CurrentUser.CreateSubKey(sousChemin, writable: true)
            ?? throw new ErreurAgent("enregistrement du protocole", $"clé HKCU\\{sousChemin} non créable");

        cle.SetValue(nom, valeur, RegistryValueKind.String);
    }

    public string? Lire(string sousChemin, string nom)
    {
        using var cle = Registry.CurrentUser.OpenSubKey(sousChemin);
        var valeur = cle?.GetValue(nom);

        return valeur as string;
    }

    public bool CleExiste(string sousChemin)
    {
        using var cle = Registry.CurrentUser.OpenSubKey(sousChemin);

        return cle is not null;
    }
}

/// <summary>Registre en mémoire des tests et des simulations.</summary>
public sealed class RegistreMemoire : IRegistre
{
    private readonly Dictionary<string, Dictionary<string, string>> _cles = new(StringComparer.OrdinalIgnoreCase);

    public void Ecrire(string sousChemin, string nom, string valeur)
    {
        if (!_cles.TryGetValue(sousChemin, out var valeurs))
        {
            valeurs = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            _cles[sousChemin] = valeurs;
        }

        valeurs[nom] = valeur;
    }

    public string? Lire(string sousChemin, string nom) =>
        _cles.TryGetValue(sousChemin, out var valeurs) && valeurs.TryGetValue(nom, out var valeur) ? valeur : null;

    public bool CleExiste(string sousChemin) => _cles.ContainsKey(sousChemin);
}
