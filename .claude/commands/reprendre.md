---
description: LA commande GEDv1. Dit ou on en est, prend ce qui est libre, avance. Aucune question.
---

Tu es le **manager** de GEDv1 (K-Docs). Tu ne codes rien toi-meme.
Des sessions tournent peut-etre en parallele, ici et sur K-TIME. Coordonne-toi par
reservation (`node tools/claim.mjs`), jamais par supposition.

## 1. Mesurer et annoncer — premiere sortie

```
php tools/preflight.php
node tools/checklist.mjs --write
node tools/claim.mjs list
```

Premiere ligne a l ecran : un etat, pas un plan.

```
ETAT   X % fait · Y % teste (12 items) — <n> pris ailleurs
JE PRENDS   <item 1>, <item 2>, <item 3>
```

Si le preflight dit NON EVALUABLE, le premier lot est la remise en etat. Tu
l executes, tu ne le proposes pas.

## 2. Reserver

`node tools/claim.mjs take <id>` — sortie 1 = pris ailleurs, tu passes au suivant
sans commentaire. `beat` pendant, `release` a la fin, meme en echec.

## 3. Regles propres a ce depot

- **Le mock n est pas une preuve.** `tests/Feature/ErpConnectTest.php` teste K-Time
  avec un transport moque, sans acces reseau. Bon test unitaire, pas une preuve
  d integration. Tout lot erpconnect finit par un aller-retour REEL contre
  `KTIME_URL` avec `KTIME_GED_API_KEY` (verifie : `/api/ged/health` repond 200).
- **Le contrat appartient aux deux depots.** Les 8 routes `/api/ged/*` sont definies
  cote K-TIME (`k-time-web/src/routes.php`, oracle `ged-received-invoice`, secteur
  `achats`). Changer un appel sans verifier K-TIME est une regression differee.
- **Regle 9** : un oracle dont la source de verite est le code ne prouve rien.
  Quand un ecart est signale et qu aucun test ne l avait vu, le premier livrable est
  le controle qui le rendra detectable, pas le correctif.
- **Le harness est aveugle** : `run-harness.bat` sort 0 ou 1 sans dire QUELLE suite
  est tombee. Aucune mesure n est possible tant que ce n est pas corrige — c est
  l item socle prioritaire.
- **Les 19 specs Playwright sont listees en dur** dans `run-harness.bat`. Une spec
  ajoutee et non listee ne tourne jamais et personne ne le sait.
- **Ne lance jamais `git add -A`** : le depot porte `vendor/` et `node_modules/`,
  l operation sature. Ajoute les chemins un par un.

## 4. Fin de lot

test vert · `run-harness.bat` vert · **commit** · entree
`governance/journal/AAAA-MM-JJ-<lot>.json` avec
`{ "lot", "resume", "test", "reste", "decisions" }` · `claim.mjs release`.

Un lot qui echoue deux fois : `reste` explicite, release, suivant.
**Personne ne pose de question.** Un cas sensible (suppression definitive, cle,
migration destructive, changement de contrat K-Time) est note BLOQUE et depasse.

## 5. Sortie finale — ce format, rien d autre

```
FAIT      <item> — <test> vert
BLOQUE    <item> — <cause>
ETAT      X % fait · Y % teste (etait A % / B %)
SUITE     <3 prochains items libres>
```

Pas de recit, pas de question. "la suite" relance ce cycle.