# Lot ged-agent-poste — agent local K-Docs (F13)

Branche `ged/ged-agent-poste` (worktree `C:\ged\wt\ged-agent-poste`), juge : Claude sur le poste principal.
Cahier des charges : `docs/secteurs/edition-poste.md`. Côté serveur : `src/Edition` (lot `ged-edition-serveur`,
déjà fusionné dans `origin/v2` — aucune fusion n'a été nécessaire, la branche part de ce commit).

Agent C# / .NET 10 LTS, application de SESSION (jamais un service : Office n'est piloté que dans la session
de l'utilisateur), un seul exécutable + une icône de barre des tâches.

## Fait

### Étape 0 — cadrage (`agent-poste`, aucun code)

- Lecture du cahier des charges, du contrat HTTP livré par `ged-edition-serveur` (`src/Edition/Api.php`),
  de la porte qualité ECC et des patrons de test.
- Documentation Microsoft d'abord, puis **vérification sur l'Office réellement installé** :
  `Document.Content.WordOpenXML` **contient bien** en-têtes, pieds de page, commentaires et images
  (mesuré au `pkg:part`), contrairement à la crainte du cahier des charges ; `WordprocessingDocument`
  `FromFlatOpcString` + `Clone(path)` (SDK Open XML 3.5.1) ; `Workbook.SaveCopyAs`,
  `Presentation.SaveCopyAs` (PowerPoint rend `Saved` en entier, pas en booléen) ; ROT par
  `GetRunningObjectTable` (l'ancien `Marshal.GetActiveObject` n'existe plus en .NET moderne) ;
  `IOleMessageFilter` + `CoRegisterMessageFilter` ; DPAPI à portée utilisateur.

### Étape 1 — analyse stricte des URL `kdocs://` (`0117896`)

`agent-poste/` : solution `.slnx` avec projet agent (`net10.0-windows`, `TreatWarningsAsErrors`) et projet
xUnit. Oracle écrit d'abord (compilation rouge), puis l'analyse (`AnalyseUrlKdocs`) : une action de liste
blanche (`ouvrir`) et un identifiant numérique positif, ancré `\A…\z` — tout le reste est refusé avec une
raison nommée. **52 cas** : schéma/hôte/port/utilisateur, paramètres/fragment, segment, traversée,
encodage pourcent, contrôle, non ASCII, zéro initial, hors bornes, longueur.

### Étape 2 — enrôlement et briques (`72557f6`)

Journal sur disque, horloge injectable, empreinte SHA-256, chemins `%LOCALAPPDATA%\K-Docs`, validation de
l'adresse serveur, configuration sur disque, **coffre DPAPI** du jeton d'appareil (`ProtectedData`,
utilisateur courant), `EnroleurHttp` (`POST /api/v1/appairage/jeton`) qui **fixe l'adresse** et range le
jeton chiffré. 79 tests.

### Étape 3 — dossier de travail et file d'envoi (`9847898`)

Téléchargement dans `Travail\<id>\<nom>` avec **contrôle d'empreinte**, fiche `.kdocs.json` (empreinte de
base : le téléchargement n'est pas une modification), ouverture « comme un double-clic ». File d'envoi
**persistante** : instantané figé après stabilité, livraison dans l'ordre, versions relignées après un
envoi réussi, reprise après redémarrage, refus définitif conservé, conflit tracé sans écrasement.
**L'instantané n'est effacé qu'après confirmation du serveur** ; le fichier de l'utilisateur n'est jamais
envoyé en cours d'écriture. 90 tests.

### Étape 4 — copies de secours COM (`25c34f5`)

Attachement aux sessions Office par la **ROT** sur fil STA sous `IOleMessageFilter`/`CoRegisterMessageFilter`.
Excel/PowerPoint : `SaveCopyAs` ; Word : `Content.WordOpenXML` → `.docx` par le SDK Open XML. Le service
copie seulement les documents du dossier de travail modifiés non enregistrés, écrit dans
`Secours\<id>\<horodatage>` et dépose la dernière copie au serveur (`POST …/secours`). Surveillance du
dossier (`FileSystemWatcher`, anti-rebond). Oracles Office réels verts. 100 tests.

### Étape 5 — protocole, icône et câblage (`30ef924`)

Enregistrement `HKCU\Software\Classes\kdocs` (sans droits admin), gestionnaire d'URL qui journalise tout
refus (assainissement anti-injection de ligne), canal local d'instance unique, services « Mes documents en
cours » et « Récupérer la copie de secours », verrou informatif d'ouverture (ouverture/signal/fermeture),
icône de barre des tâches (WinForms), application de session avec passes courtes (file, secours, 2 min).
119 tests.

### Étape 6 — parcours F13 et oracles (`44ce987`)

`agent-poste/tests/parcours/preparer-f13.php` : jeu synthétique en `gedv2_test` (fonds et secours isolés
sous `var/oracles/agent-poste/`), compte manager, dossiers Documents/Secret, documents, appairage en
attente et deux jetons d'appareil. La xUnit `ParcoursF13Fixture` démarre le serveur PHP de test
(127.0.0.1:8873), joue la préparation puis **8 tests F13** contre le vrai serveur et Office réel :
enrôlement (point 1), enregistrement HKCU du protocole et lien `kdocs://` → Word (points 1-2), Ctrl+S →
version (3), deux enregistrements hors ligne repris dans l'ordre (3), copie de secours non enregistrée (4),
base périmée → version parallèle (5), lien piégé et document sans droit (6), appareil révoqué (7).
Les oracles Word (en-tête/pied/commentaire/image) et Excel/PowerPoint ont été réécrits pour ouvrir le
document **par l'association Windows** (session exposée par la ROT), comme le fait l'agent. 128 tests.

### Durcissement (revue ECC)

- `DepotFileDisque` et `DepotFichesDisque` : une entrée/fiche JSON illisible n'est plus ignorée
  silencieusement — elle est **tracée** (jamais un crash), l'instantané restant sur le disque.

## Mesures

- Machine : .NET SDK **10.0.401**, Office 16 (Word/Excel/PowerPoint), MariaDB de test `gedv2_test`
  (127.0.0.1:3308).
- `dotnet test agent-poste/AgentPoste.slnx` : **128 tests, 0 échec, 0 ignoré, ~30 s** — dont
  **116 unitaires**, **3 oracles Office réels** (Word/Excel/PowerPoint) et **9 tests du parcours F13**.
- Analyse statique C# : compilation `TreatWarningsAsErrors` + nullable, **0 avertissement, 0 erreur**.
- Parcours F13, point par point :
  1. **Enrôlement** : `POST /api/v1/appairage/jeton` (appairage existant) → jeton rangé chiffré (DPAPI) ;
     `GET /api/v1/appairage/moi` rend le compte du manager ; adresse du serveur inscrite en configuration.
  2. **`kdocs://`** : clé `HKCU\Software\Classes\kdocs` réellement écrite et relue, commande
     `"…\Kdocs.AgentPoste.exe" "%1"` ; 52 cas d'analyse dont 6 liens piégés refusés au parcours.
  3. **Ctrl+S** : Word réel enregistre, l'agent dépose → version serveur +1 et **empreinte identique**.
     **Hors ligne** : deux Ctrl+S, panne simulée (les deux instantanés restent), « redémarrage » puis
     reprise → **deux versions reçues dans l'ordre** (bases relignées v+1 puis v+2).
  4. **Copie de secours** : Word modifié non enregistré → copie `.docx` locale + dépôt serveur retrouvé
     par `GET …/secours` (empreinte identique) ; le document Word reste `Saved = False`.
  5. **Conflit** : dépôt sur une base périmée → `conflit = true`, **version parallèle** créée, rien d'écrasé.
  6. **Lien piégé** : refusé et journalisé ; document sans droit → **403** tracé, aucune version créée.
  7. **Appareil révoqué** : `DELETE /api/v1/appairage/moi` puis dépôt → **401**, instantané local conservé,
     version serveur inchangée.
- Harness PHP (`tools\harness.ps1 -SansBanc`) : voir la rubrique « Harness » ci-dessous (chiffres exacts
  rejoués avant `ETAT: fini`).

## Porte qualité

1. **Analyse statique** : `dotnet build` avec `TreatWarningsAsErrors` sur le projet agent → 0 avertissement,
   0 erreur ; `nullable enable` partout. Aucun `#pragma` ni `SuppressMessage`.
2. **Revue du diff** (grilles ECC adaptées au C#) :
   - *security-reviewer* : le jeton d'appareil est chiffré (DPAPI, utilisateur courant) et **jamais
     journalisé** ; l'agent ne parle qu'à l'adresse fixée à l'enrôlement ; l'URL reçue de Windows est
     analysée strictement (`AnalyseUrlKdocs`) et n'exécute ni commande ni chemin ; le nom du document est
     réduit au basename (`RacineAgent.NomSain`) — aucune traversée ; le registre n'est écrit que sous
     `HKCU\Software\Classes\kdocs` ; les appels Office ne modifient jamais le document de l'utilisateur
     (`Content.WordOpenXML` en lecture, `SaveCopyAs`) ; `Process.Start` ne lance qu'un fichier du dossier
     de travail ou un dossier fixe. Les journaux assainissent les liens (contrôle retiré).
   - *silent-failure-hunter* : les seuls `catch` sont ceux qui TRACENT ou choisissent un repli sûr
     (journal sur disque avec repli débogueur ; configuration/fiches/file illisibles signalées et jamais
     de crash ; session Office fautive tracée ; canal IPC tracé). Aucun `catch` muet dans le code de
     l'agent.
   - *php-reviewer (adapté)* : `file-scoped namespaces`, types et `readonly` posés, méthodes courtes,
     aucun `var_dump`/`Console.WriteLine` de debug, pas de chaîne magique répétée.
3. **TDD** : chaque brique a son oracle écrit avant le code (analyse d'URL vue rouge par compilation,
   file d'envoi, copies de secours, services d'icône, gestionnaire d'URL) ; les oracles Office et le
   parcours F13 ont été vus rouges puis verts. Aucune assertion affaiblie, aucune sonde retirée.

## Limites connues

- Les tests F13 utilisent la base `gedv2_test` et démarrent un serveur PHP sur le port 8873 ; ils exigent
  Office installé. Aucun test ne tue une instance Office déjà ouverte : les documents temporaires sont
  ouverts par l'association et fermés seuls ; les oracles ne quittent que les instances qu'ils ont créées.
- Les oracles Excel/PowerPoint créent une instance dédiée (visible dans la ROT quand aucun Office de ce
  type n'est déjà ouvert) ; si un Excel/PowerPoint de l'utilisateur est déjà ouvert, l'instance créée peut
  ne pas apparaître dans la ROT (constat sur Word) — l'oracle Word, lui, passe par l'association Windows.
- `--enrolement` et l'agent d'icône sont fournis ; l'installeur PowerShell « sans droits admin » évoqué au
  cahier des charges n'est pas livré par ce lot (le protocole s'enregistre au démarrage, HKCU).
