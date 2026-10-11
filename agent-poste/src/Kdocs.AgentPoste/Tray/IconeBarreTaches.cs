namespace Kdocs.AgentPoste.Tray;

/// <summary>
/// Icône de la barre des tâches (zone de notification) : « Mes documents en cours » et « Récupérer la
/// copie de secours ». Application de SESSION : Office n'est piloté que dans la session de l'utilisateur.
/// </summary>
public sealed class IconeBarreTaches : IDisposable
{
    private readonly NotifyIcon _icone;
    private readonly Kdocs.AgentPoste.Application.ServiceDocumentsEnCours _documents;
    private readonly Kdocs.AgentPoste.Application.ServiceRecuperation _recuperation;
    private readonly string _dossierTravail;
    private readonly Action _quitter;
    private FenetreDocuments? _fenetre;

    public IconeBarreTaches(
        Kdocs.AgentPoste.Application.ServiceDocumentsEnCours documents,
        Kdocs.AgentPoste.Application.ServiceRecuperation recuperation,
        string dossierTravail,
        Action quitter)
    {
        _documents = documents;
        _recuperation = recuperation;
        _dossierTravail = dossierTravail;
        _quitter = quitter;

        _icone = new NotifyIcon
        {
            Icon = SystemIcons.Application,
            Visible = true,
            Text = "K-Docs — édition depuis le poste",
        };
        _icone.DoubleClick += (_, _) => MontrerDocuments();
        Rafraichir();
    }

    public NotifyIcon NotifyIcon => _icone;

    /// <summary>Reconstruit le menu (l'état des documents change à chaque envoi ou copie).</summary>
    public void Rafraichir()
    {
        var menu = new ContextMenuStrip();
        menu.Items.Add("Mes documents en cours…", null, (_, _) => MontrerDocuments());

        var recuperer = new ToolStripMenuItem("Récupérer la copie de secours");
        var disponibles = _documents.Lister().Where(d => d.CopieSecours is not null).ToList();
        if (disponibles.Count == 0)
        {
            recuperer.Enabled = false;
            recuperer.DropDownItems.Add("(aucune copie de secours pour l’instant)");
        }
        else
        {
            foreach (var document in disponibles)
            {
                var id = document.Document;
                var heure = document.CopieSecoursLeUtc?.ToString("HH:mm") ?? "copie";
                var entree = new ToolStripMenuItem($"{document.Nom} — {heure}");
                entree.Click += (_, _) => Recuperer(id);
                recuperer.DropDownItems.Add(entree);
            }
        }

        menu.Items.Add(recuperer);
        menu.Items.Add(new ToolStripSeparator());
        menu.Items.Add("Ouvrir le dossier de travail", null, (_, _) => OuvrirDossier());
        menu.Items.Add("Quitter", null, (_, _) => _quitter());

        var ancien = _icone.ContextMenuStrip;
        _icone.ContextMenuStrip = menu;
        ancien?.Dispose();
    }

    public void Dispose()
    {
        _icone.Visible = false;
        _icone.Dispose();
        _fenetre?.Dispose();
    }

    private void MontrerDocuments()
    {
        _fenetre ??= new FenetreDocuments(_documents, _recuperation, _dossierTravail);
        _fenetre.Rafraichir();
        _fenetre.Show();
        _fenetre.WindowState = FormWindowState.Normal;
        _fenetre.BringToFront();
    }

    private void Recuperer(int document)
    {
        var chemin = _recuperation.Recuperer(document);
        _icone.BalloonTipTitle = "K-Docs";
        _icone.BalloonTipText = chemin is null
            ? $"Aucune copie de secours pour le document {document}."
            : $"Copie de secours restaurée : {chemin}";
        _icone.ShowBalloonTip(4000);
        Rafraichir();
    }

    private void OuvrirDossier()
    {
        System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(_dossierTravail) { UseShellExecute = true });
    }
}
