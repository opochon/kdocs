using Kdocs.AgentPoste.Com;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>Décorateur qui délègue tous les gestes à un vrai client : base des simulations de panne.</summary>
public abstract class DecorateurClientEdition : IClientEdition
{
    protected DecorateurClientEdition(IClientEdition reel) => Reel = reel;

    protected IClientEdition Reel { get; }

    public virtual Task<EtatDocument> EtatAsync(int document, CancellationToken annulation = default) =>
        Reel.EtatAsync(document, annulation);

    public virtual Task<FichierTelecharge> TelechargerAsync(int document, string cheminDestination, CancellationToken annulation = default) =>
        Reel.TelechargerAsync(document, cheminDestination, annulation);

    public virtual Task<ResultatDepot> DeposerVersionAsync(int document, string cheminFichier, string empreinte, int versionBase, CancellationToken annulation = default) =>
        Reel.DeposerVersionAsync(document, cheminFichier, empreinte, versionBase, annulation);

    public virtual Task OuvrirAsync(int document, CancellationToken annulation = default) => Reel.OuvrirAsync(document, annulation);

    public virtual Task<bool> SignalerAsync(int document, CancellationToken annulation = default) => Reel.SignalerAsync(document, annulation);

    public virtual Task FermerAsync(int document, CancellationToken annulation = default) => Reel.FermerAsync(document, annulation);

    public virtual Task<MetaSecours> DeposerSecoursAsync(int document, string cheminFichier, CancellationToken annulation = default) =>
        Reel.DeposerSecoursAsync(document, cheminFichier, annulation);

    public virtual Task<IReadOnlyList<MetaSecours>> ListerSecoursAsync(int document, CancellationToken annulation = default) =>
        Reel.ListerSecoursAsync(document, annulation);
}

/// <summary>Simule une coupure réseau : les dépôts échouent tant que la panne est active.</summary>
public sealed class ClientCoupure : DecorateurClientEdition
{
    public ClientCoupure(IClientEdition reel)
        : base(reel)
    {
    }

    public bool EnPanne { get; set; }

    public override Task<ResultatDepot> DeposerVersionAsync(
        int document,
        string cheminFichier,
        string empreinte,
        int versionBase,
        CancellationToken annulation = default)
    {
        if (EnPanne)
        {
            throw new HttpRequestException("réseau coupé (simulation du parcours F13)");
        }

        return base.DeposerVersionAsync(document, cheminFichier, empreinte, versionBase, annulation);
    }
}

/// <summary>Note ce qui est réellement déposé (document, version de base) et délègue au vrai client.</summary>
public sealed class ClientEnregistreur : DecorateurClientEdition
{
    public ClientEnregistreur(IClientEdition reel)
        : base(reel)
    {
    }

    public List<(int Document, int VersionBase)> Depots { get; } = [];

    public override Task<ResultatDepot> DeposerVersionAsync(
        int document,
        string cheminFichier,
        string empreinte,
        int versionBase,
        CancellationToken annulation = default)
    {
        Depots.Add((document, versionBase));

        return base.DeposerVersionAsync(document, cheminFichier, empreinte, versionBase, annulation);
    }
}

/// <summary>Gestes Word réels hors d'une session gérée : fermer le document ouvert par l'association.</summary>
public static class WordReel
{
    public static bool EstOuvert(string chemin)
    {
        var trouve = false;
        DemarrageSta.Executer(() =>
        {
            foreach (var application in Rot.ObjetsActifs())
            {
                try
                {
                    dynamic app = application;
                    if (!((string)app.Name).Contains("Word", StringComparison.OrdinalIgnoreCase))
                    {
                        continue;
                    }

                    foreach (dynamic document in app.Documents)
                    {
                        if (string.Equals((string)document.FullName, chemin, StringComparison.OrdinalIgnoreCase))
                        {
                            trouve = true;

                            return;
                        }
                    }
                }
                catch (Exception)
                {
                    // Objet ROT qui n'est pas Word : ignoré.
                }
            }
        });

        return trouve;
    }

    public static bool EstEnregistre(string chemin)
    {
        var enregistre = false;
        ExecuterSurDocument(chemin, document => enregistre = (bool)document.Saved);

        return enregistre;
    }

    public static void Modifier(string chemin, Action<dynamic> action) =>
        ExecuterSurDocument(chemin, action);

    public static void Fermer(string chemin) =>
        ExecuterSurDocument(chemin, document => document.Close(0));

    /// <summary>Exécute une action sur le document Word repéré par son chemin, via la table des objets.</summary>
    private static void ExecuterSurDocument(string chemin, Action<dynamic> action)
    {
        var trouve = false;
        DemarrageSta.Executer(() =>
        {
            foreach (var application in Rot.ObjetsActifs())
            {
                try
                {
                    dynamic app = application;
                    if (!((string)app.Name).Contains("Word", StringComparison.OrdinalIgnoreCase))
                    {
                        continue;
                    }

                    foreach (dynamic document in app.Documents)
                    {
                        if (!string.Equals((string)document.FullName, chemin, StringComparison.OrdinalIgnoreCase))
                        {
                            continue;
                        }

                        action(document);
                        trouve = true;

                        return;
                    }
                }
                catch (Exception)
                {
                }
            }
        });

        if (!trouve)
        {
            throw new ErreurAgent("test", $"document Word introuvable dans une session ouverte : {Path.GetFileName(chemin)}");
        }
    }

    /// <summary>
    /// Ferme UNIQUEMENT les documents Word dont le chemin appartient aux racines temporaires des tests
    /// (`kdocs-tests-…`) : aucune chance de toucher un document de l'utilisateur. À appeler avant chaque
    /// session Word, pour qu'une passe rouge laisse un poste propre.
    /// </summary>
    public static void NettoyerTemporaires()
    {
        DemarrageSta.Executer(() =>
        {
            foreach (var application in Rot.ObjetsActifs())
            {
                try
                {
                    dynamic app = application;
                    if (!((string)app.Name).Contains("Word", StringComparison.OrdinalIgnoreCase))
                    {
                        continue;
                    }

                    var aFermer = new List<object>();
                    foreach (dynamic document in app.Documents)
                    {
                        var chemin = (string)document.FullName;
                        if (chemin.Contains("kdocs-tests-", StringComparison.OrdinalIgnoreCase))
                        {
                            aFermer.Add(document);
                        }
                    }

                    foreach (var document in aFermer)
                    {
                        try
                        {
                            ((dynamic)document).Close(0);
                        }
                        catch (Exception)
                        {
                        }
                    }
                }
                catch (Exception)
                {
                }
            }
        });
    }
}
