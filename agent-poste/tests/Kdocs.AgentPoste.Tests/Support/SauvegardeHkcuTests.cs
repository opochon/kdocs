using Microsoft.Win32;

namespace Kdocs.AgentPoste.Tests.Support;

/// <summary>
/// Oracle du helper d'hygiène HKCU : il photographie une clé, la restitue à l'identique après mutation, et
/// supprime une clé qu'il a créée alors qu'elle n'existait pas. Il travaille sur une clé JETABLE (jamais
/// <c>Software\Classes\kdocs</c>, réservée au parcours F13) et la nettoie toujours.
/// </summary>
public sealed class SauvegardeHkcuTests : IDisposable
{
    private readonly string _cle = @"Software\Kdocs.Tests\SauvegardeHkcu\" + Guid.NewGuid().ToString("N");

    public void Dispose() => Registry.CurrentUser.DeleteSubKeyTree(_cle, throwOnMissingSubKey: false);

    [Fact]
    public void Restitue_a_l_identique_une_cle_pre_existante()
    {
        using (var cle = Registry.CurrentUser.CreateSubKey(_cle, writable: true)!)
        {
            cle.SetValue(string.Empty, "URL:K-Docs Protocol", RegistryValueKind.String);
            cle.SetValue("URL Protocol", string.Empty, RegistryValueKind.String);
            using var commande = cle.CreateSubKey(@"shell\open\command", writable: true)!;
            commande.SetValue(string.Empty, "\"C:\\apps\\Kdocs.AgentPoste.exe\" \"%1\"", RegistryValueKind.String);
        }

        using var sauvegarde = new SauvegardeHkcu(_cle);
        Assert.True(sauvegarde.Existait);
        Assert.True(sauvegarde.EstIdentique(), "une clé intacte est identique à sa photographie");

        using (var cle = Registry.CurrentUser.OpenSubKey(_cle, writable: true)!)
        {
            cle.SetValue(string.Empty, "modifié par le test", RegistryValueKind.String);
            cle.SetValue("Extra", "1", RegistryValueKind.String);
            using var extra = cle.CreateSubKey("extra-sous-cle", writable: true)!;
            extra.SetValue(string.Empty, "présente", RegistryValueKind.String);
        }
        Assert.False(sauvegarde.EstIdentique(), "une clé modifiée ne doit pas être vue comme identique");

        sauvegarde.Restaurer();
        Assert.True(sauvegarde.EstIdentique(), "la clé doit être rendue à l'identique");

        using var apres = Registry.CurrentUser.OpenSubKey(_cle)!;
        Assert.Equal("URL:K-Docs Protocol", apres.GetValue(string.Empty) as string);
        Assert.Null(apres.GetValue("Extra"));
        Assert.Null(apres.OpenSubKey("extra-sous-cle"));
        Assert.Equal(
            "\"C:\\apps\\Kdocs.AgentPoste.exe\" \"%1\"",
            apres.OpenSubKey(@"shell\open\command")!.GetValue(string.Empty) as string);
    }

    [Fact]
    public void Supprime_une_cle_qui_n_existait_pas()
    {
        Registry.CurrentUser.DeleteSubKeyTree(_cle, throwOnMissingSubKey: false);

        using var sauvegarde = new SauvegardeHkcu(_cle);
        Assert.False(sauvegarde.Existait);
        Assert.True(sauvegarde.EstIdentique(), "deux absences sont identiques");

        using (var cle = Registry.CurrentUser.CreateSubKey(_cle, writable: true)!)
        {
            cle.SetValue(string.Empty, "écrit par le test", RegistryValueKind.String);
        }
        Assert.False(sauvegarde.EstIdentique());

        sauvegarde.Restaurer();
        Assert.True(sauvegarde.EstIdentique());
        Assert.Null(Registry.CurrentUser.OpenSubKey(_cle));
    }
}
