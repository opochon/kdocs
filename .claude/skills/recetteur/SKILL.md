---
name: recetteur
description: Jouer la partition de non-regression avant d'envoyer une version a un utilisateur ou de faire une demonstration client, quelle que soit la taille de la modification, y compris un correctif d'une ligne.
disable-model-invocation: true
---

# Recetteur — jouer la partition

Le recetteur constate. Il ne corrige pas, ne pondere pas, ne modifie pas la
partition.

## Protocole

1. `node F:/DATA/DEVELOPPEMENT/EcosystemK/gouvernance/tools/recette.mjs socle`
   — jamais reconstruite de memoire.
2. **Integralement.** Aucun point saute, y compris ceux sans rapport avec la
   modification — c'est precisement le point sans rapport qui casse.
3. Une **preuve par point** (le script les ecrit dans `recette/preuves/`).
4. **Un rouge = liberation bloquee.** Un NON CABLE en mode `--release` aussi.
5. Ne jamais corriger : rapporter au poste de ligne concerne.
6. Ne jamais modifier `recette/partition.json` pendant une recette. L'ajout
   d'une question passe par Olivier, hors session.

## Deux niveaux

| Niveau | Quand | Cible |
|---|---|---|
| `socle` | chaque liberation, sans exception | < 15 min |
| `full` | version majeure, avant demo client | libre |

Une partition trop longue ne sera pas jouee ; non jouee, elle est pire que rien.
Si le socle depasse 15 minutes : le reduire, pas l'ignorer.

## Defauts constates → questions permanentes

Aucun defaut rencontre par un utilisateur reel n'est declare corrige tant que
sa question n'est pas dans `recette/partition.json`, cause documentee dans
`recette/defauts.md`. Un defaut sans cause reviendra sous une autre forme.

## Demonstration client

Le parcours est joue **deux fois d'affilee**, la seconde sans rien toucher, sur
donnees reelles, par quelqu'un qui n'a pas ecrit le code. S'il faut dire
« attends », c'est rouge. Une demo repoussee coute une semaine ; une demo ratee
coute le client.
