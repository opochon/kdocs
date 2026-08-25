---
name: deja-fait
description: Inventaire obligatoire avant de produire quoi que ce soit — code, fichier, script, mesure, document, jeu de tests. A utiliser des qu'une tache implique d'ecrire ou de calculer quelque chose. Empeche de recoder, reecrire ou recalculer ce qui existe deja dans le depot, dans git, ou dans les instruments du projet.
---

# Deja fait — inventaire avant production

Generalise R19 : la regle porte sur **toute production**, y compris les mesures.

## Les quatre questions, dans l'ordre — reponse ECRITE

1. **Ca existe localement ?** Chercher par fonction, pas seulement par nom.
2. **Ca existe dans git ou un depot frere ?** `git log --all --diff-filter=A`,
   les autres depots d'EcosystemK, `trash/`, `docs/plans/`.
3. **Faut-il vraiment le produire ?** Souvent : etendre l'existant.
4. **Si oui, quoi exactement ?** Perimetre precis, ecrit avant de commencer.

## Verdict, en tete de tout lot

```
RIEN   — n'existe pas, production justifiee
PARTIE — j'etends l'existant ; je nomme ce que je reutilise
TOUT   — existe deja ; je pointe le fichier, je ne produis rien
```

Un verdict RIEN sur un projet mur est suspect : verifier deux fois.
Le doute penche vers PARTIE.

## Cas particulier : les mesures

**Ne jamais recalculer un etat que les instruments produisent.** Lire d'abord :
`docs/STATUS-SECTEURS.md` · `tests/reports/*-latest.json` · `recette/preuves/`
· `governance/backlog.json` · `team/briefs/`. Explorer l'arborescence a la main
pour estimer l'etat du projet est une faute : les instruments sont la source.

## Interdits

- un second outil qui fait ce qu'un existant fait a 80 %
- reecrire un harnais, un lanceur, un script de mesure
- recopier une regle transverse — on importe
- reconstruire une partition de memoire — on la charge
