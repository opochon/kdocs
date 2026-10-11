using Kdocs.AgentPoste.Serveur;

namespace Kdocs.AgentPoste.Envoi;

/// <summary>Issue d'un essai d'envoi, pour que l'appelant mette à jour la fiche du document.</summary>
public sealed record ResultatEnvoi(EntreeFile Entree, bool Livre, bool Conflit, int Version, string? Message);

/// <summary>
/// File d'envoi persistante et sa reprise.
///
///   - un contenu n'est mis en file qu'une fois STABLE, et toujours sous forme d'INSTANTANÉ : le fichier
///     ouvert par l'utilisateur n'est jamais envoyé en cours d'écriture ;
///   - les entrées vivent sur disque : elles reprennent après une coupure réseau ou un redémarrage ;
///   - livraison dans l'ORDRE de mise en file ; après un envoi réussi, les entrées suivantes du même
///     document reprennent la version rendue par le serveur (historique linéaire, pas de faux conflit) ;
///   - un conflit signalé par le serveur n'écrase rien : le contenu est conservé en version parallèle ;
///   - l'instantané n'est effacé qu'APRÈS confirmation du serveur.
/// </summary>
public sealed class GestionnaireFileEnvoi
{
    private readonly IClientEdition _client;
    private readonly IDepotFile _depot;
    private readonly RacineAgent _racine;
    private readonly IFichierStable _stable;
    private readonly IHorloge _horloge;
    private readonly IJournal _journal;
    private readonly TimeSpan _delaiMaxStabilite;

    public GestionnaireFileEnvoi(
        IClientEdition client,
        IDepotFile depot,
        RacineAgent racine,
        IFichierStable stable,
        IHorloge horloge,
        IJournal journal,
        TimeSpan? delaiMaxStabilite = null)
    {
        _client = client;
        _depot = depot;
        _racine = racine;
        _stable = stable;
        _horloge = horloge;
        _journal = journal;
        _delaiMaxStabilite = delaiMaxStabilite ?? TimeSpan.FromSeconds(20);
    }

    /// <summary>Nombre d'entrées encore dans la file (affiché par « Mes documents en cours »).</summary>
    public int Compter() => _depot.Charger().Count;

    /// <summary>
    /// Fige un fichier stable en instantané et l'inscrit dans la file persistante. Rend l'entrée, ou
    /// null si le fichier n'est jamais devenu stable (rien n'est perdu : la prochaine sauvegarde repartira).
    /// </summary>
    public async Task<EntreeFile?> MettreEnFileAsync(
        int document,
        string cheminTravail,
        int versionBase,
        CancellationToken annulation = default)
    {
        if (!await _stable.AttendreAsync(cheminTravail, _delaiMaxStabilite, annulation).ConfigureAwait(false))
        {
            _journal.Avertissement(
                "file d’envoi",
                $"fichier jamais stable, mise en file reportée : {Path.GetFileName(cheminTravail)}");

            return null;
        }

        var id = Guid.NewGuid().ToString("N");
        var dossierInstantane = Path.Combine(DepotFileDisque.DossierInstantanes(_racine.DossierFile), id);
        Directory.CreateDirectory(dossierInstantane);
        var instantane = Path.Combine(dossierInstantane, RacineAgent.NomSain(Path.GetFileName(cheminTravail)));
        File.Copy(cheminTravail, instantane, overwrite: true);
        var empreinte = EmpreinteSha256.Instance.CalculerFichier(instantane);

        var entree = new EntreeFile
        {
            Id = id,
            Document = document,
            CheminTravail = cheminTravail,
            CheminInstantane = instantane,
            Empreinte = empreinte,
            VersionBase = versionBase,
            Etat = EtatEntree.EnAttente,
            CreeLeUtc = _horloge.MaintenantUtc,
        };
        _depot.Enregistrer(entree);
        _journal.Info("file d’envoi", $"document {document} mis en file (base v{versionBase}, {empreinte[..12]}…)");

        return entree;
    }

    /// <summary>Traite les entrées dues, dans l'ordre, et rend une issue par entrée examinée.</summary>
    public async Task<IReadOnlyList<ResultatEnvoi>> TraiterAsync(CancellationToken annulation = default)
    {
        var entrees = _depot.Charger()
            .OrderBy(e => e.CreeLeUtc)
            .ThenBy(e => e.Id, StringComparer.Ordinal)
            .ToList();
        var resultats = new List<ResultatEnvoi>();
        var versions = new Dictionary<int, int>();

        foreach (var entree in entrees)
        {
            annulation.ThrowIfCancellationRequested();
            if (entree.RefusDefinitif)
            {
                continue;
            }
            if (!File.Exists(entree.CheminInstantane))
            {
                // L'instantané a disparu : plus rien à envoyer, on retire la comptabilité interne.
                _depot.Supprimer(entree.Id);
                continue;
            }
            if (entree.Etat == EtatEntree.EnCours)
            {
                entree.Etat = EtatEntree.EnAttente;   // reprise après arrêt brutal
            }
            if (entree.ProchaineTentativeUtc > _horloge.MaintenantUtc)
            {
                continue;
            }

            var versionBase = versions.TryGetValue(entree.Document, out var connue) ? connue : entree.VersionBase;
            entree.Etat = EtatEntree.EnCours;
            entree.VersionBase = versionBase;
            _depot.Enregistrer(entree);

            try
            {
                var depot = await _client
                    .DeposerVersionAsync(entree.Document, entree.CheminInstantane, entree.Empreinte, versionBase, annulation)
                    .ConfigureAwait(false);
                if (depot.Version > 0)
                {
                    versions[entree.Document] = depot.Version;
                }

                var message = ConstruireMessage(depot, versionBase, entree);
                _depot.Supprimer(entree.Id);
                NettoyerInstantane(entree);
                _journal.Info(
                    "file d’envoi",
                    $"document {entree.Document} livré en version {depot.Version}"
                    + (depot.Conflit ? " (conflit signalé, rien d’écrasé)" : string.Empty));
                resultats.Add(new ResultatEnvoi(entree, true, depot.Conflit, depot.Version, message));
            }
            catch (ErreurServeur erreur) when (erreur.EstRefusDefinitif)
            {
                entree.Etat = EtatEntree.Echec;
                entree.RefusDefinitif = true;
                entree.DerniereErreur = erreur.MessageUtilisateur;
                _depot.Enregistrer(entree);
                _journal.Avertissement("file d’envoi", $"document {entree.Document} refusé : {erreur.MessageUtilisateur}");
                resultats.Add(new ResultatEnvoi(entree, false, false, 0, erreur.MessageUtilisateur));
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                entree.Etat = EtatEntree.Echec;
                entree.Tentatives++;
                entree.DerniereErreur = exception.Message;
                entree.ProchaineTentativeUtc = _horloge.MaintenantUtc + Retard(entree.Tentatives);
                _depot.Enregistrer(entree);
                _journal.Avertissement(
                    "file d’envoi",
                    $"document {entree.Document} non livré (essai {entree.Tentatives}) : {exception.Message}");
                resultats.Add(new ResultatEnvoi(entree, false, false, 0, exception.Message));
            }
        }

        return resultats;
    }

    private static string? ConstruireMessage(ResultatDepot depot, int versionBase, EntreeFile entree)
    {
        if (depot.Conflit)
        {
            return $"version parallèle : contenu conservé (base v{versionBase}, dernière v{depot.VersionAvant}, nouvelle v{depot.Version})";
        }
        if (depot.Empreinte.Length > 0 && !string.Equals(depot.Empreinte, entree.Empreinte, StringComparison.OrdinalIgnoreCase))
        {
            return "le serveur a confirmé une empreinte différente de celle envoyée";
        }

        return null;
    }

    private static TimeSpan Retard(int tentatives)
    {
        var secondes = Math.Pow(2, Math.Min(tentatives, 8));

        return TimeSpan.FromSeconds(Math.Min(secondes, 300));
    }

    private void NettoyerInstantane(EntreeFile entree)
    {
        try
        {
            var dossier = Path.GetDirectoryName(entree.CheminInstantane);
            if (!string.IsNullOrEmpty(dossier) && Directory.Exists(dossier))
            {
                Directory.Delete(dossier, recursive: true);
            }
        }
        catch (IOException exception)
        {
            _journal.Avertissement("file d’envoi", $"instantané non nettoyé : {exception.Message}");
        }
        catch (UnauthorizedAccessException exception)
        {
            _journal.Avertissement("file d’envoi", $"instantané non nettoyé : {exception.Message}");
        }
    }
}
