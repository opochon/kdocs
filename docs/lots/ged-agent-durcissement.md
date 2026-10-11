# Lot ged-agent-durcissement — sécurité de l'agent local (bloquant client)

Branche `ged/ged-agent-durcissement` (worktree `C:\ged\wt\ged-agent-durcissement`), juge : Claude sur le poste
principal. Secteur `edition-poste` (cahier des charges `docs/secteurs/edition-poste.md`).
Constat du juge du 11-10 : l'agent passe au shell tout fichier téléchargé, sans liste blanche d'extensions.

## Fait

- (en cours) Étape 0 — cadrage : relecture du jugement `ged-agent-poste` (`docs/secteurs/edition-poste.md`),
  du code `agent-poste/` et de `src/Edition/`. Documentation Microsoft lue d'abord :
  - extensions à macros Office Open XML (`.docm`, `.xlsm`, `.pptm` — et aussi `.xlsb`) :
    https://learn.microsoft.com/en-us/openspecs/office_file_formats/ et
    https://support.microsoft.com/en-us/office/macros-in-office-files-12b036fd-d140-4e74-b45e-16fed1a7e5c6 ;
  - enregistrement d'un protocole d'URL par utilisateur dans
    `HKCU\Software\Classes\<scheme>` (« Registering an Application to a URI Scheme », « Merged View of
    HKEY_CLASSES_ROOT ») : https://learn.microsoft.com/en-us/windows/win32/sysinfo/merged-view-of-hkey-classes-root ;
  - démarrage à l'ouverture de session `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
    (« Run and RunOnce Registry Keys ») :
    https://learn.microsoft.com/en-us/windows/win32/setupapi/run-and-runonce-registry-keys.

## En cours

- Écriture de l'oracle xUnit rouge : liste blanche d'extensions, refus jamais passé au shell, motif visible
  dans « Mes documents en cours ».

## Reste

- Agent C# : `ExtensionsEditables`, refus tracé + visible, `dotnet test` vert.
- Serveur PHP : liste unique `Formats`, bouton masqué hors liste, API ouverture/version 403 nommé.
- E2E écran : ni `.exe` ni `.docm` ne montrent de bouton.
- Installateur PowerShell sans droits admin + test machine.

ETAT: en cours
