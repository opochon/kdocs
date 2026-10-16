# Plan de bataille GEDv2

29-09-2026 · Olivier Pochon

GEDv2 repart d'un dépôt propre, sur le framework EcosystemK de K-Time, avec le moteur d'ingestion de GEDv1 (le seul morceau mesuré au-dessus du marché), les fonctions de Paperless-ngx comme cahier des charges et M-Files/RedX comme cible. L'interface et le harness sont posés avant la première fonction ; chaque lot se ferme sur un oracle rejoué, jamais sur une déclaration.

## 1. Constat

GEDv1 compte 66 000 lignes de PHP, 126 tables, 65 contrôleurs, 93 gabarits et 151 fichiers de documentation, pour une poignée de fonctions réellement mesurées. Paperless-ngx 2.20.5, dont elle est partie, reste loin devant comme produit ; GEDv1 ne le dépasse que sur l'ingestion d'un lot de courrier non trié.

| Morceau de GEDv1 | Preuve | Dans GEDv2 |
| --- | --- | --- |
| Moteur de découpe `cmd4_ingest` (partagé avec CMD v4) | lot de 100 pages : 53/53 exacts, 0 fausse coupe | repris tel quel, comme service |
| Classement corroboré (IA gemma-4-31B + indice dans le texte) | 52 validés seuls, 0 faux | porté, réécrit sur le framework |
| Habitudes (3 choix identiques = règle) | preuve banc du 28-09 | porté |
| Rangement année / type / émetteur | 52/53 rangés | porté |
| Banc : base vierge, rejeu, mesure contre vérité | 10 rejeux du 27 au 28-09 | devient le harness de GEDv2 |
| Corpus synthétique à vérité écrite | `courrier-100p`, 13 pièces de base | repris et élargi |
| Écrans, menus, 126 tables, workflows, droits, recherche | « déclaré », jamais rejoué | laissés ; servent de liste de courses |

Le dépôt GEDv1 reste intact et lisible, comme K-TIME l'est resté pour k-time-v1.

## 2. Décision de squelette

Recommandation : **GEDv2 sur le framework EcosystemK de K-Time** (PHP 8.4, MariaDB), Paperless servant de cahier des charges et d'oracle d'ergonomie, sans une ligne de son code. C'est la seule option qui garde « la base est un dossier » et l'hébergement Infomaniak, et qui ne réinvente ni l'installateur, ni la chaîne de release, ni le registre de maturité.

| Critère | A. Framework EcosystemK (recommandé) | B. Fork de Paperless-ngx | C. Neuf en Python/Django |
| --- | --- | --- | --- |
| Parité fonctionnelle au départ | 0 : tout à construire | immédiate | 0 |
| Licence | la tienne | GPLv3 : toute installation chez un client oblige à livrer les sources | la tienne |
| Hébergement Infomaniak mutualisé | oui, comme K-Time | non : Redis, Celery, torch, ~3 Go | non sans VPS |
| « La base est un dossier » | cœur du modèle | contraire : la base Django fait foi | possible |
| Jonctions K-Time, ClearMyMails, module OCR partagé | natives (même framework, mêmes comptes) | par API seulement | par API |
| Installateur, release, console éditeur, maturité, HDOC | déjà faits dans K-Time | à refaire | à refaire |
| Suivre l'amont | sans objet | chaque version Paperless à refusionner | sans objet |

À trancher par toi : **A ou B**. Tout le reste de ce plan suppose A.

Dépôt `F:\DATA\DEVELOPPEMENT\GEDv2`, base `gedv2`, base de tests `gedv2_test` (le 28-09, les tests de GEDv1 écrivaient dans la vraie base : erreur à ne pas refaire).

## 3. Architecture

```
          Dossier racine (SOURCE DE VÉRITÉ, lisible sans l'application)
          2026/Facture/Atelier_X/facture_p008.pdf
                              facture_p008.pdf.meta.json   <- métadonnées à côté du fichier
                              .versions/facture_p008.v1.pdf
                 │  surveillé (jobs courts à reprise)            ▲ rangement
                 ▼                                              │
  ┌──────────────────────────┐   texte, pièces   ┌──────────────┴────────────┐
  │ Agent d'ingestion        │ ────────────────► │ GEDv2 (framework EcosystemK│
  │ cmd4_ingest (Python)     │                   │ PHP 8.4)                   │
  │ découpe, OCR, QR         │ ◄── lot déposé ── │ Index MariaDB              │
  │ sur le poste client ou   │                   │  (RECONSTRUCTIBLE depuis   │
  │ un VPS, appel SORTANT    │                   │   le dossier)              │
  └──────────────────────────┘                   │ Classement, habitudes,     │
                                                 │ workflows, droits, audit   │
  Infomaniak AI (Suisse) ◄──────────────────────►│ 6 écrans maîtres + mobile  │
  gemma-4-31B : classement, vision               └──────┬──────────┬──────────┘
                                                        │          │
                                             K-Time (factures)  ClearMyMails (courriels)
```

- **Le dossier fait foi.** Chaque document a ses métadonnées dans un fichier `.meta.json` à côté de lui ; la base n'est qu'un index. Une commande reconstruit la base entière depuis le dossier : c'est l'oracle F12 et la différence avec Paperless comme avec M-Files.
- **L'agent d'ingestion** tourne là où Tesseract et Python existent (poste client, NAS, VPS) et appelle GEDv2 en sortant, sur le modèle du bridge WinBiz : aucun port ouvert chez le client, GEDv2 reste hébergeable en mutualisé.
- **Aucun appel PHP long** : tout traitement lourd en jobs courts avec point de reprise (règle I/O batch de K-Time).

## 4. Interface pensée avant le code

Six écrans maîtres, maquettés et validés par toi avant la première ligne de métier ; tout autre écran est une variante de l'un d'eux, servi par les gabarits du framework.

| Écran | Ce qu'on y fait | Pris chez |
| --- | --- | --- |
| **À traiter** | bilan en tête (classés seuls ≥ 85 %, à contrôler, en cours) ; un document à la fois, grand aperçu, suggestions pré-remplies avec leur source (IA, habitude, règle) ; valider au clavier ; couper, fusionner, tourner des pages ici | GEDv1 (bilan), Paperless (édition de pages) |
| **Bibliothèque** | trois volets : vues et arbre à gauche, liste au centre (100 lignes puis défilement), aperçu et fiche à droite, sans fenêtre modale | M-Files (vues), Paperless (liste) |
| **Recherche** | une barre partout (Ctrl+K), plein texte surligné, filtres par métadonnées, « documents semblables », vues enregistrées | Paperless |
| **Fiche document** | aperçu, métadonnées, versions, historique, notes, liens entre documents, partage, workflow en cours | M-Files, Paperless |
| **Workflows** | gabarits prêts (facture à seuil, contrat à échéance), déclencheurs et actions, designer de nœuds, essai à blanc sur un vrai document | Paperless (déclencheurs), M-Files (états), GEDv1 (designer) |
| **Application / Administration** | Application : dossiers d'ingestion, stockages, IA, connecteurs, droits. Administration : types, champs, étiquettes, correspondants | découpe décidée pour K-Time le 16-09 |

Règles tenues sur tous les écrans, chacune vérifiée par un test :

- barre d'actions à gauche sous le titre ; écrans compacts, la donnée visible sans défiler ;
- une seule source d'entrées de menu, rendue en haut ou à gauche selon la place ;
- le dossier d'ingestion n'apparaît jamais, seulement le bilan ;
- tout élément actionnable porte un `data-testid` ; aucun écran au-delà de 1,5 s ;
- une erreur se nomme (étape, cause, rapport transmissible), jamais un 500 muet ;
- le mobile a ses propres écrans : photo d'un document vers l'ingestion, consultation, validation.

## 5. Matrice des fonctions

La cible est « Paperless partout, M-Files là où il va plus loin, GEDv1 là où elle est déjà devant ». Niveaux 0 à 9 puis P et V1, calculés par les tests, jamais saisis à la main. Colonne Paperless lue dans ses sources 2.20.5 ; colonne M-Files de mémoire, à vérifier chez RedX.

| Fonction | Paperless 2.20 | M-Files / RedX | GEDv1 mesuré | Cible GEDv2 | Lot |
| --- | --- | --- | --- | --- | --- |
| Dossier d'ingestion surveillé | oui | capture | 6 | 9 | V2-02 |
| Découpe d'un lot sans séparateur | non (codes-barres seulement) | séparation de lots | 9 (53/53) | 9 | V2-02 |
| Regroupement de pages égarées | non | — | 9 | 9 | V2-02 |
| OCR, PDF/A interrogeable | oui (ocrmypdf) | oui | texte seul, pas de PDF/A | 9 | V2-02 |
| Facture QR suisse | non | via module | 6 | 9 + lignes K-Time | V2-02, V2-08 |
| Classement automatique | apprentissage sur le fonds ; IA en suggestion à la demande | IA (Aino) | 8 (52/53, 0 faux) | 9 | V2-03 |
| Apprentissage des choix | ré-entraînement sur tout le fonds | — | 5 (habitudes par émetteur) | habitudes + apprentissage sur le fonds | V2-03 |
| File de validation | liste « inbox » | boîte de réception | 3 | 9, au clavier | V2-03 |
| Édition de pages (couper, fusionner, tourner, supprimer) | oui | partiel | 0 | 9 | V2-03 |
| Métadonnées, champs personnalisés, types | oui | cœur du produit | 4 | 9 | V2-04 |
| Vues enregistrées, vues par métadonnées | oui | cœur du produit | 4 | 9 | V2-04 |
| Rangement sur disque lisible sans l'application | modèle de chemin, mais la base fait foi | non (coffre) | 8 | 9 : le dossier fait foi | V2-01 |
| Versions | non (original + archive) | oui, avec réservation | 6 (déclaré) | 9 : `.versions/` à côté du fichier | V2-01 |
| Plein texte, surlignage, semblables | oui (Whoosh) | oui | 3 | 9 | V2-05 |
| Aperçu PDF, bureautique, images | oui | oui | 4 | 9 | V2-05 |
| Workflows déclencheurs + actions | oui (4 déclencheurs) | états + transitions | 2 | les deux + designer | V2-06 |
| Droits par objet, groupes | oui | oui | 4 (déclaré) | 9 | V2-07 |
| Journal d'audit, corbeille, zéro suppression | oui | oui | 5 | 9, chaîné par hash | V2-07 |
| Liens de partage, notes | oui | oui | 0 | 9 | V2-07 |
| Import de messagerie | règles IMAP | oui | 1 | pont ClearMyMails | V2-08 |
| Archivage légal, rétention | non | oui | 2 | 9 | V2-09 |
| API, applications mobiles | oui | oui | 2 | API + écrans mobiles | V2-05, V2-10 |
| Installation, mise à jour, console éditeur | Docker | intégrateur | 2 | celles de K-Time | V2-00 |
| Modification en masse (type, étiquettes, émetteur, dossier, corbeille) | oui | oui | 0 | 9, droits respectés | ged-parite-masse |
| Édition depuis le poste sans rien perdre (Word, Excel, PowerPoint) | non | oui (client Desktop, réservation) | 0 | 9 : agent local, `kdocs://`, versions à chaque enregistrement, copies de secours | ged-edition-serveur, ged-agent-poste, ged-edition-poste-2 (réservation, ruban Office, OnlyOffice) |

## 6. Matrice des flux

Un flux n'est au niveau 9 que s'il porte deux preuves vertes : le mécanisme sur le corpus de test, et la reprise sur un vrai fonds anonymisé. Chaque ligne a son oracle écrit avant le code.

| # | Flux | De bout en bout | Oracle | Porte |
| --- | --- | --- | --- | --- |
| F1 | Lot scanné déposé dans le dossier | dépôt → découpe → lecture → classement → rangement → bilan | `verite.json` par lot | 53/53, 0 validé faux, < 10 min |
| F2 | Fichier posé par l'écran ou le mobile | même chaîne que F1, lancée à la main | même vérité | texte lu 100 %, écran à jour sans recharger |
| F3 | Courriel entrant | ClearMyMails → pièce jointe → F1 | boîte de test à vérité | 0 pièce perdue, 0 doublon |
| F4 | Validation à la main | À traiter → correction → rangement → habitude | partition jouée par un persona | 3 choix = habitude, un revirement la casse |
| F5 | Retrouver un document | barre → plein texte → filtres → aperçu | liste requêtes → documents attendus | 100 % des attendus en tête, < 1 s |
| F6 | Modifier un fichier | ouverture → enregistrement → nouvelle version → retour arrière | hash de chaque version | aucune version perdue, restauration exacte |
| F7 | Dossier modifié hors de l'application | copie ou déplacement dans l'explorateur → index à jour | arbre attendu après manipulation | la base suit le dossier, jamais l'inverse |
| F8 | Facture fournisseur à valider | classement → workflow à seuil → visa → envoi à K-Time | partition + montants | le bon valideur selon le montant, K-Time reçoit la pièce une fois |
| F9 | Contrat à échéance | champ échéance → rappel planifié → notification | horloge figée | le rappel part le bon jour |
| F10 | Droits | un groupe ne voit que son dossier, partout (liste, recherche, aperçu, API) | matrice qui voit quoi | 0 fuite, refus côté serveur |
| F11 | Archivage légal | scellement → déplacement hors du fonds → horodatage → rétention | hash + jeton d'horodatage | pièce intouchable pendant la durée légale |
| F12 | Sauvegarde, restauration, reconstruction | base perdue → reconstruite depuis le dossier ; dossier + base → restauration sur base vierge | comparaison octet par octet | restauration identique, index reconstruit identique |
| F13 | Éditer depuis le poste | GED → `kdocs://` → Word local → Ctrl+S → version ; coupure, redémarrage, fermeture sans enregistrer | parcours sur Office réel (docs/secteurs/edition-poste.md) | aucune modification perdue, aucun écrasement, lien piégé refusé |
| F13b | Crash tests de l'édition depuis le poste | agent tué en plein envoi, coupure de courant, serveur en panne, disque plein, Word figé, deux postes, réservation forcée, document déplacé, redémarrage forcé, horloge décalée, OnlyOffice tué, jeton révoqué | douze cas joués sur Office réel (docs/secteurs/edition-poste.md, section D) | chaque enregistrement local retrouvé en version, zéro version tronquée, zéro perte, zéro écrasement |

## 7. Oracles et corpus

Tout oracle est écrit et vu rouge avant le code qu'il juge. Zéro donnée réelle, zéro nom de client dans le dépôt : corpus synthétiques générés, noms fictifs ; la reprise sur un vrai fonds se fait en local, anonymisée, jamais commitée.

| Oracle | Contenu | Juge |
| --- | --- | --- |
| Lots d'ingestion | `courrier-100p` (fait) + un lot par difficulté : photos smartphone, pages à 90° et 180°, versos blancs, factures QR multipages, manuscrit, DE/IT/EN, trois factures aux pages mélangées, doublons, bureautique (docx, xlsx, msg, eml), PDF de 300 pages, PDF protégé | F1, F2, F3 |
| `recherche.json` | requêtes → documents attendus et leur rang | F5 |
| `droits.json` | qui voit, modifie, supprime quoi | F10 |
| Versions et arbres | hash de chaque version ; arbre attendu après manipulations hors application | F6, F7, F12 |
| Partitions | scénarios rejouables, horloge figée, montants fixes, joués par plusieurs rôles | F4, F8, F9 |
| Parcours Paperless | la même tâche chronométrée dans Paperless et GEDv2 (clics, secondes), dès que Paperless tourne quelque part (Docker ne démarre pas sur ce poste, WSL n'a pas de réseau) | ergonomie |

## 8. Harness

Une commande, `harness.ps1 tout`, rejoue tout sur une base vierge et produit un rapport par secteur ; le registre de maturité est écrit par ces runs, jamais à la main. Règle anti-mensonge : une ligne sans test vaut 0, une absence d'erreur ne vaut pas validation.

| Couche | Ce qu'elle prouve | Outil |
| --- | --- | --- |
| 0. Garde-fous | on tourne bien sur le code, le stockage et la base de test (leçon OPcache du 27-09) | sonde au démarrage |
| 1. Unitaire | services, règles de classement, moteur | PHPUnit sur `gedv2_test`, pytest |
| 2. Contrat | chaque route : statut, schéma, droits | tests d'API |
| 3. Banc | base vierge → rejeu des corpus → mesure contre vérité | banc de GEDv1 porté |
| 4. Tour complet | chaque écran, chaque bouton, chaque formulaire ; 0 erreur console, 0 500, capture par écran | Playwright |
| 5. Personas | fiduciaire, secrétaire, comptable, administrateur, auditeur, débutant jouent F1–F12 ; une lecture de capture dit trouvé ou pas trouvé et en combien de temps | Playwright + vision |
| 6. Charge | 3 à 10 personas simultanés, dont 2 ou 3 en écriture | Playwright |
| 7. Sécurité | injections, droits contournés, chemins hors racine, secrets | sonde offensive (Codex) |
| 8. Registre | niveau 0–9/P/V1 par ligne des deux matrices | calculé par 1–7 |

## 9. Agents

Un agent par secteur, lancé seul, qui connaît le dépôt et le framework et rapporte ; un agent de vue d'ensemble ; pas de dispatcher. Celui qui écrit un lot ne le juge jamais : la porte est rejouée par un autre agent.

| Secteur | Agent | Pourquoi lui | Juge de la porte |
| --- | --- | --- | --- |
| Socle : dossier, `.meta.json`, versions, index reconstructible, installateur | GLM | rapide et bon quand le framework cadre, comme sur K-Time | Codex |
| Ingestion : agent, moteur, OCR, découpe | Claude Code | connaît le moteur et le banc, a mesuré les 10 rejeux | GLM |
| Classement, habitudes, apprentissage sur le fonds | Claude Code | a écrit la chaîne corroborée | DeepCode |
| Interface : 6 écrans, gabarits, mobile | GLM sur maquettes validées | gabarits du framework K-Time | Claude Code (personas) |
| Recherche, aperçu, API | DeepCode | secteur isolé, contrat clair | Codex |
| Workflows et designer | GLM | gabarits + partitions | DeepCode |
| Droits, audit, sécurité | Codex | sonde adverse | Claude Code |
| Jonctions K-Time, ClearMyMails | Claude Code | connaît les deux dépôts | GLM |
| Harness et oracles | Claude Code | porte le banc existant | chaque secteur le rejoue |
| Vue d'ensemble : matrices, registre, `ETAT:` | Claude Code | ne code pas les secteurs, les mesure | toi |

Chaque lot = un prompt court : le contrat commun (dépôt, bases, interdits), les contraintes atomiques du lot, l'oracle à faire passer du rouge au vert, un commit par étape, une ligne `ETAT:` en fin de lot.

## 10. Lots et portes

```
Semaine →   1        2        3        4        5        6        7        8        9       10
MAQUETTES  ███ 6 écrans validés par toi
V2-00           ███ squelette (framework, bases, harness rouge, installateur)
V2-01                ████ dossier source, .meta.json, versions, index reconstructible   [F6 F7 F12]
V2-02                ████████ ingestion portée + nouveaux lots                          [F1 F2]
V2-04                     ██████ métadonnées, types, champs, vues
V2-03                          ██████ À traiter, habitudes, édition de pages            [F4]
V2-05                               ██████ bibliothèque, recherche, aperçu, API         [F5]
V2-07                               ██████ droits, audit, partage, notes                [F10]
V2-06                                     ██████ workflows + designer                   [F8 F9]
V2-08                                          █████ K-Time, ClearMyMails               [F3 F8]
V2-09                                               █████ archivage légal, rétention    [F11]
V2-10                                                    ██████ mobile, charge, RC      [tout]
```

| Lot | Porte (rejouée par un autre agent) |
| --- | --- |
| Maquettes | les 6 écrans cliquables, validés par toi ; les parcours F1–F12 y sont jouables |
| V2-00 | installation sur base vierge ; tour Playwright vert sur les écrans vides ; registre à 0 partout, harness qui tourne |
| V2-01 | F6, F7, F12 verts : base supprimée puis reconstruite identique depuis le dossier |
| V2-02 | F1, F2 : 53/53 et 0 validé faux sur chaque lot du corpus |
| V2-03 | F4 ; validation d'un lot de 50 pièces au clavier sans souris |
| V2-04 | champs et vues créés par un persona, sans aide |
| V2-05 | F5 : attendus en tête, < 1 s sur 10 000 documents |
| V2-06 | F8, F9 sur horloge figée ; essai à blanc sans effet de bord |
| V2-07 | F10 : 0 fuite sur liste, recherche, aperçu, API |
| V2-08 | F3 ; F8 jusqu'à K-Time |
| V2-09 | F11 |
| V2-10 | 10 personas simultanés sans collision ; toutes les lignes des deux matrices à 9 : RC |
| V2-14 | après V2-10 : F13 vert et F13b 12/12 ; réservation unifiée, ruban Office et OnlyOffice prouvés sur poste réel (ged-edition-poste-2) |

Lots en parallèle quand leurs secteurs ne se touchent pas (V2-02 et V2-04, V2-05 et V2-07) ; jamais deux agents dans le même secteur.
