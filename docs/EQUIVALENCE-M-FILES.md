# GEDv1 (K-Docs) face à M-Files — état mesuré

> Date : 2026-08-07 · Périmètre : `F:\DATA\DEVELOPPEMENT\GEDv1`
> Référence : **M-Files**, la plateforme ECM. RedX en est l'intégrateur suisse ;
> REDX Factures / Contrat / SMQ / RH sont des jeux de paramétrage métier au-dessus
> du socle, pas des produits distincts. On compare donc au socle.
>
> Ce document ne remplace pas `PANORAMA-GED-REDX.md` (analyse marché) ni
> `PARITE-REDX-TESTS.md` (registre des 38 gaps). Il répond à une autre question :
> **qu'est-ce qui est prouvé, par opposition à ce qui est écrit ?**

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

**M-Files est métadonnées-first.** Il n'y a pas de dossiers physiques : un document
existe indépendamment de son emplacement, et les vues sont calculées dynamiquement
à partir des métadonnées. Le même document apparaît sous « Client X », « Factures
2026 » et « À valider » sans être dupliqué ni déplacé. Le classement n'est pas une
arborescence, c'est une requête.

**GEDv1 est filesystem-first**, et c'est un invariant assumé, écrit dans
`docs/ORACLES.md` : *« le fichier physique est la source ; la BDD porte métadonnées,
index et relations »*.

Ce n'est pas un retard de fonctionnalité, c'est un choix inverse.

| | Métadonnées-first (M-Files) | Filesystem-first (GEDv1) |
|---|---|---|
| Gain | Un document, N contextes. Classement sans arbitrage d'arborescence. Recherche naturelle. | Le fichier reste lisible sans l'application. Aucun enfermement. Sauvegarde et reprise triviales. |
| Coût | Dépendance forte au produit : sans lui, on a des blobs et une base propriétaire. | Le classement redevient une arborescence, avec ses arbitrages. Les vues transverses sont à construire. |

**Conséquence pratique** : rattraper M-Files sur ce point ne se fait pas en ajoutant
des fonctions, mais en changeant de modèle. Toute comparaison ligne à ligne qui
ignore cette bascule sous-estime l'écart réel sur l'organisation, et surestime
l'écart sur la souveraineté.

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
| **Recherche** | Métadonnées, contexte, IA | FULLTEXT MySQL, Qdrant optionnel (désactivé) | **ABSENT** en preuve — `search-fulltext` **ROUGE** : `MATCH AGAINST` casse en SQL 1064 sur expression booléenne vide |
| **Organisation** | Métadonnées, vues dynamiques | Arborescence + métadonnées en base | **ABSENT** — différence structurelle (§3), pas un gap à combler |
| **Workflow** | Validation factures, approbations | `WorkflowEngine`, nœuds typés, validation par rôles | **PROUVÉ, mince** — `workflow-doc-identification` vert (1 cas) |
| **Classification** | Métadonnées auto | Cascade IA configurable + taxonomie ECM | **PROUVÉ** — `classifier-taxonomie` vert (15 cas) |
| **Archivage légal CH** | Conforme GeBüV / Olico, 10 ans | Colonnes `legal_sealed`, `retention_until`, `tsa_token` déployées ; **10 documents scellés** sur 279 | **PARTIEL** — `legal-seal` vert (1 cas). Mais `TSA_URL` absent du `.env` : **aucun horodatage qualifié réel**. Et le scellé était contournable par la purge jusqu'au 07-08 |
| **Piste de révision** | Audit complet, export | Table `classification_audit_log` déployée | **ABSENT** — **0 ligne** en base pour 279 documents. La piste n'est pas alimentée |
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
| GAP-040 — ACL document fine | ✅ 🧪 `Unit\FolderPermissionTest` (10 tests) | **Le test existe et il est vert.** Mais `FolderPermissionService` n'est référencé par **aucun** contrôleur, middleware ou route. Le test valide un service que l'application n'appelle jamais. Table à 0 ligne. |
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

**Équivalence prouvée par la mesure : ~25 %.** Compte les seuls domaines portés par
une suite nommée et verte au dernier harness, dont on a vérifié qu'elle atteste la
capacité et non seulement le code. Soit 3 domaines pleins sur 12 — classification,
intégration ERP, hébergement — plus deux partiels — workflow et archivage légal.
Dénominateur identique.

**L'écart entre les deux est le résultat.** La moitié de ce qui est écrit n'est pas
en service : modules désactivés, ACL non branchée, piste de révision vide,
versioning inutilisé. Ce n'est pas du code manquant, c'est du code non raccordé.

Réserve honnête sur ces deux chiffres : un découpage en 12 domaines est un choix.
Un découpage plus fin déplacerait les valeurs de quelques points. Ce qui ne bouge
pas, c'est le rapport d'environ 1 à 2 entre déclaré et prouvé.

---

## 7. Ce qui manquerait pour un usage fiduciaire réel

**À portée — raccordement, pas développement**

1. **Brancher l'ACL.** Le service existe et il est correct. Aucun contrôleur ne
   l'appelle. Sans cela, les permissions de dossier n'existent pas côté serveur.
2. **Alimenter la piste de révision.** La table est là, vide. Sans piste, pas de
   conformité GeBüV défendable, quel que soit le scellé.
3. **Réparer la recherche FULLTEXT.** Bug SQL 1064 identifié et localisé.
4. **Activer les modules déjà écrits** — ou les retirer de l'interface. Un module
   désactivé qui apparaît dans le menu produit des 404.
5. **Horodatage TSA réel.** `TSA_URL` à renseigner ; le code accepte déjà un
   transport injectable.

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
