# Lot ged-agent-durcissement — sécurité de l'agent local (bloquant client)

Branche `ged/ged-agent-durcissement` (worktree `C:\ged\wt\ged-agent-durcissement`), juge : Claude sur le poste
principal. Secteur `edition-poste` (cahier des charges `docs/secteurs/edition-poste.md`).
Constat du juge du 11-10 : l'agent passe au shell tout fichier téléchargé, sans liste blanche d'extensions.
Objectif : plus aucun format hors liste blanche ne peut être exécuté par le poste (ni bouton, ni lien piégé,
ni fiche héritée), côté agent **et** côté serveur, et livrer l'installateur PowerShell sans droits admin.

## Fait

### Étape 0 — cadrage, documentation Microsoft d'abord

- Relecture du jugement `ged-agent-poste`, du code `agent-poste/` et de `src/Edition/`.
- Documentation Microsoft lue avant d'écrire :
  - extensions à macros Office Open XML (`.docm`, `.xlsm`, `.pptm`, et `.xlsb` qui peut en contenir) :
    « Macros in Office files » <https://support.microsoft.com/en-us/office/macros-in-office-files-12b036fd-d140-4e74-b45e-16fed1a7e5c6>
    et [MS-OVBA] <https://learn.microsoft.com/en-us/openspecs/office_file_formats/ms-ovba/575462ba-bf67-4190-9fac-c275523c75fc> ;
  - protocole d'URL par utilisateur dans `HKCU\Software\Classes\<scheme>` (« Registering an Application to a
    URI Scheme » ; `HKCR` est la vue fusionnée de `HKCU` et `HKLM`) :
    <https://learn.microsoft.com/en-us/windows/win32/sysinfo/merged-view-of-hkey-classes-root> ;
  - démarrage à l'ouverture de session `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
    (« Run and RunOnce Registry Keys ») :
    <https://learn.microsoft.com/en-us/windows/win32/setupapi/run-and-runonce-registry-keys>.

### Étape 1 — liste BLANCHE d'extensions, côté agent (`76c2270`)

- Oracle xUnit écrit d'abord (**compilation rouge** : 15 erreurs `CS0246/CS1729/CS1061`), puis vert.
- `Kdocs.AgentPoste.Travail.ExtensionsEditables` : **liste blanche** exacte
  `doc, docx, xls, xlsx, ppt, pptx, odt, ods, odp, rtf, txt, csv`. L'extension retenue est celle du nom
  **normalisé** par `RacineAgent.NomSain` (basename, caractères interdits remplacés, espaces/points finaux
  retirés), jamais celle fournie brute.
- Exclusions, justifiées :
  - `docm`, `xlsm`, `pptm` et `xlsb` : formats Office **à macros VBA** (`vbaProject.bin`), vecteur
    d'exécution documenté par Microsoft ;
  - `dot`, `dotx`, `potm`… : modèles (et modèles à macros), hors périmètre d'édition ;
  - `exe, com, bat, cmd, ps1, js, vbs, lnk, url, hta, msi, scr, pif, cpl, jar` et tout nom sans extension :
    refusés **par défaut**.
- Variantes couvertes : majuscules, double extension (`facture.pdf.exe`), espaces/points finaux, flux de
  données alternatif (`nom.docx:x.exe` → la dernière extension reste `.exe`), nom sans extension.
- `GestionnaireTravail.OuvrirAsync` refuse **avant tout téléchargement** : jamais de fichier écrit, jamais
  `Process.Start`, jamais de signal d'ouverture. Le refus est journalisé (`AVERTISSEMENT`) et conservé
  (`RefusOuverture` + `DepotRefusDisque`, borné aux 50 derniers) ; « Mes documents en cours » le montre
  (`Etat = « refusé »`, colonne **Motif**).
- `ServiceRecuperation` (`fb2f471`) refuse de rouvrir une fiche héritée d'un format non éditable : le dernier
  chemin vers le shell est fermé.

### Étape 2 — liste unique côté serveur (`3094968`)

- `Ged\Edition\Formats` : **source unique** (sections Word / Excel / PowerPoint), la même liste que l'agent.
- `Pieces::libelleOuverture` s'y adosse ; `src/Ui/Ecrans.php` expose `peut_ouvrir_poste` et
  `templates/ecrans/document.twig` ne rend le bouton `action-ouvrir-poste` que pour un format éditable ;
  `src/Socle/EcranBibliotheque.php` applique la même règle à l'aperçu.
- `src/Edition/Api.php` : **403 nommé** (`Formats::motif`) pour l'ouverture et le dépôt de version d'un
  document hors liste, et pour tout fichier téléversé hors liste (même si le document rangé est éditable).

### Étape 3 — E2E écran (`934fde9`)

- `tests/e2e/preparer-edition-serveur.php` dépose un `facture.pdf.exe` et un `courrier.docm` dans le même
  dossier que le `.docx` et le `.xlsx`.
- `tests/e2e/edition-serveur.spec.ts` : le manager ouvre leur fiche **par la Bibliothèque** (clic ligne puis
  « Ouvrir ») et assène `action-ouvrir-poste` à 0 et **aucun** lien `kdocs://`. Aucun appel d'API interne.

### Étape 4 — installateur PowerShell sans droits administrateur (`e312394`)

- `agent-poste/tools/installer-agent.ps1` : copie dans `%LOCALAPPDATA%\K-Docs\Agent`, enregistre
  `HKCU\Software\Classes\kdocs` (`"<exe>" "%1"`), la valeur `K-Docs` de `HKCU\...\Run`, et enrôlement
  facultatif (`--enrolement`). Désinstallation : programme + clé de protocole + valeur Run ; **les données
  utilisateur ne sont jamais supprimées**.
- `agent-poste/tools/tester-installateur.ps1` rejoué sur cette machine : installation (source = build réel),
  clés vérifiées, désinstallation, « rien ne reste » (dossier `Agent`, clé `kdocs`, valeur Run), **donnée de
  travail conservée**. L'état HKCU d'avant le test est sauvegardé (`reg export`) puis restauré.
  Résultat : `INSTALLATEUR: OK`.

### Durcissement (revue ECC) (`9567cbb`, `fb2f471`)

- `DepotRefusDisque` : les erreurs d'E/S (écriture, lecture, purge) sont **tracées** sans jamais masquer
  l'erreur nommée de l'agent ; la lecture ne fait pas tomber l'icône.
- `ServiceRecuperation` : oracle ajouté, jamais d'ouverture hors liste blanche.

## Mesures

- Machine : .NET SDK **10.0.401**, Office 16, PHP **8.4.0**, base de test `gedv2_test` (127.0.0.1:3308).
- `dotnet test agent-poste/AgentPoste.slnx` : **220 tests, 0 échec, 0 ignoré, 25 s** (dont l'oracle
  d'extensions : 89 cas rouges d'abord, puis un oracle de récupération). Compilation `TreatWarningsAsErrors`
  et `nullable` : **0 avertissement, 0 erreur**.
- `php vendor/bin/phpunit` : **563 tests, 3 108 assertions, 1 ignoré**.
- Installateur : test machine `INSTALLATEUR: OK` (install → clés → désinstall → rien ne reste).

### Harness `-SansBanc` (commit `fb2f471`, 2026-10-11 04:03, **735 s**)

| Couche | Verdict | Chiffres |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules présents, DB_NAME=gedv2_test imposé |
| 1-unitaire (PHP) | VERT | **563 tests, 3 108 assertions, 1 ignoré** |
| 3-banc | ROUGE | sautée (`-SansBanc`, attendu) |
| 4-ecrans | VERT | **76 passed (3,5 m)** |
| 7-securite | VERT | sonde sécurité : aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture **760 ms**, p95 écriture **244 ms**, 0 erreur, 0 collision |
| 8-statique | VERT | **phpstan : 0 erreur hors baseline** (baseline vide) ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 369–747 ms) |

Note honnête : un **premier rejeu** du harness a porté un échec de la couche 4 (`1 failed / 75 passed`) alors
que le même commit passait 76/76 en rejeu isolé puis au rejeu complet ci-dessus. Échec de timing sur les
budgets, non reproductible, sans lien avec le diff ; les deux rejeux suivants sont verts.

## Porte qualité (ECC)

1. **Analyse statique** : PHPStan niveau 6, `phpstan-baseline.neon` vide, **0 erreur hors baseline** ; C#
   `TreatWarningsAsErrors` + nullable en Debug : 0/0 ; aucun `phpstan-ignore` ni `#pragma`.
2. **Revue du diff** (grilles recopiées dans `docs/qualite/ecc/`) :
   - *security-reviewer* : le nom du document n'atteint plus jamais `Process.Start` sans validation ; la
     décision est une **liste blanche** (jamais une liste noire), appliquée sur le nom normalisé, côté agent
     **et** serveur ; refus **avant** écriture disque et avant tout effet de bord ; aucun secret journalisé
     (le refus nomme le document et son motif, jamais un jeton).
   - *silent-failure-hunter* : aucun `catch` muet — les E/S du dépôt de refus sont tracées et l'erreur nommée
     de l'agent reste levée ; l'installateur échoue avec un message nommé si l'exécutable source manque ou si
     l'enrôlement échoue.
   - *php-reviewer* : `declare(strict_types=1)`, classes `final`, `$doc`/`$peutOuvrirPoste` typés et courts ;
     aucun SQL concaténé (aucun SQL ajouté) ; styles PSR-12 respectés.
   - Constat mineur accepté et motivé : le constructeur C# `GestionnaireTravail` passe à 7 paramètres
     (injection de dépendances, même patron que le reste de l'agent) ; pas de DTO introduit pour ce seul cas.
3. **TDD** : oracle xUnit rouge (compilation) puis vert, oracle PHPUnit rouge (51 erreurs / 3 échecs) puis
   vert, E2E ajouté puis vert. Aucune assertion affaiblie, aucune sonde retirée.
4. **Journal** : chiffres ci-dessus.

## Limites connues

- Le test de l'installateur utilise les **vraies** clés HKCU (c'est ce qu'il doit écrire) ; il sauvegarde et
  restaure l'état d'avant, donc aucune trace ne subsiste (vérifié : clé `kdocs` identique à l'avant, valeur
  Run absente).
- La couche 4 du premier rejeu a connu un échec de timing non reproductible (voir note) ; les rejeux verts
  font foi.
- Les tests F13 (`dotnet test`) démarrent un serveur PHP sur un port libre et exigent Office ; ils restent
  inchangés par ce lot.

ETAT: fini
