using System.Text;
using Kdocs.AgentPoste.Secours;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>Office simulé : liste des documents ouverts et écriture d'une copie scriptée.</summary>
public sealed class FauxOfficeAutomate : IOfficeAutomate
{
    public List<DocumentOffice> Documents { get; } = [];

    public List<(DocumentOffice Document, string Destination)> Copies { get; } = [];

    public Func<DocumentOffice, byte[]>? Contenu { get; set; }

    public Exception? ErreurCopie { get; set; }

    public IReadOnlyList<DocumentOffice> Lister() => Documents;

    public void EcrireCopieSecours(DocumentOffice document, string destination)
    {
        Copies.Add((document, destination));
        if (ErreurCopie is not null)
        {
            throw ErreurCopie;
        }

        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        File.WriteAllBytes(destination, Contenu?.Invoke(document) ?? Encoding.UTF8.GetBytes("copie de secours"));
    }
}
