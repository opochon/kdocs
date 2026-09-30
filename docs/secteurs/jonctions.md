# Secteur JONCTIONS — K-Time, ClearMyMails, API d'entrée

Agent : DeepCode (V2-08a API d'entrée, V2-08b appairage/destinations/.eml, V2-08c appairage parti de la GED, V2-08d une seule table) · Juge : Claude Code · Lot en cours : V2-08d

## Mission
La GED ne refait pas ce que les produits voisins font déjà ; elle se branche proprement, par API, jamais par la base
d'un autre.
- **ClearMyMails** = le pont lourd du courriel (Gmail, Microsoft 365 / Graph, règles de tri, historique). Il pousse les
  pièces vers la GED par **l'API d'entrée** ; la GED les traite exactement comme le dossier d'ingestion (même chaîne,
  même dédoublonnage, source `cmm`). L'adresse d'ingestion IMAP (secteur Ingestion) reste la porte légère.
- **K-Time** : la GED **détecte** une facture fournisseur (QR suisse, montant, échéance, IBAN) et la **propose** à
  K-Time ; K-Time l'introduit. Jamais d'écriture WinBiz par la GED. Le dépôt K-Time est en **lecture seule** pour nous.
- **API d'entrée** : `POST /api/v1/depots` (fichier + métadonnées facultatives), jeton par application, idempotente
  (même empreinte = même réponse), réponse = identifiant du travail d'ingestion, puis `GET` pour suivre son état.

## Chemins
`src/Jonctions/`, `tests/php/Jonctions/`, `tests/e2e/jonctions.spec.ts`, `docs/api/`,
`templates/ecrans/profil.twig`. Depuis V2-08c, l'écran Application › Appareils reliés a rejoint Mon profil :
`templates/ecrans/appairage.twig` et `templates/ecrans/appareils.twig` sont supprimés, `/appairage` et
`/application/appareils` redirigent vers `/profil`.

## Lot V2-08 — porte
1. API d'entrée : 3 fichiers poussés deux fois → 3 documents, 0 doublon ; jeton absent ou révoqué → 401 nommé.
2. ClearMyMails (mode simulé en test, réel en recette) : un message à 2 pièces jointes → 2 documents, source `cmm`,
   lien vers le message d'origine dans la fiche.
3. K-Time : une facture QR du corpus apparaît côté K-Time comme proposition complète (fournisseur, montant, échéance,
   référence), sans aucune écriture WinBiz ; un refus K-Time revient dans la fiche.
4. Aucune jonction en panne ne bloque la GED : file d'envoi avec reprise, erreurs visibles dans « Application ».

## Reprendre de GEDv1
`erpconnect` (détection facture → K-Time), `MailSyncService` (désactivé : ne pas reprendre, CMM le remplace),
l'API REST existante (`/api/documents`) comme liste de courses, pas comme code.

## Demandes
- À l'Ingestion (livrée dans ce lot) : la source `Ged\Jonctions\SourceApi` écrit dans la zone de travail et passe par
  `Travaux::recevoir` ; il reste à la brancher dans `Fabrique::sources()` (demande écrite dans `docs/secteurs/ingestion.md`).
- Aux Droits (livrée dans ce lot) : jetons d'application (création, révocation, portée `depot`) et déclaration des deux
  routes en `publique:api-depot(-suivi)` (signalé dans `docs/secteurs/droits-securite.md`).
- Au Socle (V2-08b, ajout minimal fait) : `DepotFichiers::cheminRangement()` honore une clé de métadonnées `dossier` —
  un dépôt direct par l'API dans un dossier autorisé garde ce chemin au lieu du rangement année/type/émetteur (section 2).
- À l'Ingestion (V2-08b, ajout minimal fait) : `Pilote::decouper()` lit l'accompagnement `<fichier>.depot.json` de la
  source API et propage l'origine CMM dans chaque pièce ; `Pilote::ranger()` l'écrit sous `origine.courriel` du `.meta.json`.
- À l'Aperçu (V2-08b, ajouts minimaux faits) : `Apercu\Source` connaît la catégorie `message` (`.eml`) et résout l'`id`
  depuis l'index quand le `.meta.json` n'en porte pas ; `Apercu\Module` rend le HTML nettoyé de `Ged\Jonctions\Courriel`.
  Recherche n'est pas modifiée : `SourceRecherche` indexait déjà le `texte` du `.meta.json`.
- À l'Interface (V2-08b, ajout minimal à commiter par le juge) : lien statique « Appareils reliés » dans
  `templates/ecrans/application.twig` (2 lignes).
- Au harnais (V2-08b) : statuts attendus des routes publiques dans `tools/banc/contrat.php` (fichier sans modification
  concurrente, commité par DeepCode) et charges CSRF défensives dans `tools/banc/sonde-securite.php` (modifié par GLM :
  à commiter par le juge).
- À l'Interface (V2-08c, ajout minimal commité) : le lien de `templates/ecrans/application.twig` pointe vers
  `/profil` (« Mon profil · appareils reliés ») ; l'écran Application › Appareils reliés n'existe plus.
- Aux Droits (V2-08c, signalé) : les routes de Mon profil (`GET /profil`, `POST /profil/mail`,
  `GET /profil/mail/verifier`, `POST /profil/appairage`) sont déclarées en `documents:traiter`, comme les
  écrans d'appairage V2-08b.
- Au Socle/Droits (V2-08c, demande) : une permission `profil:gerer` serait plus juste que `documents:traiter`
  pour un écran personnel (aujourd'hui un `lecteur` sans `documents:traiter` ne voit pas son profil).
- Au Socle (V2-08c, ajout minimal commité) : `composer.json`/`composer.lock` ajoutent `phpmailer/phpmailer`
  (transport SMTP du courriel d'auto-configuration) ; en test, le transport « fichier » écrit le `.eml` dans
  `var/mails` et n'envoie jamais rien. Réinstaller si `vendor/` est périmé : `php tools\composer.phar install`.

## Journal V2-08a — API d'entrée (porte 1)

- Oracle d'abord : `tests/php/Jonctions/DepotApiTest.php` écrit rouge (classes `Ged\Jonctions\{Schema,Jetons,SourceApi,DepotApi}`
  absentes : 7 erreurs), puis vert — 7 tests, 50 assertions.
- Fait : `POST /api/v1/depots` (fichier `multipart/form-data` + métadonnées facultatives) et `GET /api/v1/depots/{id}`.
  Jeton par application (`Jetons` : création, révocation, portée `depot`, empreinte sha256 en base, jamais le clair ;
  portée `*` possible pour un jeton d'administration). L'authentification se fait dans le secteur, hors session :
  `Authorization: Bearer …`.
- Fait : idempotence par empreinte — `SourceApi` écrit la copie stable dans la zone de travail dédiée
  (`<INGESTION_TRAVAIL>/api`) puis passe par `Travaux::recevoir` ; à empreinte connue, le nouveau fichier part dans
  `doublons/` (jamais supprimé) et la réponse est identique (même code 202, même identifiant). Les métadonnées
  accompagnent la copie dans `<fichier>.depot.json` (le dossier fait foi).
- Fait : erreurs nommées (étape, cause, référence) — 401 jeton absent/inconnu/révoqué, 403 portée insuffisante,
  413 fichier trop gros, 415 type refusé, 400 transfert incomplet, 404 travail introuvable. Types acceptés : les mêmes
  que le dossier d'ingestion ; taille max `API_DEPOT_MAX_OCTETS` (25 Mio par défaut).
- Fait : `SourceApi` implémente `Ged\Ingestion\Source` ; `relever()` rend les fichiers écrits mais pas encore
  enregistrés (reprise après panne) et ignore ceux déjà connus.
- Signalé : `src/Securite/DroitsRoutes.php` (secteur Droits) déclare les deux routes en `publique:api-depot(-suivi)`
  pour qu'elles échappent à la session ; la porte réelle est le jeton. Demande écrite dans la fiche Droits ; sonde
  offensive rejouée verte.
- Preuves : `vendor\bin\phpunit tests\php\Jonctions` vert (7 tests, 50 assertions) ; `php tools\banc\sonde-securite.php`
  vert. Harness `-SansBanc` : couches 0 et 7 vertes ; relance isolée de PHPUnit verte (241 tests, 1072 assertions) ;
  la couche 4 reste rouge sur 5–6 parcours `recherche`/`bibliotheque` (résultats vides) parce qu'un agent concurrent
  remet à neuf la base partagée `gedv2_test` — défaut hors secteur, déjà consigné dans la fiche Droits.
- Documentation : `docs/api/depots.yaml` (OpenAPI court : dépôt et suivi, jeton Bearer, schémas d'erreur).
- Commit : `1759a67` `jonctions: API d'entrée sous jeton, idempotente (V2-08a)`.

ETAT: fini


## Jugement V2-08a (Claude Code, 29-09) — porte franchie
Rejoué sur le code commité : unitaire 212/212, 3 bancs verts, écrans 26/26, couche 7 verte (les deux routes publiques d'API tiennent par le jeton). Demande à l'Ingestion faite : `SourceApi` est dans `Fabrique::sources()`. Reste : V2-08b (ClearMyMails pousse vers l'API, source `cmm`), V2-08c (K-Time).
ETAT: fini (jugé)

## À commiter par le juge (fichiers portant des modifications d'un autre agent)

- `src/Securite/DroitsRoutes.php` (modifié par GLM, V2-04) — lignes ajoutées par V2-08b :
  - registre `PERMISSIONS` : `GET|DELETE /api/v1/appairage/moi` → `publique:api-appairage-moi`,
    `POST /api/v1/appairage/jeton` → `publique:api-appairage`, `GET /api/v1/dossiers` → `publique:api-dossiers`,
    `GET|POST /appairage` → `documents:traiter`, `GET /application/appareils` → `documents:traiter`,
    `POST /application/appareils/{id:[0-9]+}/revoquer` → `documents:traiter` ;
  - `permissionPour()` : reconnaissance de la route paramétrée `POST /application/appareils/[0-9]+/revoquer`.
- `tools/banc/sonde-securite.php` (modifié par GLM, V2-04) — deux charges défensives ajoutées dans `$chargesMutations` :
  `POST /appairage` et `POST /application/appareils/{id:[0-9]+}/revoquer`.
- `src/Securite/DroitsRoutes.php` — lignes ajoutées par V2-08c (section 1 v2, Mon profil) :
  `GET /profil`, `POST /profil/mail`, `GET /profil/mail/verifier`, `POST /profil/appairage` →
  `documents:traiter` ; les lignes `GET|POST /appairage` de V2-08b restent (elles redirigent vers `/profil`).
- `tools/banc/sonde-securite.php` — charges défensives ajoutées par V2-08c dans `$chargesMutations` :
  `POST /profil/mail` (`adresse=`) et `POST /profil/appairage` (`sonde=1`), à côté de `POST /appairage`.

## Journal V2-08b — appairage, destinations, `.eml`

- Oracle d'abord, rouge : `tests/php/Jonctions/AppairageTest.php`, `CourrielTest.php`, `ContratCmmTest.php` écrits rouges
  (classe `Ged\Jonctions\Appairage` absente : 7 erreurs), puis verts — 14 tests, 136 assertions. Parcours Playwright
  connecté `tests/e2e/jonctions.spec.ts` (2 parcours) : appairage → échange du code (rôle CMM) → écran Appareils reliés
  → révocation ; et refus 403 sans session.
- Fait (section 1) : `GET /appairage` (écran au système visuel existant, session normale), `POST /appairage` (acceptation,
  CSRF), code à usage unique 5 min (`jonctions_codes_appairage`, empreinte sha256, revendication atomique), échange
  `POST /api/v1/appairage/jeton`, statut `GET /api/v1/appairage/moi`, révocation `DELETE /api/v1/appairage/moi`, écran
  Application › Appareils reliés (liste + révoquer). Le jeton est LIÉ AU COMPTE (`compte_id`/`compte_identifiant` sur
  `jonctions_jetons`), haché, révocable ; jeton inconnu/révoqué → 401 nommé, code inconnu/expiré/rejoué → 400 nommé.
- Fait (section 2) : `GET /api/v1/dossiers?parent=` ne liste QUE les dossiers ACL où le compte peut écrire
  (`Ged\Securite\DroitsDossiers`, héritage parent, refus par défaut) ; `POST /api/v1/depots` accepte
  `destination=ingestion|dossier`, `dossier`, `origine` ; `destination=dossier` passe par `Ged\Contrats\Depot::deposer`
  (statut `a_controler`, chemin conservé) et refuse 403 nommé sans droit d'écriture. Idempotence par empreinte dans les deux.
- Fait (section 3) : `.eml` accepté comme document ; `Ged\Jonctions\Courriel` (zbateson/mail-mime-parser) lit les en-têtes
  et le corps (texte indexé parce que porté par le `.meta.json`), produit un aperçu HTML NETTOYÉ (scripts et éléments
  distants retirés, tous attributs supprimés, images remplacées par leur texte alternatif) et liste les pièces jointes.
  `origine` écrite dans le `.meta.json` (dépôt direct : `origine` ; chaîne d'ingestion : `origine.courriel`).
- Ajouts minimaux signalés (fichiers sans modification concurrente, commités par DeepCode) : `src/Socle/DepotFichiers.php`
  (clé `dossier`), `src/Ingestion/Pilote.php` (origine CMM), `src/Apercu/{Source,Module,Piece}.php`,
  `templates/partiels/visionneuse.twig`, `public/assets/js/visionneuse.js`, `templates/ecrans/application.twig`,
  `tools/banc/contrat.php`.
- Preuves : `vendor\bin\phpunit tests\php\Jonctions` vert (14 tests, 136 assertions) ; suite PHPUnit complète verte
  (262 tests, 1254 assertions) ; `php tools\banc\contrat.php` vert ; `php tools\banc\sonde-securite.php` vert ;
  `npx playwright test tests/e2e/jonctions.spec.ts` vert (2 parcours) ; aperçu Playwright isolé vert (7 parcours).
  Harness `-SansBanc` : couches 0, 1, 2, 6 et 7 VERTES ; couche 3 volontairement sautée ; couche 4 rouge sur
  `apercu`/`bibliotheque`/`maquettes` (seuil de première page PDF sous charge ; 404 d'aperçu de l'oracle Bibliothèque)
  — vérifié hors secteur par `git stash` des ajouts transverses : `bibliotheque.spec.ts` échoue identiquement sans mes
  modifications ; défaut déjà consigné (V2-07c bis, V2-08a).
- Commit : `d9d49fe` `jonctions: appairage, destinations et .eml (V2-08b)`.

ETAT: fini

## Journal V2-08c — l'appairage part de la GED (Mon profil, auto-configuration)

- Contrat lu et suivi : section 1 v2 de `docs/api/cmm-ged.md` (décision d'Olivier du 30-09 : la liaison part
  de la GED, CMM la valide, la GED révoque ; la GED ne lit jamais la boîte). `docs/api/appairage.yaml`
  réécrit en v2.
- Oracle d'abord, rouge : `AppairageTest`, `ProfilTest`, `CourrielAutoConfigTest` et `ContratCmmTest`
  réécrits/écrits rouges (classes `Ged\Jonctions\{Profil,Mailer}` absentes : 13 erreurs), puis verts —
  `tests/php/Jonctions` 20 tests, 192 assertions. Parcours Playwright connecté réécrit
  (`tests/e2e/jonctions.spec.ts`, 2 parcours) : Mon profil → adresse vérifiée par le lien du `.eml` écrit en
  `var/` → Relier → lecture du courriel d'auto-configuration → échange `{code, verification, poste}` comme
  CMM → appareils reliés → Révoquer → 401 ; et refus 403 sans session.
- Fait (Mon profil) : `GET /profil` (adresse et état, bouton « Relier ClearMyMails », appairages en attente,
  appareils reliés avec Révoquer), `POST /profil/mail` (adresse + lien de vérification), `GET
  /profil/mail/verifier` (lien à usage unique, valable 1 h). L'écran Application › Appareils reliés est
  DÉPLACÉ : `/appairage` et `/application/appareils` redirigent vers `/profil` ; `appairage.twig` et
  `appareils.twig` sont supprimés ; le lien de `application.twig` pointe vers `/profil`.
- Fait (appairage en attente 24 h) : code d'échange 256 bits (empreinte sha256 seule en base) + code de
  vérification court (6 caractères sans ambiguïté, ex. `K7M-4QX`) affiché à l'écran. La GED envoie à
  l'adresse VÉRIFIÉE le courriel d'auto-configuration : objet « Relier votre GED à ClearMyMails », en-tête
  `X-GED-Appairage: v1`, texte humain, pièce `ged-appairage.json` `{version, ged_url, ged_nom, compte, code,
  verification, expire_le}`. `POST /api/v1/appairage/jeton` exige désormais `{code, verification, poste}` :
  mauvais code de vérification → 400 nommé SANS consommer le code ; code inconnu/expiré/rejoué → 400 nommé ;
  l'échange lie le jeton au compte et enregistre le poste annoncé par CMM.
- Fait (transport) : `Ged\Jonctions\{Transport,TransportFichier,TransportSmtp,Mailer}` sur
  `phpmailer/phpmailer` (ajouté proprement par `tools\composer.phar` : `composer.json` + `composer.lock`).
  Transport SMTP configurable par `GED_SMTP_*` ; en test, transport « fichier » qui écrit le `.eml` dans
  `var/mails` (jamais d'envoi réel). `.env.example` complété.
- Remplacement de l'ancien parcours URL (V2-08b) : la compatibilité n'était pas gratuite (l'acceptation CMM
  poussée par URL n'a plus de sens quand la liaison part de la GED) ; les deux routes historiques redirigent,
  et le contrat le dit (`docs/api/appairage.yaml`, rubrique Chemins ci-dessus).
- Schéma resserré : la table `jonctions_profils` porte l'adresse, sa vérification ET l'appairage en attente
  (au plus un) ; `jonctions_jetons` reste seule à porter les jetons. Deux tables Jonctions, comme avant, pour
  tenir le plafond de 24 tables du lot V2-04 ; `jonctions_codes_appairage` disparaît. Aucun `DELETE FROM`
  dans `src/` (une remise à zéro est une trace : `appairage_utilise_le`), la garde `CorbeilleTest` reste verte.
- Preuves : `vendor\bin\phpunit tests\php\Jonctions` vert (20 tests, 192 assertions) ; suite PHPUnit complète
  verte (268 tests, 1314 assertions) ; `npx playwright test tests/e2e/jonctions.spec.ts` vert (2 parcours) ;
  `php tools\banc\contrat.php` vert ; `php tools\banc\sonde-securite.php` vert.
- Harness `-SansBanc` sur `46ffc77` : couches 0, 1, 2 et 7 VERTES ; couche 3 volontairement sautée ; couche 4
  rouge sur 2 parcours HORS SECTEUR (relance isolée : 4) — `apercu` seuil de première page PDF sous charge,
  `bibliotheque` 404/écran du Socle V2-04, `maquettes` seuils 1,5 s ; couche 6 (charge) rouge sur l'écran
  **Bibliothèque** du Socle V2-04 en cours (p95 4973 ms à 3 utilisateurs ; la table par écran de
  `recette/preuves/charge/charge.md` désigne la bibliothèque, jamais une route Jonctions). Les oracles
  Jonctions sont verts isolément ; défauts hors secteur déjà consignés (V2-07c bis, V2-08a, fiche Droits).
- Vestiaire partagé (À commiter par le juge) : lignes ajoutées à `src/Securite/DroitsRoutes.php` et charges
  dans `tools/banc/sonde-securite.php` (détail dans la rubrique ci-dessus).
- Commit : `46ffc77` `jonctions: appairage parti de la GED (profil vérifié, auto-configuration SMTP/fichier) (V2-08c)`.

ETAT: fini

### Jugement V2-08b + V2-08c (juge, 30-09 09:25, copie propre sur 66ed97f)

- Le juge a commité les lignes du secteur dans `src/Securite/DroitsRoutes.php` et `tools/banc/sonde-securite.php` (66ed97f), sans les lignes non commitées du Socle.
- Harness : 0 vert ; 2 contrat vert ; 3 banc vert (53/53, F1 franchie) ; 7 sécurité verte (les charges défensives de Mon profil et de la révocation y sont).
- **Couche 1 rouge, imputable au secteur** : `InstallateurTest` — 21 tables posées pour un plafond de 20 (oracle V2-01). Passage de 20 à 21 à d9d49fe (V2-08b). Le « plafond de 24 » cité au journal V2-08c vient d'un travail non commité et non jugé du Socle : il ne vaut pas. Le plafond reste 20.
- Couche 4 (aperçu et recherche < 1 s) et couche 6 (p95 lecture 1,98 s) rouges sur des budgets de temps, avec un banc de découpe à 144 s au lieu de 87 s : machine chargée, hors secteur. À rejouer machine calme avant tout verdict sur ces couches.
- Verdict : **non franchi**. Renvoyé en V2-08d : Jonctions tient en une table, contrat et comportement inchangés.

## Journal V2-08d — Jonctions tient en UNE table (plafond V2-01)

- Jugement repris tel quel : sur `66ed97f`, le plafond est **20** (oracle V2-01) et le passage de 20 à 21
  vient des DEUX tables Jonctions (`jonctions_jetons` + `jonctions_profils`). Le « plafond de 24 du lot
  V2-04 » est un travail non commité et non jugé du Socle : il ne compte pas.
- Oracle d'abord, **rouge** : sur la base COMMITÉE seule (`git archive HEAD` dans un dossier temporaire, pour
  ne pas toucher le travail non commité du Socle), `InstallateurTest` échoue 3/4 — « 21 tables posées : le
  départ doit tenir en 20 ». Compte vérifié sans `git stash` : `git show HEAD:<Schema.php>` somme à 21
  `CREATE TABLE` (Jonctions = 2).
- Fait : les deux tables fusionnent en **`jonctions_jetons`** (option « `jonctions_jetons` élargie »
  proposée par le juge), avec une colonne `nature` : `application` (jeton d'application), `appareil` (jeton
  appairé, lié compte + poste), `verification_adresse` (adresse saisie puis vérifiée du profil, lien à usage
  unique) et `appairage_en_attente` (code d'échange 24 h + code court de comparaison). Chaque nature remplit
  son sous-ensemble de colonnes ; trois empreintes distinctes et uniques (`empreinte`, `verif_empreinte`,
  `appairage_code`) plus `identifiant` unique pour le profil. Toujours sha256, jamais un secret en clair.
- Fait : `Profil`, `Appairage` et `Jetons` bornent leurs lectures sur la `nature` (un jeton d'appareil porte
  un `compte_id` : sans ce filtre, une lecture de profil l'aurait pris pour un profil). Un nouvel appairage
  marque le précédent `appairage_utilise_le` (trace, jamais une suppression) puis pose une ligne neuve :
  « au plus un appairage en attente » tient toujours. L'`appairage_jeton_id` est posé en deux temps
  (sélection puis `UPDATE`) car MySQL refuse une sous-requête sur la table en cours de mise à jour — la table
  étant désormais unique. Aucun `DELETE FROM` ajouté dans `src/`.
- Fait : `tests/php/Jonctions/SupportJonctions.php` nettoie la table unique (les `DROP` des noms historiques
  restent, pour repartir d'un schéma neuf). Les oracles de comportement (`AppairageTest`, `ProfilTest`,
  `ContratCmmTest`, `DepotApiTest`, `Courriel*Test`) ne changent pas d'une virgule et `depot.php` non plus.
- Contrat inchangé : `docs/api/cmm-ged.md` v2 et `docs/api/appairage.yaml` ne bougent pas ; aucun changement
  d'HTTP, de statuts ni de messages.
- Vert (copie commitée propre + mes seules modifications Jonctions) : `InstallateurTest` 4/4, **20 tables**
  (1+2+1+1+7+5+3) ; l'installateur annonce « 20 tables ».
- Preuves (arbre intégré) : `tests\php\Jonctions` vert (20 tests, 192 assertions) ; suite PHPUnit complète
  verte (268 tests, 1314 assertions, `CorbeilleTest` compris) ; `tests/e2e/jonctions.spec.ts` vert
  (2 parcours) ; `tools/banc/contrat.php` vert ; `tools/banc/sonde-securite.php` vert.
- Hors secteur noté : sur la base commitée seule, des avertissements PHP (comptés par `failOnWarning`) viennent
  de fichiers COMMITÉS qui attendent des fichiers du Socle non commités : `src/Ui/ATraiter.php` →
  `Ged\Socle\Nomenclature` ; `src/Kernel.php` → `Ged\Socle\Module` (les oracles Jonctions qui passent par
  `Kernel::creer` en héritent). `class_exists` échoue alors proprement. Sans rapport avec Jonctions :
  l'arbre intégré (fichiers du Socle présents) est vert.
- Commit : `abcb8a8` `jonctions: une seule table (jetons, profils, appairages) tient le plafond V2-01 (V2-08d)`.

ETAT: fini
### Jugement V2-08b + c + d (juge, 30-09 10:30, copie propre sur d5ce356)

- Schéma : 20 tables, Jonctions en une seule table ; plafond V2-01 tenu sans modifier l'oracle.
- Harness : 0, 1 (237 tests), 2, 3 (53/53, F1 franchie), 6 (p95 lecture 958 ms, écriture 116 ms) et 7 verts.
- Couche 4 : 30/31 ; seul rouge = budget « première page PDF < 1 s » d'`apercu.spec.ts` (1 548 ms), hors secteur. Rejoué seul deux fois : 7/7 vert. Défaut du banc d'écrans sous exécution parallèle, consigné à la fiche recherche-apercu.
- Verdict : **V2-08b/c/d franchis**. L'appairage part de Mon profil (adresse vérifiée, courriel d'auto-configuration avec code de vérification, révocation), conformément à `docs/api/cmm-ged.md` v2 §1.