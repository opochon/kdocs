using System.Net;
using System.Text.Json;
using Kdocs.AgentPoste.Application;
using Kdocs.AgentPoste.Configuration;
using Kdocs.AgentPoste.Envoi;
using Kdocs.AgentPoste.Protocole;
using Kdocs.AgentPoste.Secours;
using Kdocs.AgentPoste.Serveur;
using Kdocs.AgentPoste.Tests.Support;
using Kdocs.AgentPoste.Travail;

namespace Kdocs.AgentPoste.Tests.F13;

/// <summary>
/// PARCOURS F13 complet de l'agent local K-Docs, contre le VRAI serveur de test (base `gedv2_test`) et
/// Word/Excel/PowerPoint réels. Chaque test couvre un des points du cahier des charges
/// (`docs/secteurs/edition-poste.md`) et n'assène que sur ce qui est réellement passé par le réseau.
/// </summary>
[Collection("F13")]
[Trait("Categorie", "F13")]
public sealed class ParcoursF13Tests
{
    private readonly ParcoursF13Fixture _f;

    public ParcoursF13Tests(ParcoursF13Fixture fixture) => _f = fixture;

    private static GestionnaireFileEnvoi FileRapide(IClientEdition client, RacineAgent racine, IJournal journal) =>
        new(client, new DepotFileDisque(racine.DossierFile), racine,
            new FichierStableDisque(TimeSpan.FromMilliseconds(150), 3), HorlogeSysteme.Instance, journal);

    private static CoordinateurEnvoi Coordinateur(IClientEdition client, RacineAgent racine, IDepotFiches fiches, IJournal journal) =>
        new(fiches, FileRapide(client, racine, journal), EmpreinteSha256.Instance, HorlogeSysteme.Instance, journal);

    /// <summary>Attend qu'une condition devienne vraie (Office met quelques secondes à ouvrir un document).</summary>
    private static bool Attendre(Func<bool> condition, TimeSpan delai)
    {
        var fin = DateTime.UtcNow + delai;
        while (DateTime.UtcNow < fin)
        {
            if (condition())
            {
                return true;
            }

            Thread.Sleep(500);
        }

        return condition();
    }

    /// <summary>Attend qu'Office enregistre le document dans la table des objets (quelques secondes).</summary>
    private static List<DocumentOffice> AttendreDocuments(OfficeComAutomate automate, string chemin, TimeSpan delai)
    {
        var fin = DateTime.UtcNow + delai;
        var vus = new List<DocumentOffice>();
        do
        {
            vus = automate.Lister()
                .Where(d => string.Equals(d.Chemin, chemin, StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (vus.Count > 0)
            {
                return vus;
            }

            Thread.Sleep(500);
        }
        while (DateTime.UtcNow < fin);

        return vus;
    }

    [Fact]
    public async Task Point1_l_enrolement_consomme_un_appairage_existant()
    {
        var racine = new RacineAgent(_f.RacineEnrolement.Chemin);
        var coffre = new CoffreJetonDpapi(racine.FichierJeton);
        var enroleur = new EnroleurHttp(
            _f.Http,
            new DepotConfigurationFichier(racine.FichierConfiguration),
            coffre,
            HorlogeSysteme.Instance,
            _f.Journal);

        var configuration = await enroleur.EnrollerAsync(_f.Serveur, _f.CodeAppairage, _f.VerificationAppairage, _f.Poste);

        Assert.Equal(_f.Serveur, configuration.AdresseServeur);
        Assert.Equal(_f.Manager, configuration.Compte);
        Assert.Equal(_f.Poste, configuration.Appareil);
        Assert.True(coffre.Existe);
        var jeton = coffre.Lire()!;
        var (statut, corps) = await _f.AppelerAsync(jeton, HttpMethod.Get, "/api/v1/appairage/moi");
        Assert.Equal(200, statut);
        Assert.Equal(_f.Manager, corps!.Value.GetProperty("compte").GetString());
    }

    [Fact]
    public void Point2_le_protocole_kdocs_est_enregistre_sous_HKCU()
    {
        var executable = Path.Combine(AppContext.BaseDirectory, "Kdocs.AgentPoste.exe");
        Assert.True(File.Exists(executable), $"exécutable de l’agent attendu : {executable}");

        // Ce test écrit dans la VRAIE ruche : il rend l'état HKCU qu'il a trouvé, même s'il échoue
        // (libération sur exception), comme `tester-installateur.ps1` le fait côté PowerShell.
        using var sauvegarde = new SauvegardeHkcu(ProtocoleKdocs.Cle);

        var protocole = new ProtocoleKdocs(new RegistreHkcu());
        protocole.Enregistrer(executable);

        Assert.True(protocole.EstEnregistre(out var commande));
        Assert.Equal($"\"{executable}\" \"%1\"", commande);
        using (var cle = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(ProtocoleKdocs.Cle))
        {
            Assert.NotNull(cle);
            Assert.Equal("URL:K-Docs Protocol", cle!.GetValue(string.Empty) as string);
            Assert.Equal(string.Empty, cle.GetValue("URL Protocol") as string);
        }
        using (var commandeCle = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(ProtocoleKdocs.Cle + @"\shell\open\command"))
        {
            Assert.NotNull(commandeCle);
            Assert.Equal($"\"{executable}\" \"%1\"", commandeCle!.GetValue(string.Empty) as string);
        }

        // Oracle de l'hygiène : la clé est redevenue EXACTEMENT celle trouvée avant le test (l'absence
        // d'écriture silencieuse serait une fuite laissée sur le poste — défaut relevé par le juge).
        sauvegarde.Restaurer();
        Assert.True(sauvegarde.EstIdentique(), "le test doit rendre l'état HKCU trouvé");
    }

    [Fact]
    public async Task Points1et2_un_lien_kdocs_ouvre_le_document_dans_word()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        var gestionnaire = new GestionnaireUrl(
            new GestionnaireTravail(racine, client, fiches, new OuvreurShell(), HorlogeSysteme.Instance, _f.Journal),
            _f.Journal);

        try
        {
            WordReel.NettoyerTemporaires();
            var resultat = await gestionnaire.TraiterAsync($"kdocs://ouvrir/{_f.Docx}");

            Assert.True(resultat.Accepte, resultat.Refus);
            var fiche = fiches.Lire(_f.Docx)!;
            Assert.True(File.Exists(fiche.Chemin));
            Assert.True(
                Attendre(() => WordReel.EstOuvert(fiche.Chemin), TimeSpan.FromSeconds(30)),
                "Word doit avoir ouvert le fichier local");
            // « ouvert par … » côté GED : le signal prolonge une ouverture bien active.
            Assert.True(await client.SignalerAsync(_f.Docx));
        }
        finally
        {
            WordReel.Fermer(fiches.Lire(_f.Docx)!.Chemin);
        }
    }

    [Fact]
    public async Task Point3_un_enregistrement_word_devient_une_version_avec_la_meme_empreinte()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        var travail = new GestionnaireTravail(racine, client, fiches, new FauxOuvreur(), HorlogeSysteme.Instance, _f.Journal);
        var fiche = await travail.OuvrirAsync(_f.Docx);
        var versionAvant = (await _f.EtatAsync(_f.JetonPrincipal, _f.Docx)).GetProperty("version").GetInt32();
        var coordinateur = Coordinateur(client, racine, fiches, _f.Journal);

        WordReel.NettoyerTemporaires();
        using (var word = new WordOuvert(fiche.Chemin))
        {
            word.Executer(doc => ((dynamic)doc).Content.InsertAfter(" MODIF F13 CTRL+S"));
            word.Enregistrer();
        }

        using (var verif = DocumentFormat.OpenXml.Packaging.WordprocessingDocument.Open(fiche.Chemin, false))
        {
            Assert.Contains(
                "MODIF F13 CTRL+S",
                verif.MainDocumentPart!.Document!.InnerText,
                StringComparison.Ordinal);
        }

        var resultats = await coordinateur.TraiterFichierAsync(fiche.Chemin);

        Assert.Single(resultats);
        Assert.True(resultats[0].Livre);
        Assert.False(resultats[0].Conflit);
        var etat = await _f.EtatAsync(_f.JetonPrincipal, _f.Docx);
        Assert.Equal(versionAvant + 1, etat.GetProperty("version").GetInt32());
        Assert.Equal(
            EmpreinteSha256.Instance.CalculerFichier(fiche.Chemin),
            etat.GetProperty("empreinte").GetString(),
            ignoreCase: true);
        Assert.Equal(versionAvant + 1, fiches.Lire(_f.Docx)!.DerniereVersionConnue);
    }

    [Fact]
    public async Task Point3_deux_enregistrements_hors_ligne_arrivent_dans_l_ordre_apres_reprise()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var reel = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        var travail = new GestionnaireTravail(racine, reel, fiches, new FauxOuvreur(), HorlogeSysteme.Instance, _f.Journal);
        var fiche = await travail.OuvrirAsync(_f.DocxLigne);
        var versionAvant = (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxLigne)).GetProperty("version").GetInt32();

        // Deux Ctrl+S pendant la coupure réseau : la file garde les deux instantanés.
        var horloge = new HorlogeFigee(new DateTimeOffset(2026, 10, 11, 12, 0, 0, TimeSpan.Zero));
        var coupure = new ClientCoupure(reel) { EnPanne = true };
        var depot = new DepotFileDisque(racine.DossierFile);
        var fileEnPanne = new GestionnaireFileEnvoi(
            coupure, depot, racine, new FauxFichierStable(), horloge, _f.Journal);
        File.WriteAllText(fiche.Chemin, "contenu hors ligne A");
        await fileEnPanne.MettreEnFileAsync(_f.DocxLigne, fiche.Chemin, versionAvant);
        horloge.Avancer(TimeSpan.FromSeconds(1));
        File.WriteAllText(fiche.Chemin, "contenu hors ligne B");
        await fileEnPanne.MettreEnFileAsync(_f.DocxLigne, fiche.Chemin, versionAvant);
        var sansReseau = await fileEnPanne.TraiterAsync();
        Assert.All(sansReseau, r => Assert.False(r.Livre));
        Assert.Equal(2, depot.Charger().Count);

        // Redémarrage de l'agent : une nouvelle file relit le disque et le réseau est rétabli.
        horloge.Avancer(TimeSpan.FromMinutes(1));
        var enregistreur = new ClientEnregistreur(reel);
        var fileReprise = new GestionnaireFileEnvoi(
            enregistreur, depot, racine, new FauxFichierStable(), horloge, _f.Journal);
        var repris = await fileReprise.TraiterAsync();

        Assert.Equal(2, repris.Count(r => r.Livre));
        Assert.Equal(2, enregistreur.Depots.Count);
        Assert.Equal(versionAvant, enregistreur.Depots[0].VersionBase);
        Assert.Equal(versionAvant + 1, enregistreur.Depots[1].VersionBase);
        Assert.Equal(versionAvant + 2, (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxLigne)).GetProperty("version").GetInt32());
        Assert.Empty(depot.Charger());
    }

    [Fact]
    public async Task Point4_une_modification_non_enregistree_part_en_copie_de_secours()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        // Ouverture par l'association Windows (le vrai geste de l'agent) : le document vit dans la
        // session Word de l'utilisateur, celle que la table des objets expose.
        var travail = new GestionnaireTravail(racine, client, fiches, new OuvreurShell(), HorlogeSysteme.Instance, _f.Journal);
        var fiche = await travail.OuvrirAsync(_f.Docx);

        try
        {
            Assert.True(
                Attendre(() => WordReel.EstOuvert(fiche.Chemin), TimeSpan.FromSeconds(30)),
                "Word doit avoir ouvert le fichier local");
            WordReel.Modifier(fiche.Chemin, doc => ((dynamic)doc).Content.InsertAfter(" MODIF NON ENREGISTREE F13"));
            Assert.False(WordReel.EstEnregistre(fiche.Chemin), "le document doit être modifié sans enregistrement");

            var automate = new OfficeComAutomate(_f.Journal);
            var vus = AttendreDocuments(automate, fiche.Chemin, TimeSpan.FromSeconds(20));
            Assert.True(vus.Count == 1, $"la session Word doit exposer le document ouvert (vu {vus.Count})");
            Assert.False(vus[0].Enregistre, "le document est modifié sans enregistrement");

            var secours = new ServiceSecours(automate, client, racine, HorlogeSysteme.Instance, _f.Journal);
            var copies = await secours.ExecuterPasseAsync();

            var copie = Assert.Single(copies, c => c.Document == _f.Docx);
            Assert.True(copie.Envoyee);
            Assert.True(File.Exists(copie.CheminLocal));
            var (statut, corps) = await _f.AppelerAsync(_f.JetonPrincipal, HttpMethod.Get, $"/api/v1/edition/documents/{_f.Docx}/secours");
            Assert.Equal(200, statut);
            Assert.Contains(
                corps!.Value.GetProperty("copies").EnumerateArray(),
                c => string.Equals(c.GetProperty("empreinte").GetString(), copie.Empreinte, StringComparison.OrdinalIgnoreCase));
            Assert.False(WordReel.EstEnregistre(fiche.Chemin), "la copie de secours ne doit pas enregistrer le document");
        }
        finally
        {
            if (WordReel.EstOuvert(fiche.Chemin))
            {
                WordReel.Fermer(fiche.Chemin);
            }
        }
    }

    [Fact]
    public async Task Point5_une_base_perimee_cree_une_version_parallele_sans_ecraser()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        var travail = new GestionnaireTravail(racine, client, fiches, new FauxOuvreur(), HorlogeSysteme.Instance, _f.Journal);
        var fiche = await travail.OuvrirAsync(_f.DocxConflit);
        var versionAvant = (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxConflit)).GetProperty("version").GetInt32();
        var file = FileRapide(client, racine, _f.Journal);

        // 1) Une modification légitime avance la version : la base est celle du serveur.
        File.WriteAllText(fiche.Chemin, "contenu légitime sur la bonne base");
        await file.MettreEnFileAsync(_f.DocxConflit, fiche.Chemin, versionAvant);
        Assert.True(Assert.Single(await file.TraiterAsync()).Livre);
        var versionApres = (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxConflit)).GetProperty("version").GetInt32();
        Assert.Equal(versionAvant + 1, versionApres);

        // 2) Une nouvelle modification sur la base PÉRIMÉE est conservée en version parallèle.
        File.WriteAllText(fiche.Chemin, "contenu local sur une base périmée");
        await file.MettreEnFileAsync(_f.DocxConflit, fiche.Chemin, versionAvant);
        var resultats = await file.TraiterAsync();

        var resultat = Assert.Single(resultats);
        Assert.True(resultat.Livre);
        Assert.True(resultat.Conflit);
        Assert.Contains("version parallèle", resultat.Message ?? string.Empty, StringComparison.Ordinal);
        var etat = await _f.EtatAsync(_f.JetonPrincipal, _f.DocxConflit);
        Assert.Equal(versionApres + 1, etat.GetProperty("version").GetInt32());
        Assert.Equal("contenu local sur une base périmée", File.ReadAllText(fiche.Chemin));
    }

    [Fact]
    public async Task Point6_un_lien_piege_est_refuse_et_un_document_sans_droit_est_trace()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonPrincipal);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientPrincipal();
        var fiches = new DepotFichesDisque(racine);
        var gestionnaire = new GestionnaireUrl(
            new GestionnaireTravail(racine, client, fiches, new FauxOuvreur(), HorlogeSysteme.Instance, _f.Journal),
            _f.Journal);

        foreach (var url in new[]
                 {
                     "kdocs://fermer/1", "kdocs://ouvrir/abc", "kdocs://ouvrir/1/../2",
                     "http://autre-serveur/ouvrir/1", "kdocs://serveur-autre/1", "kdocs://ouvrir/0",
                 })
        {
            var resultat = await gestionnaire.TraiterAsync(url);
            Assert.False(resultat.Accepte, $"lien accepté à tort : {url}");
        }
        Assert.Contains(_f.Journal.Lignes, l => l.Message.Contains("refusé", StringComparison.Ordinal));

        // Lien valide mais document sans droit : l'ouverture est refusée (403) et tracée.
        var resultatSecret = await gestionnaire.TraiterAsync($"kdocs://ouvrir/{_f.Secret}");
        Assert.True(resultatSecret.Accepte);
        Assert.Contains(
            _f.Journal.Lignes,
            l => l.Niveau == "ERREUR" && l.Message.Contains($"document {_f.Secret}", StringComparison.Ordinal));
    }

    [Fact]
    public async Task Point7_un_appareil_revoque_n_ecrit_plus_et_garde_la_copie_locale()
    {
        using var racineTemporaire = _f.CreerRacineAgent(_f.JetonRevocable);
        var racine = new RacineAgent(racineTemporaire.Chemin);
        using var client = _f.ClientRevocable();
        var fiches = new DepotFichesDisque(racine);
        var travail = new GestionnaireTravail(racine, client, fiches, new FauxOuvreur(), HorlogeSysteme.Instance, _f.Journal);
        var fiche = await travail.OuvrirAsync(_f.DocxRevoque);
        var versionAvant = (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxRevoque)).GetProperty("version").GetInt32();

        var (statutRevocation, _) = await _f.AppelerAsync(_f.JetonRevocable, HttpMethod.Delete, "/api/v1/appairage/moi");
        Assert.Equal(200, statutRevocation);

        File.WriteAllText(fiche.Chemin, "modification après révocation");
        var depot = new DepotFileDisque(racine.DossierFile);
        var file = new GestionnaireFileEnvoi(client, depot, racine, new FauxFichierStable(), HorlogeSysteme.Instance, _f.Journal);
        var entree = await file.MettreEnFileAsync(_f.DocxRevoque, fiche.Chemin, versionAvant);
        var resultats = await file.TraiterAsync();

        Assert.False(Assert.Single(resultats).Livre);
        Assert.True(File.Exists(entree!.CheminInstantane), "appareil révoqué : la copie locale reste");
        Assert.Single(depot.Charger());
        var (statut, _) = await _f.AppelerAsync(_f.JetonRevocable, HttpMethod.Get, $"/api/v1/edition/documents/{_f.DocxRevoque}");
        Assert.Equal((int)HttpStatusCode.Unauthorized, statut);
        Assert.Equal(versionAvant, (await _f.EtatAsync(_f.JetonPrincipal, _f.DocxRevoque)).GetProperty("version").GetInt32());
    }
}
