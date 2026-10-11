namespace Kdocs.AgentPoste.Protocole;

/// <summary>
/// Enregistrement du protocole d'URL <c>kdocs://</c> sous <c>HKCU\Software\Classes\kdocs</c>, sans droits
/// administrateur (documentation Microsoft « Registering an Application to a URI Scheme »). Windows
/// appellera l'agent avec le lien en argument : <c>"…\Kdocs.AgentPoste.exe" "%1"</c>. Aucune valeur ne
/// vient d'une entrée utilisateur : le chemin est celui de l'exécutable courant.
/// </summary>
public sealed class ProtocoleKdocs
{
    public const string Cle = @"Software\Classes\kdocs";
    public const string NomProtocole = "URL:K-Docs Protocol";

    private readonly IRegistre _registre;

    public ProtocoleKdocs(IRegistre registre) => _registre = registre;

    public void Enregistrer(string cheminExecutable)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(cheminExecutable);
        _registre.Ecrire(Cle, string.Empty, NomProtocole);
        _registre.Ecrire(Cle, "URL Protocol", string.Empty);
        _registre.Ecrire(Cle + @"\shell\open\command", string.Empty, ConstruireCommande(cheminExecutable));
    }

    public bool EstEnregistre(out string? commande)
    {
        commande = _registre.Lire(Cle + @"\shell\open\command", string.Empty);

        return _registre.CleExiste(Cle) && !string.IsNullOrEmpty(commande);
    }

    public static string ConstruireCommande(string cheminExecutable) =>
        $"\"{cheminExecutable}\" \"%1\"";
}
