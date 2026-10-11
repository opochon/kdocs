using DocumentFormat.OpenXml.Packaging;

namespace Kdocs.AgentPoste.Secours;

/// <summary>
/// Conversion du paquet Flat OPC lu dans Word (<c>Document.Content.WordOpenXML</c>, lecture seule) en
/// fichier .docx, par le SDK Open XML. Aucune écriture dans le document de l'utilisateur.
/// </summary>
public static class ConversionWord
{
    public static void EcrireDocxDepuisFlatOpc(string flatOpc, string destination)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(flatOpc);
        using var document = WordprocessingDocument.FromFlatOpcString(flatOpc);
        using var copie = document.Clone(destination);
        _ = copie;
    }
}
