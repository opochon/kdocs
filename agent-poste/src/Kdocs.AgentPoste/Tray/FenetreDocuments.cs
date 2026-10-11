using System.Diagnostics;

namespace Kdocs.AgentPoste.Tray;

/// <summary>
/// « Mes documents en cours » : liste des documents ouverts, leur état (envoyé ou en attente), un éventuel
/// conflit, et la dernière copie de secours. Le bouton « Récupérer la copie de secours » restaure la
/// dernière copie locale dans le dossier de travail et l'ouvre.
/// </summary>
public sealed class FenetreDocuments : Form
{
    private readonly Kdocs.AgentPoste.Application.ServiceDocumentsEnCours _documents;
    private readonly Kdocs.AgentPoste.Application.ServiceRecuperation _recuperation;
    private readonly string _dossierTravail;
    private readonly ListView _liste;
    private readonly Label _etat;

    public FenetreDocuments(
        Kdocs.AgentPoste.Application.ServiceDocumentsEnCours documents,
        Kdocs.AgentPoste.Application.ServiceRecuperation recuperation,
        string dossierTravail)
    {
        _documents = documents;
        _recuperation = recuperation;
        _dossierTravail = dossierTravail;

        Text = "K-Docs — Mes documents en cours";
        Width = 980;
        Height = 440;
        StartPosition = FormStartPosition.CenterScreen;
        MinimizeBox = false;
        ShowInTaskbar = true;

        _liste = new ListView
        {
            Dock = DockStyle.Fill,
            View = View.Details,
            FullRowSelect = true,
            MultiSelect = false,
            AccessibleName = "documents-en-cours",
        };
        _liste.Columns.Add("Document", 70);
        _liste.Columns.Add("Nom", 220);
        _liste.Columns.Add("État", 90);
        _liste.Columns.Add("Conflit", 60);
        _liste.Columns.Add("Copie de secours", 130);
        _liste.Columns.Add("Motif", 340);

        var boutons = new FlowLayoutPanel { Dock = DockStyle.Bottom, Height = 52, Padding = new Padding(8) };
        var recuperer = new Button { Text = "Récupérer la copie de secours", AutoSize = true, AccessibleName = "recuperer-copie" };
        recuperer.Click += (_, _) => RecupererSelection();
        var ouvrirDossier = new Button { Text = "Ouvrir le dossier de travail", AutoSize = true, AccessibleName = "ouvrir-dossier" };
        ouvrirDossier.Click += (_, _) => OuvrirDossier();
        var rafraichir = new Button { Text = "Rafraîchir", AutoSize = true, AccessibleName = "rafraichir" };
        rafraichir.Click += (_, _) => Rafraichir();
        boutons.Controls.Add(recuperer);
        boutons.Controls.Add(ouvrirDossier);
        boutons.Controls.Add(rafraichir);

        _etat = new Label { Dock = DockStyle.Bottom, Height = 24, Padding = new Padding(8, 0, 0, 0), AccessibleName = "etat" };

        Controls.Add(_liste);
        Controls.Add(_etat);
        Controls.Add(boutons);
        Rafraichir();
    }

    public void Rafraichir()
    {
        _liste.BeginUpdate();
        _liste.Items.Clear();
        foreach (var document in _documents.Lister())
        {
            var ligne = new ListViewItem(document.Document.ToString(System.Globalization.CultureInfo.InvariantCulture))
            {
                Tag = document.Document,
            };
            ligne.SubItems.Add(document.Nom);
            ligne.SubItems.Add(document.Etat);
            ligne.SubItems.Add(document.Conflit ? "oui" : "non");
            ligne.SubItems.Add(document.CopieSecoursLeUtc is null ? "aucune" : document.CopieSecoursLeUtc.Value.ToString("HH:mm"));
            ligne.SubItems.Add(document.Refus ?? string.Empty);
            _liste.Items.Add(ligne);
        }

        _liste.EndUpdate();
        _etat.Text = _liste.Items.Count == 0
            ? "Aucun document en cours."
            : $"{_liste.Items.Count} document(s) en cours.";
    }

    private void RecupererSelection()
    {
        if (_liste.SelectedItems.Count == 0 || _liste.SelectedItems[0].Tag is not int document)
        {
            _etat.Text = "Choisissez d’abord un document.";

            return;
        }

        var chemin = _recuperation.Recuperer(document);
        _etat.Text = chemin is null
            ? $"Aucune copie de secours pour le document {document}."
            : $"Copie de secours restaurée : {chemin}";
        Rafraichir();
    }

    private void OuvrirDossier()
    {
        try
        {
            Process.Start(new ProcessStartInfo(_dossierTravail) { UseShellExecute = true });
        }
        catch (Exception exception) when (exception is System.ComponentModel.Win32Exception or InvalidOperationException)
        {
            _etat.Text = $"Dossier de travail injoignable : {exception.Message}";
        }
    }
}
