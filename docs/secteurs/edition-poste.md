# Secteur Édition depuis le poste (agent local K-Docs)

10-10-2026 · décision d'Olivier, conçu par le juge. Reprend la session du 04-10 (« GED - adoption webdav ») et la
complète par le point posé le 10-10 : ne JAMAIS perdre une modification, même jamais enregistrée par l'utilisateur.

## Pourquoi

C'est l'écart principal avec M-Files : ouvrir un document de la GED dans Word ou Excel, travailler, et ne rien
perdre, sans check-in, sans lien qui expire, sans session web qui tombe. WebDAV est écarté (hébergement mutualisé
Infomaniak, verrous WebDAV inadaptés, comportement hors ligne de Word non fiable).

## Principe

Un petit agent sur le poste (barre des tâches), enrôlé une fois. Word n'ouvre jamais qu'un fichier local.

1. **Protocole `kdocs://`** (doc Microsoft « Registering an Application to a URI Scheme ») : clé sous
   `HKCU\Software\Classes\kdocs`, sans droits administrateur. Le lien ne porte qu'une action et un identifiant
   numérique (`kdocs://ouvrir/1234`) ; tout le reste est refusé. L'agent ne parle qu'au serveur de sa configuration
   et n'exécute rien qui vienne de l'URL.
2. **Pas de session web** : l'agent utilise le jeton d'appareil de `Jonctions\Appairage` (code unique + code de
   vérification), haché en base, révocable depuis « Mon profil › Appareils reliés ». Plus aucun délai d'expiration
   ne peut faire perdre un enregistrement.
3. **Dossier de travail** `%LOCALAPPDATA%\K-Docs\Travail\<id>\<nom>` : l'agent télécharge, puis ouvre le fichier
   comme un double-clic.
4. **Chaque Ctrl+S devient une version** : surveillance du dossier, attente d'un fichier stable (Word enregistre
   par fichier temporaire puis renommage), envoi avec la version de base. File d'envoi persistante, reprise après
   coupure réseau ou redémarrage ; la copie locale n'est effacée qu'après confirmation de l'empreinte par le serveur.
5. **Conflit** : si le document a changé ailleurs (GED, partage réseau, autre poste), on crée une version parallèle
   signalée, jamais un écrasement.
6. **Verrou informatif** : « ouvert par X sur POSTE-03 depuis 14 h 10 », entretenu par un signal régulier de
   l'agent, levé à la fermeture ; sans signal, il passe en « peut-être abandonné ». Aucun déverrouillage manuel.

## Copies de secours : rien de non enregistré ne se perd

Demande d'Olivier du 10-10 : l'agent sauvegarde lui-même, régulièrement, le document en cours dans un dossier à part.

- Toutes les 2 minutes, l'agent s'attache à Office par son interface d'automatisation (COM) et, pour chaque
  document ouvert venant de son dossier de travail et modifié depuis la dernière copie (`Saved = False`), écrit une
  **copie de secours** dans `%LOCALAPPDATA%\K-Docs\Secours\<id>\` SANS toucher au document de l'utilisateur :
  - Excel : `Workbook.SaveCopyAs` (« saves a copy … but doesn't modify the open workbook in memory ») ;
  - PowerPoint : `Presentation.SaveCopyAs` (« without modifying the original ») ;
  - Word : pas de `SaveCopyAs` dans l'objet `Document` (vérifié sur learn.microsoft.com). On lit
    `Document.Content.WordOpenXML` (lecture seule, aucun enregistrement) et on écrit un .docx à partir de ce
    paquet Flat OPC. À prouver par oracle : en-têtes, pieds de page, commentaires et images conservés ; sinon
    repli documenté.
- Une copie de secours n'est PAS une version : elle ne remplace rien et ne change pas l'état « enregistré » du
  document. L'utilisateur garde la main sur ce qu'il valide (fermer sans enregistrer reste possible).
- La dernière copie de secours de chaque document part aussi au serveur, dans un espace de secours de l'application,
  hors du fonds (pas dans le dossier du client). Ainsi, même un portable volé ou un disque mort ne fait rien perdre.
- Si Word se ferme sans enregistrer, plante, ou si le poste redémarre : l'agent propose « Récupérer la copie de
  secours de 14 h 32 » dans « Mes documents en cours » et dans la fiche du document.
- Office refuse parfois un appel (boîte de dialogue ouverte, saisie de cellule en cours) : `RPC_E_CALL_REJECTED`
  ou `RPC_E_SERVERCALL_RETRYLATER`. L'agent enregistre un `IOleMessageFilter` (CoRegisterMessageFilter) et
  réessaie plus tard ; il n'interrompt jamais l'utilisateur.
- Complément hors agent : stratégie de groupe Office, récupération automatique toutes les 1 à 2 minutes.

## Technique retenue

- Agent en C# / .NET 10 (LTS), un seul exécutable signé : COM Office natif, registre, `FileSystemWatcher`, icône
  de barre des tâches, Open XML SDK pour la copie Word. Python + PyInstaller écarté : faux positifs fréquents des
  antivirus (GravityZone chez les clients) et runtime à embarquer. Installateur PowerShell sur le modèle du bridge
  WinBiz de K-Time (installation sans droits administrateur).
- Office n'est piloté que dans la session de l'utilisateur (Microsoft ne prend pas en charge l'automatisation
  d'Office depuis un service) : l'agent est une application de session, pas un service Windows.
- Côté serveur : aucune nouvelle table (plafond 21) ; l'ouverture est un événement de `document_collaboration`
  (type `ouverture`), le signal régulier le prolonge ; dépôt de version avec version de base ; route de copie de
  secours ; bouton « Ouvrir dans Word » (`kdocs://ouvrir/<id>`) et « ouvert par … » dans la fiche et la Bibliothèque.

## Flux F13 et oracles

F13 — Éditer depuis le poste sans rien perdre. Porte : sur un poste avec Office réel,
1. ouvrir depuis la GED → Word ouvre le fichier local, la GED affiche « ouvert par … » ;
2. Ctrl+S → nouvelle version côté serveur, empreinte identique ;
3. réseau coupé, deux Ctrl+S, redémarrage de l'agent, réseau rétabli → deux versions reçues dans l'ordre ;
4. modification jamais enregistrée puis Word tué → copie de secours récupérable, contenu identique ;
5. même document modifié dans la GED pendant l'édition → version parallèle signalée, rien d'écrasé ;
6. lien `kdocs://` piégé (chemin, autre serveur, identifiant non numérique, document sans droit) → refusé ;
7. appareil révoqué → plus rien ne passe, les copies locales restent.

## Lots

- `ged-edition-serveur` (machine 2, file des lots) : routes, ouverture informative, version de base et conflit,
  espace de secours, boutons ; tests E2E par l'écran.
- `ged-agent-poste` : l'agent C#, ses tests unitaires et le parcours F13 avec Word réel.
- Plus tard (après 100 % opérationnel) : explorateur « fichiers à la demande » (API Windows Cloud Files).

## Chemins

Chemins que l'agent du lot `ged-edition-serveur` est autorisé à modifier (un agent = un secteur) :

- `src/Edition/` — **nouveau secteur** : service d'édition, API jeton d'appareil, espace de secours,
  ouvertures informatives, `Module`.
- `src/Kernel.php` — une ligne : le secteur `Edition` rejoint la liste des modules (aucune autre
  modification ; même geste minimal que l'ajout de `Ui`, signalé ici).
- `src/Securite/DroitsRoutes.php` — déclaration des routes du lot (toute route doit y figurer) :
  API sous jeton (`publique:api-edition`) et route de session « Récupérer une copie de secours ».
- `src/Ui/Document.php`, `src/Ui/Ecrans.php`, `templates/ecrans/document.twig` — bouton
  « Ouvrir dans Word/Excel/PowerPoint/le poste », « ouvert par … », liste des copies de secours.
- `src/Socle/EcranBibliotheque.php`, `templates/ecrans/bibliotheque.twig` — « ouvert par … » dans la
  liste et l'aperçu.
- `docs/secteurs/edition-poste.md`, `docs/lots/ged-edition-serveur.md`, `.env.example` (SECOURS_ROOT).
- `tests/php/Edition/`, `tests/e2e/edition-serveur.spec.ts`, `tests/e2e/preparer-edition-serveur.php`,
  `tests/e2e/README.md`.
- `tools/banc/sonde-securite.php` et `tools/banc/contrat.php` — une entrée par route déclarée (charge
  CSRF défensive, statut attendu sans jeton) : la sonde et le contrat EXIGENT la couverture de toute
  route nouvelle, ces deux ajouts sont donc la contrepartie obligatoire de `DroitsRoutes`.

Le stockage de secours de l'application vit hors du fonds (`SECOURS_ROOT`, défaut `var/secours`) et
n'est jamais une version : aucune ligne de `document_versions`, aucun déplacement, aucune nouvelle table
(plafond 21 tables inchangé).

Chemins du lot `ged-agent-poste` (agent C# côté poste, même secteur) :

- `agent-poste/` — solution .NET 10 : projet agent (`src/Kdocs.AgentPoste`, `net10.0-windows`) et projet
  de tests xUnit (`tests/Kdocs.AgentPoste.Tests`). Parcours F13 : `tests/parcours/preparer-f13.php`,
  `tools/verifier.ps1`.
- `docs/lots/ged-agent-poste.md` — note d'avancement du lot.
- Rien d'autre : ni `src/` PHP, ni `templates/`, ni `tools/banc/` (le contrat HTTP du serveur est
  consommé tel quel, aucune modification n'est demandée).

Chemins du lot `ged-agent-durcissement` (même secteur, durcissement bloquant du constat du juge) :

- `agent-poste/` — liste blanche d'extensions (`ExtensionsEditables`), refus tracé et visible
  (`RefusOuverture`, `GestionnaireTravail`, `ServiceDocumentsEnCours`, tray), tests xUnit, et
  installateur PowerShell sans droits administrateur (`agent-poste/tools/installer-agent.ps1`,
  `agent-poste/tools/tester-installateur.ps1`).
- `src/Edition/Formats.php` — **nouveau** : la liste blanche côté serveur, en un seul endroit
  (sections Word/Excel/PowerPoint) ; `src/Edition/Pieces.php` et `src/Edition/Api.php` s'y adossent
  (bouton et API d'ouverture/version : 403 nommé hors liste).
- `src/Ui/Ecrans.php`, `templates/ecrans/document.twig` — le bouton « Ouvrir dans … » n'apparaît que
  pour un format éditable (`peut_ouvrir_poste`).
- `src/Socle/EcranBibliotheque.php` — même règle pour l'aperçu de la Bibliothèque.
- `tests/php/Edition/`, `tests/e2e/edition-serveur.spec.ts`, `tests/e2e/preparer-edition-serveur.php`.
- `docs/secteurs/edition-poste.md`, `docs/lots/ged-agent-durcissement.md`.

## Demandes

- **ged-agent-poste** : le contrat HTTP attendu par l'agent (jeton d'appareil `Authorization: Bearer …`,
  portée `depot`, lié à un compte réel) est :

  | Geste | Route | Corps | Réponse |
  | --- | --- | --- | --- |
  | Télécharger | `GET /api/v1/edition/documents/{id}/fichier` | — | le fichier, en-têtes `X-Ged-Empreinte`, `X-Ged-Version` |
  | État | `GET /api/v1/edition/documents/{id}` | — | `{document, nom, mime, taille, empreinte, version}` |
  | Nouvelle version | `POST …/{id}/versions` | multipart `fichier` + `version_base` | `{version, empreinte, conflit, version_avant, deja}` |
  | Ouverture | `POST …/{id}/ouverture` | — | `{ouverture:{id, auteur, poste, depuis}}` |
  | Signal | `POST …/{id}/signal` | — | `{prolonge:true}` (faux si aucune ouverture active) |
  | Fermeture | `POST …/{id}/fermeture` | — | `{ferme:true}` |
  | Copie de secours | `POST …/{id}/secours` | multipart `fichier` | `{appareil, quand, empreinte, taille, nom}` |
  | Copies disponibles | `GET …/{id}/secours` | — | `{copies:[…]}` |

  Le poste est celui du jeton (`jonctions_jetons.appareil`), jamais une valeur du corps. Un jeton
  révoqué, inconnu ou lié à un autre dossier est refusé (401/403 nommés).
- **Vue d'ensemble** : aucune modification de contrat n'est demandée ; `Edition` utilise les contrats
  existants (`Socle\DepotFichiers`, `Jonctions\Jetons`, `Securite\DroitsDossiers`) et la table
  `document_collaboration` (aucune DDL, plafond 21 inchangé).
## Jugement lot ged-edition-serveur (juge, 11-10) — fusionné

- Relu sur la copie du juge après fusion de v2 (fusion sans conflit) : PHPStan niveau 6 sans erreur ; PHPUnit 509 tests, un échec de test corrigé par le juge (séparateurs de chemin Windows dans l'assertion « secours hors du fonds »), puis vert.
- API du poste (`src/Edition`) : authentifiée par le jeton d'appareil (`Jonctions\Jetons`, portée dépôt, compte appairé obligatoire), jamais par la session web ; droits vérifiés par document (`lire` pour télécharger, `modifier` pour ouvrir, signaler, déposer une version ou une copie de secours). Le dépôt de version garde le même chemin (aucun déplacement, donc pas de contrôle d'arrivée en jeu) ; le contenu courant est archivé avant remplacement ; une base périmée crée une version parallèle tracée, rien n'est perdu.
- Ouvertures informatives dans `document_collaboration` ; copies de secours sous `SECOURS_ROOT`, hors du fonds, une par document et par appareil, sans table. Aucune nouvelle table, aucun DDL hors installateur.
- Reste : l'agent du poste (lot ged-agent-poste) et le parcours F13 complet avec Word réel.
## Jugement lot ged-agent-poste (juge, 11-10) — fusionné, NON distribuable avant durcissement

- Rejoué sur une copie de juge indépendante (machine 2, `C:\ged\juge`, session interactive pour Office) : `dotnet test agent-poste/AgentPoste.slnx` **130 tests, 0 échec** (unitaires, oracles Office réels Word/Excel/PowerPoint, parcours F13 contre le serveur de test). Aucun changement PHP dans le lot.
- Relu : analyse `kdocs://` stricte (longueur bornée, action en liste blanche, identifiant numérique sans zéro initial), jeton d'appareil chiffré DPAPI, adresse du serveur fixée à l'enrôlement, copies de secours sans toucher au document (`SaveCopyAs` pour Excel/PowerPoint, `Content.WordOpenXML` → .docx par Open XML SDK pour Word), filtre `IOleMessageFilter`.
- **Défaut bloquant trouvé à la relecture** : l'agent ouvre le fichier téléchargé par `Process.Start` avec `UseShellExecute` sans liste blanche d'extensions. Un document `.exe`, `.js`, `.bat`, `.lnk`… déposé dans la GED par quelqu'un qui a le droit de dépôt serait EXÉCUTÉ sur le poste de celui qui l'ouvre (lien `kdocs://` piégé ou bouton). Lot `ged-agent-durcissement` lancé : liste blanche d'extensions de documents (côté agent ET côté serveur : bouton et API d'ouverture refusés pour tout autre type), plus l'installateur PowerShell sans droits administrateur annoncé et non livré.
- Tant que ce lot n'est pas jugé, l'agent ne doit être installé sur aucun poste client.
## Jugement lot ged-agent-durcissement (juge, 11-10) — fusionné, défaut bloquant levé

- Rejoué de façon indépendante : `dotnet test` sur `C:\ged\juge` (machine 2, session interactive, Office réel) **220 tests, 0 échec** ; installateur rejoué par le juge (`tester-installateur.ps1` : installation HKCU, clés vérifiées, désinstallation propre, données de l'utilisateur conservées, état HKCU restauré) ; côté PHP sur la copie du juge du poste : PHPStan 0 erreur, PHPUnit **563 tests / 3119 assertions** vert.
- Liste BLANCHE identique des deux côtés (`Ged\Edition\Formats`, `Kdocs.AgentPoste.Travail.ExtensionsEditables`) : doc, docx, xls, xlsx, ppt, pptx, odt, ods, odp, rtf, txt, csv. Exclus : formats à macros (docm, xlsm, pptm, xlsb), modèles, exécutables, scripts, raccourcis, nom sans extension. Oracles : double extension, flux alternatif, majuscules, points et espaces finaux. Le bouton et l'API de l'agent refusent tout autre format (403 nommé) ; la récupération d'une copie de secours ne rouvre pas un format refusé.
- Le défaut bloquant du jugement précédent est levé : l'agent peut être installé sur un poste pilote.
- Risque résiduel, à traiter côté déploiement et non dans le code : les formats binaires anciens (doc, xls, ppt) et rtf peuvent porter des macros ou des objets OLE. Office les ouvre selon le Centre de gestion de la confidentialité du poste ; recommandation pour les clients : stratégie de groupe « désactiver les macros VBA sauf celles signées » (ou sans notification), à poser avec l'installation de l'agent.