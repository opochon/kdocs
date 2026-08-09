---
name: erpconnect
description: "Integration K-Time et contrat /api/ged : erp_links, CMD v4, health, simulateur et lint-contrat. Invoquer pour erpconnect, KTIME_URL, WinBiz ou contrat GED K-Time."
tools: Read, Grep, Glob, Edit, Write, Bash
---

# Périmètre : Integration K-Time — contrat /api/ged/*

> **L'état ne se lit pas ici** — il se calcule : `node tools/status-secteurs.mjs`.
> Un verdict figé dans un fichier de prose pourrit. Voir `docs/STATUS-SECTEURS.md`.
> Registre : `governance/sectors.json` · Règles : `AGENTS.md` · Attendus : `governance/ATTENDUS-PRODUIT.md`

## Invariant

La GED n ecrit JAMAIS dans WinBiz. Le flux passe par CMD v4 (extraction) puis K-Time (introduction, validation, sync). Le contrat appartient aux deux depots : K-TIME est en LECTURE SEULE depuis GEDv1.

Il protège la frontière WinBiz et le contrat partagé : CMD v4 extrait, K-Time introduit, valide et synchronise.

## Scope

**Fichiers** : `apps/erpconnect/`, `tools/lint-contrat.mjs`, `governance/contrat-ged-ktime.json`

**Tables** : `erp_links`

**Routes** : `/erpconnect/*`

**Dépend de** : `securite-acl`

## Oracles

- `ktime-contract` : VERT au dernier harness.
- `api-key-redaction` : VERT au dernier harness.
- `erp-connect` : ROUGE au dernier harness ; le simulateur attendu sur `127.0.0.1:8091` est absent.

## État connu

Le secteur est le mieux prouvé du produit.

Le contrat de huit routes est versionné, confronté au dépôt K-Time sur disque et au serveur vivant, dont `health` répond 200.

`erp-connect` est ROUGE : la spécification attend le simulateur sur `127.0.0.1:8091`, absent. C'est un prérequis d'environnement, pas une régression.

## Ce qu'il faut faire ensuite

1. Établir le simulateur attendu sur `127.0.0.1:8091`.
2. Rejouer `erp-connect` contre ce prérequis d'environnement.
3. Continuer à confronter le contrat au dépôt K-Time et au serveur vivant.
4. Préserver la frontière : la GED n'écrit jamais dans WinBiz.

## Pièges de ce secteur

K-TIME est en LECTURE SEULE depuis GEDv1. Un mock n'est pas une preuve : un lot erpconnect finit par un aller-retour réel contre `KTIME_URL` sur `/api/ged/health` avec 200.
