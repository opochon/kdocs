# ATTENDUS PRODUIT — GEDv1 / K-Docs

> **Posé par Olivier Pochon (Karbonic), 2026-08-09.** Transcrit sans interprétation.
> Fait foi au titre du §3 de `JEUXDECONTROLE.md` : un agent peut proposer des
> questions, **jamais** produire ou modifier un attendu. Seul Olivier adopte une
> nouvelle valeur ; l'ancienne reste versionnée.
>
> État mesuré en regard : `docs/STATUS-SECTEURS.md` (généré).
> Comparatif : `docs/EQUIVALENCE-M-FILES.md`.

---

## L'attendu, en une phrase

**Une GED opérationnelle** : l'équivalent fonctionnel de M-Files, plus les
fonctions propres à Karbonic, sur un socle où **la base est un dossier**.

Le point de départ n'est pas un prototype. C'est une excellente GED qui
fonctionnait. L'attendu n'est donc pas « faire mieux qu'un brouillon », c'est
« retrouver et dépasser un niveau produit ».

---

## A. Le socle — la différence assumée avec M-Files

**A1. La base est un dossier.** Le fichier sur disque est la source. On pose la
GED sur un stockage existant sans tout importer.

C'est la **seule** divergence volontaire avec M-Files, et elle est un gain, pas
un retard :

- pas de blob en base, pas de nom de fichier aberrant ;
- le document reste ouvrable directement, sans l'application ;
- sauvegarde et reprise triviales.

**A2. Les métadonnées font tout le reste.** Recherche transverse, dossiers
filtrés, vues dynamiques. Un dossier « Factures » rassemble toutes les factures
où qu'elles soient, sans déplacer un fichier.

**A3. Versions rangées à côté du fichier**, dans un sous-dossier caché voisin
(modèle `.versions/`, convention comparable à `.DS_Store`). Jamais en base.

**A4. Zéro suppression.** Aucune ligne n'est jamais supprimée d'une table par le
produit. Reconstruire une base pour les tests est légitime mais **n'appartient
pas à l'application** : outil externe, précédé d'un dump.

---

## B. Équivalence M-Files — attendu fonctionnel

Tout ce qu'un ECM fiduciaire suisse doit savoir faire :

| | Attendu |
|---|---|
| B1 | Capture — scan, mail, upload, dépôt dans un dossier surveillé |
| B2 | OCR et indexation plein texte |
| B3 | Organisation par métadonnées, vues dynamiques (cf. A2) |
| B4 | Workflows de validation, approbations, notifications |
| B5 | Archivage légal suisse — GeBüV / Olico, rétention 10 ans, horodatage |
| B6 | Sécurité — droits par rôle, permissions de dossiers **vérifiées côté serveur** |
| B7 | Piste de révision complète, exportable |
| B8 | Contrôle de version |
| B9 | Recherche par métadonnées, contexte, IA |
| B10 | Intégration comptable |
| B11 | Qualité / SMQ, dossiers RH, contrats, portail client |
| B12 | Hébergement souverain, sans SaaS tiers pour les documents |

---

## C. Le cycle documentaire — cœur de l'attendu Karbonic

**C1. Ingestion** d'un document.

**C2. Analyse** du document.

**C3. Classement** — le document trouve sa place.

**C4. Proposition de classement** — le système propose, l'humain adopte.

**C5. Classement automatique** — sans intervention, une fois la règle acquise.

**C6. Création de règles réutilisables** — l'apprentissage d'un classement
produit une règle qui reclasse automatiquement les suivants. Ce qui a été
tranché une fois ne se redemande pas.

---

## D. Intégrations et mode plugin

**D1. Détection de facture → connexion K-Time.** Quand l'analyse conclut qu'il
s'agit d'une facture, la pièce part vers K-Time.

> Rappel de la décision du 2026-07-03, non renégociée ici : la GED **n'écrit
> jamais** dans WinBiz. CMD v4 extrait, K-Time introduit, valide et synchronise.

**D2. Mode plugin** — architecture d'extensions de plein exercice.

**D3. Plugin K-Time.**

**D4. Plugin ClearMyMails.**

---

## E. Ce que « opérationnelle » veut dire

Un attendu n'est pas atteint parce que le code existe. Il est atteint quand :

1. la fonction est **atteignable** par un utilisateur depuis l'interface ;
2. elle est **câblée** — le service est réellement appelé par le produit, pas
   seulement écrit et testé unitairement ;
3. un **oracle du harness** l'atteste, et cet oracle exécute le chemin réel ;
4. le module est **activé** dans l'installation, pas seulement livré derrière un
   drapeau absent du `.env`.

Le point 2 n'est pas théorique. Constat du 2026-08-07 : `FolderPermissionService`
était écrit, correct et couvert par 10 tests verts — et appelé par aucune ligne
de code applicatif. Les permissions de dossiers n'existaient pas en service alors
que tous les voyants étaient au vert. C'est l'état **FANTÔME** du registre des
secteurs.

---

## F. Ce qui n'est pas dans l'attendu

Écrit pour éviter la dérive de périmètre, à confirmer si besoin :

- application mobile native ;
- bascule vers un modèle 100 % métadonnées sans fichiers sur disque — explicitement
  écarté par A1 ;
- écriture dans WinBiz depuis la GED — écarté par D1.
