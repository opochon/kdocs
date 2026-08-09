# GEDv1 (K-Docs) face à M-Files — état mesuré

> Date : 2026-08-07 · Périmètre : `F:\DATA\DEVELOPPEMENT\GEDv1`
> Référence : **M-Files**, la plateforme ECM. RedX en est l'intégrateur suisse ;
> REDX Factures / Contrat / SMQ / RH sont des jeux de paramétrage métier au-dessus
> du socle, pas des produits distincts. On compare donc au socle.
>
> Ce document ne remplace pas `PANORAMA-GED-REDX.md` (analyse marché) ni
> `PARITE-REDX-TESTS.md` (registre des 38 gaps). Il répond à une autre question :
> **qu'est-ce qui est prouvé, par opposition à ce qui est écrit ?**
>
> **Révision du 2026-08-09.** La première version portait deux constats faux, tous
> deux par sous-estimation : la piste de révision déclarée vide alors que
> `audit_logs` portait 1 261 lignes (deux tables homonymes vides avaient été
> interrogées à sa place), et les vues dynamiques déclarées absentes alors que
> `logical_folders` les fournit depuis longtemps. L'équivalence prouvée passe de
> 25 % à 45 %. Les sections corrigées portent la mention.

---

## 1. Pourquoi ce document existe

Le dépôt porte quatre chiffres de parité incompatibles, tous écrits noir sur blanc :

| Source | Chiffre | Date | Question à laquelle il répond réellement |
|---|---|---|---|
| `PANORAMA-GED-REDX.md` | ~48 % | 17-06 | Combien de capacités attendues d'un ECM fiduciaire existent, à dire d'expert ? |
| `AUDIT-SYNTHESE-EXECUTIVE.md` | ~52 % | 18-06 | Idem, après passage d'audit |
| `PARITE-REDX-TESTS.md` | ~96 % hors WinBiz | 03-07 | Combien de gaps du registre portent un test **déclaré** vert ? |
| `tools/checklist.mjs` | 45 % fait · **9 % testé** | 07-08 | Combien d'items du backlog ont une sonde qui passe, et un oracle vert **au dernier harness** ? |

Ces écarts ne sont pas un désaccord d'appréciation : ce sont quatre dénominateurs
différents. Le seul qui soit reproductible est le dernier, parce qu'il est
recalculé à chaque exécution et qu'il s'appuie sur `tests/reports/harness-latest.json`.

Le dépôt applique par ailleurs une règle explicite — **règle 9** : *un oracle dont
la source de vérité est le code ne prouve rien*. Un chiffre de 96 % adossé à des
tests cités mais non reliés à une exécution mesurée tombe exactement sous cette
règle. La section 5 donne le résultat du sondage.

---

## 2. Ce qu'on compare, et ce qu'on ne compare pas

M-Files est une plateforme commerciale sous licence, adossée à un éditeur, un
support, un écosystème de partenaires et une responsabilité contractuelle.
GEDv1 est un logiciel interne auto-hébergé, développé pour Karbonic.

**L'équivalence a un sens** sur les fonctions attendues d'un ECM fiduciaire suisse :
capture, indexation, organisation, workflow, archivage légal, sécurité, intégration
comptable, recherche.

**Elle n'en a pas** sur la maturité, le support, la garantie éditeur, l'écosystème
de connecteurs, la responsabilité juridique en cas de perte, ni le coût. Un
pourcentage de parité fonctionnelle ne dit rien de ces axes-là, et c'est souvent
eux qui décident d'un choix en fiduciaire.

---

## 3. La différence qui commande tout le reste

> **Révisé le 2026-08-09.** La première version de cette section présentait
> l'écart comme un gap à combler et classait les vues dynamiques en ABSENT.
> C'était faux deux fois : le modèle est un choix délibéré, et les vues
> dynamiques existent et fonctionnent.

**M-Files est métadonnées-first.** Il n'y a pas de dossiers physiques : un document
existe indépendamment de son emplacement, et les vues sont calculées dynamiquement
à partir des métadonnées. Le classement n'est pas une arborescence, c'est une requête.

**GEDv1 est hybride, et c'est une décision.** Le fichier reste sur disque, lisible
directement, sous son vrai nom — invariant écrit dans `docs/ORACLES.md` : *« le
fichier physique est la source ; la BDD porte métadonnées, index et relations »*.
Les métadonnées, elles, portent exactement ce que M-Files en tire : recherche
transverse et **vues dynamiques**. Un dossier « Factures » rassemble toutes les
factures où qu'elles soient, sans déplacer un fichier (`logical_folders`,
oracle `logical-folders`).

Le raisonnement, posé par la direction : on doit pouvoir poser la GED sur un
stockage existant sans tout importer, sans alourdir la base de blobs, et sans
produire des fichiers aux noms aberrants qu'on ne peut plus ouvrir sans
l'application.

Ce n'est donc pas un retard sur M-Files. C'est le même service de classement, sur
un socle de stockage différent — et le socle est le seul point où l'équivalence
n'est pas recherchée.

| | Métadonnées-first (M-Files) | Hybride (GEDv1) |
|---|---|---|
| Gain | Un document, N contextes. Classement sans arbitrage d'arborescence. | Mêmes vues transverses, **plus** un fichier ouvrable sans l'application. Aucun enfermement. Sauvegarde et reprise triviales. On se pose sur un stockage existant. |
| Coût | Dépendance forte au produit : sans lui, on a des blobs et une base propriétaire. | Deux sources à tenir d'accord — disque et base. Une dérive est invisible sans contrôle : au 09-08, 5 dossiers du disque n'étaient pas indexés et `file_count` mentait sur les 40. |

**Conséquence pratique** : le coût du modèle hybride n'est pas fonctionnel, il est
opérationnel. Il ne se paie pas en fonctions manquantes mais en cohérence à
maintenir, et cette cohérence doit être mesurée en continu — d'où l'oracle
`stockage-coherence`.

---

## 4. Tableau d'équivalence

Trois statuts, et pas un de plus :

- **PROUVÉ** — une suite nommée du harness l'atteste, et elle est verte (suite citée).
- **DÉCLARÉ** — le code ou un document l'affirme, aucune suite ne l'atteste.
- **ABSENT** — non implémenté, ou implémenté mais non déployé / non branché.

| Domaine | Capacité M-Files | État GEDv1 | Statut de preuve |
|---|---|---|---|
| **Capture** | Scan MFP, mail, upload, drag-drop | Upload + dossier `consume`. Module mail IMAP présent mais désactivé. | **ABSENT** en preuve — `persona-parcours-ecm` (ingérer → classer → analyser) est **ROUGE**, timeout 180 s |
| **OCR / indexation** | Texte intégral, métadonnées auto | OCR opérationnel, contenu indexé | **DÉCLARÉ** — aucune suite nommée ; `ocr-benchmark` est un oracle sans test |
| **Recherche** | Métadonnées, contexte, IA | FULLTEXT MySQL, Qdrant optionnel (désactivé) | **PROUVÉ** — `search-fulltext` vert (17 cas) depuis le 07-08. Deux bugs distincts corrigés : une sonde qui testait sa propre syntaxe, et un opérateur laissé comme terme qui cassait toute recherche en SQL 1064. Dette ouverte : les erreurs SQL restent avalées, une recherche cassée rend zéro résultat |
| **Organisation** | Métadonnées, vues dynamiques | Fichiers sur disque + vues dynamiques calculées | **PROUVÉ** — `logical-folders` vert (9 cas). `logical_folders` porte `filter_type` et `filter_config` ; un dossier « Factures » sur `{document_type_code: facture}` rassemble les factures où qu'elles soient, sans déplacer un fichier |
| **Workflow** | Validation factures, approbations | `WorkflowEngine`, nœuds typés, validation par rôles | **PROUVÉ, mince** — `workflow-doc-identification` vert (1 cas) |
| **Classification** | Métadonnées auto | Cascade IA configurable + taxonomie ECM | **PROUVÉ** — `classifier-taxonomie` vert (15 cas) |
| **Archivage légal CH** | Conforme GeBüV / Olico, 10 ans | Colonnes `legal_sealed`, `retention_until`, `tsa_token` déployées ; **10 documents scellés** sur 279 | **PARTIEL** — `legal-seal` vert (1 cas). Mais `TSA_URL` absent du `.env` : **aucun horodatage qualifié réel**. Et le scellé était contournable par la purge jusqu'au 07-08 |
| **Piste de révision** | Audit complet, export | `audit_logs` alimentée, **1 261 lignes** | **PROUVÉ** — `audit-trail-api` vert. La piste fonctionnait déjà pour l'authentification et les contrôleurs web ; les mutations passées par l'API ne laissaient aucune trace, elles sont journalisées depuis le 09-08. Restent : `classification_audit_log` à 0 ligne, et une table `audit_log` (singulier) vide en doublon |
| **Versioning** | Contrôle de version natif | Table `document_versions` déployée | **ABSENT** — **0 ligne** en base |
| **Sécurité / ACL** | RBAC, permissions fines | `FolderPermissionService` écrit et testé (10 cas verts) | **ABSENT** — le service n'est **appelé par aucune ligne de code applicatif**, et la table `folder_permissions` a **0 ligne**. Voir §5 |
| **Intégration ERP** | Comptable via intégrateur | Contrat `/api/ged/*` versionné, 8 routes, K-Time | **PROUVÉ** — `ktime-contract` vert + aller-retour réel `/api/ged/health` 200. Le point le plus solide du produit |
| **Modules métier** | REDX Factures / Contrat / SMQ / RH | `contracts`, `hr`, `mail`, `portal` codés | **ABSENT** — les 7 drapeaux (`CONTRACTS_APP_ENABLED`, `RH_APP_ENABLED`, `MAIL_APP_ENABLED`, `PORTAL_APP_ENABLED`, `MULTI_TENANT_ENABLED`, `CLAMAV_ENABLED`, `TSA_URL`) sont **absents du `.env`**. Tables déployées, **toutes à 0 ligne** |
| **Mobilité** | Apps natives, accès distant | Aucune | **ABSENT** — GAP-044 (Tauri) hors dépôt |
| **Hébergement** | On-premise ou cloud fiduciaire CH | Auto-hébergé intégral | **PROUVÉ** — préflight vert, aucune dépendance SaaS pour les documents |

---

## 5. Sondage sur les 96 %

`PARITE-REDX-TESTS.md` annonce « 34 gaps avec test vert » et « tout ce qui n'est pas
plugin WinBiz est comblé et épinglé par un test vert ». Vérification par échantillon :

| Gap | Déclaré | Constaté |
|---|---|---|
| GAP-040 — ACL document fine | ✅ 🧪 `Unit\FolderPermissionTest` (10 tests) | **Le test existait et il était vert.** Mais `FolderPermissionService` n'était référencé par **aucun** contrôleur, middleware ou route : le test validait un service que l'application n'appelait jamais. **Corrigé le 09-08** — le garde est branché, oracle `folder-permissions-serverside`. La table reste à 0 ligne : aucune règle n'est configurée. |
| GAP-020/024 — scellé WORM | ✅ 🧪 | Colonnes déployées, 10 documents scellés, suite verte. **Mais** le scellé ne couvrait pas le chemin de purge : `TrashService::deletePermanently` et `TaskService::cleanupTrash` détruisaient sans consulter `legal_sealed`. Corrigé le 07-08. |
| GAP-023 — horodatage TSA | ✅ 🧪 (mock TSA) | Test vert **avec transport moqué**. `TSA_URL` absent du `.env` : aucun horodatage réel n'a jamais été produit. |
| GAP-030/033/034/042 — contrats, RH, mail, portail | ✅ 🧪 | Tests verts, drapeaux absents du `.env`, tables à 0 ligne. Modules non déployés. |

**Ce que ce sondage établit** : les tests cités existent réellement et passent. Le
problème n'est pas leur existence, c'est ce qu'ils prouvent. Un test unitaire vert
sur un service jamais appelé, ou sur un module désactivé, atteste que **le code
écrit fonctionne** — pas que **la capacité est disponible pour l'utilisateur**.

C'est exactement la règle 9. Le chiffre de 96 % mesure la couverture de test du
code écrit ; il ne mesure pas la parité fonctionnelle livrée.

---

## 6. Les deux chiffres

**Équivalence fonctionnelle déclarée : ~50 %.** Cohérent avec le panorama (48 %) et
l'audit (52 %). Compte les capacités pour lesquelles du code existe, qu'il soit
branché ou non. Dénominateur : les 12 domaines du §4. Ignore : le déploiement,
l'alimentation des tables, l'activation des modules.

**Équivalence prouvée par la mesure : ~45 %** *(révisé le 09-08 ; la première
version disait 25 %, sur la foi de deux constats faux — voir §3 et §5)*. Compte les
seuls domaines portés par une suite nommée et verte au dernier harness, dont on a
vérifié qu'elle atteste la capacité et non seulement le code. Soit 6 domaines pleins
sur 12 — classification, organisation, piste de révision, sécurité/ACL, intégration
ERP, hébergement — plus deux partiels : workflow et archivage légal.

**L'écart entre les deux reste le résultat**, mais il s'est resserré en un jour de
travail, et pas en écrivant du code : en le raccordant. L'ACL existait et n'était
appelée par personne ; l'audit fonctionnait mais ne couvrait pas le chemin que
l'interface emprunte ; les vues dynamiques marchaient sans que rien ne l'atteste.

Ce qui reste non raccordé : versioning inutilisé (0 ligne), 5 modules aux drapeaux
absents du `.env`, horodatage TSA jamais configuré.

Réserve honnête sur ces deux chiffres : un découpage en 12 domaines est un choix.
Un découpage plus fin déplacerait les valeurs de quelques points. Ce qui ne bouge
pas, c'est le rapport d'environ 1 à 2 entre déclaré et prouvé.

---

## 7. Ce qui manquerait pour un usage fiduciaire réel

**Fait le 2026-08-09** — tout était du raccordement, aucun développement neuf :

1. ~~Brancher l'ACL.~~ Consultée sur `show`, `content`, `download`, `update`,
   `delete`. Refus en 404, pour ne pas révéler l'existence d'une pièce interdite.
2. ~~Couvrir la piste de révision.~~ Sept mutations de l'API journalisées.
3. ~~Réparer la recherche FULLTEXT.~~ Deux bugs distincts, sonde et produit.

**Reste à portée**

4. **Trancher `storage.base_path`.** Il pointe vers `C:\wamp64`, l'installation
   d'avant migration : 129 des 191 documents vivants y sont. Aucun fichier n'est
   perdu, mais le fonds est à cheval sur deux arborescences.
5. **Brancher le versioning.** Le modèle `DocumentVersion` est complet et n'est
   appelé par personne — 0 ligne pour 337 documents.
6. **Activer les 5 modules éteints** — ou les retirer. Aucun n'est lié depuis
   l'interface aujourd'hui, donc pas de 404 : c'est une décision produit, pas une
   urgence technique.
7. **Horodatage TSA réel.** `TSA_URL` à renseigner ; le code accepte déjà un
   transport injectable.
8. **Poser des règles d'ACL.** Le garde est en place mais `folder_permissions` est
   vide et aucun écran ne permet d'en créer.

**Structurel — ne se rattrape pas par incréments**

6. **Le modèle métadonnées.** Voir §3. Décision d'architecture, pas de sprint.
7. **La mobilité.** Aucune base existante.
8. **L'écosystème et la garantie.** Hors du champ du logiciel.

---

## 8. Ce que GEDv1 fait que M-Files ne fait pas

Instruit, pas supposé :

- **Souveraineté intégrale.** Documents et métadonnées restent sur l'infrastructure
  Karbonic, sans SaaS tiers. M-Files propose l'on-premise, mais le modèle de
  métadonnées reste propriétaire ; ici le fichier reste lisible sans l'application.
- **Aucun coût par utilisateur.** M-Files se facture à l'utilisateur nommé, plus les
  services d'intégration.
- **IA locale et configurable.** Cascade `training → claude → ollama → rules`,
  paramétrable, avec Ollama en local. On choisit ce qui sort de la maison.
- **Intégration WinBiz par K-Time.** Contrat de 8 routes versionné et vérifié contre
  les deux dépôts et le serveur vivant. C'est du sur-mesure fiduciaire suisse
  qu'aucun produit générique ne fournit tel quel.
- **Zéro suppression dure.** Depuis le 07-08, aucun chemin produit ne détruit une
  ligne : la corbeille est un état durable, pas une antichambre. Invariant plus
  strict que le comportement par défaut de la plupart des ECM.

---

## 9. Ce qui reste indéterminé

- La profondeur fonctionnelle réelle de M-Files sur les workflows de validation
  facture n'a pas été testée en conditions comparables — l'appréciation vient de la
  documentation éditeur et des notes de juin du dépôt, pas d'un banc d'essai.
- Le coût total M-Files pour le périmètre Karbonic n'a pas été chiffré.
- Les 3 autres suites rouges (`lib-operations`, `pipeline-ui`, `erp-connect`) ne
  sont pas départagées entre défaut produit et instabilité de banc. `lib-operations`
  change de cas en échec d'un run à l'autre, ce qui oriente vers l'instabilité.

---

*Méthode : statuts adossés à `tests/reports/harness-latest.json` (37 suites, run du
2026-08-06, verdict ROUGE), à l'état réel de la base `kdocs` (279 documents, 161 en
corbeille, 10 scellés) et au `.env` de l'installation. Les chiffres se recalculent
par `node tools/checklist.mjs` et `run-harness.bat`.*
