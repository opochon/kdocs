# GEDv2 - point de reprise (stand-by du 16-10-2026)

Décision d'Olivier du 16-10 : GEDv2 passe en stand-by pour laisser la place à K-Time et ClearMyMails. On
garde le bénéfice de l'audit technique du 16-10. Ce fichier est le seul point d'entrée pour reprendre.

## État figé

- Ligne d'intégration : `origin/v2` (le `main` local de `F:\DATA\DEVELOPPEMENT\GEDv2` la suit, arbre propre).
- Mesuré le 16-10 sur M1 (lot `ged-meta-atomique`) : PHPStan niveau 6, baseline vide, **0 erreur** ;
  PHPUnit **963 tests, 14 échecs, tous PDF/A**, identiques avec ou sans ce lot (cause ci-dessous).
- Machine : toutes les tâches planifiées `GEDv2-*` sont désactivées, sauf `GEDv2-sauvegarde-push` (pousse
  chaque heure `main` vers `origin/v2`). Aucun agent GED ne tourne. Copies conservées : `GEDv2-juge`
  (branche `juge-fin`, copie du juge), `GEDv2-recette`, `_archives\GEDv2-*`.

## Objectif qualité (porte de tout lot, inchangée à la reprise)

1. PHPStan **niveau 6**, `phpstan-baseline.neon` vide ; une baseline ne grossit jamais.
2. PHPUnit **0 échec** ; harness `tools\harness.ps1` vert sur toutes les couches (banc compris avant la RC).
3. Contre-épreuve : un oracle ou une porte ne compte vert que montré ROUGE avant le correctif, VERT après.
4. L'auteur d'un lot ne le juge jamais ; une exclusion de parité est le fait du juge, jamais d'un agent.
5. Sécurité : une route sensible a son test de REFUS joué avec `X-GED-Teste-Garde: 1` (audit G3).

Le niveau d'exigence commun à tous les projets sera recalé après l'audit comparatif en cours ; cette
porte est le plancher de GEDv2.

## Ordre de reprise

1. **Juger `ged/ged-meta-atomique`** (audit G1, pas encore fusionnée) : `.meta.json` écrit atomiquement,
   sous verrou, relu depuis le disque. Journal et mesures : `docs/lots/ged-meta-atomique.md`.
2. **Lot PDF/A (Ingestion)** : les 14 échecs PHPUnit. La copie sort « contrôle partiel en échec :
   output_intent_srgb » ; `ControlePdfA` lit `/OutputIntent` dans les octets bruts, alors que Ghostscript
   10.03 écrit par défaut des flux d'objets compressés (`/ObjStm`). Lire la doc Ghostscript `pdfwrite`
   d'abord, puis trancher : options d'écriture sans flux d'objets, ou contrôle qui décompresse.
3. **`ged/ged-mfiles-fiche-1`** (réservation `Fiche\Verrou`, parité M-Files famille 5) : le dernier rejeu du
   juge est sauvegardé sur `origin/reval/ged-mfiles-fiche-1`. Avant fusion : harnais du 15-10 à rejouer sur
   arbre figé (1 écran rouge, sonde POST `/reglages` expirée, p95 lecture 7,6 s pour un seuil de 1,5 s, à
   instrumenter par sondes avant correction) ; `Verrou` doit écrire par `Fichiers::modifierMeta`.
4. **`ged/ged-mfiles-espace-1`** : branche distante non fusionnée, non examinée le 16-10.
5. Plan : V2-10 (RC), puis **V2-14** `ged-edition-poste-2` (réservation unifiée avec l'agent, ruban Office,
   OnlyOffice, crash tests F13b) - cahier des charges dans `docs/secteurs/edition-poste.md`.

## Audit du 16-10 (Codex) retenu pour GEDv2

- G1 `.meta.json` non atomique : traité par `ged/ged-meta-atomique` (à juger).
- G2 harnais rouge du 15-10 : points 2 et 3 ci-dessus.
- G3 garde de rendu contournée en test : règle 5 de la porte.
- G4 modules trop gros (`Jonctions\RessourcesApi` 2 059 lignes, `Jonctions\Catalogue` 1 353,
  `Socle\Relations` 900) : extraction par responsabilité, en lot final.
