---
name: contre-jeu
description: Tests de refus et de destruction — tenter les actions illegitimes et verifier qu'elles echouent explicitement sans rompre la tracabilite. A utiliser apres qu'un flux nominal soit vert, avant toute liberation, et quand un invariant de conservation doit etre prouve.
---

# Contre-jeu — prouver que le systeme sait dire non

Le nominal montre que ca marche. Le contre-jeu montre que ca tient.
**Se joue apres le nominal, sur les memes donnees.**

## Trois familles

**1. Refus.** Tenter l'action interdite. Attendu : refus motive, piece intacte,
tentative journalisee. Sont rouges au meme titre : l'action reussit · echoue en
silence · echoue sur une erreur technique non explicite.

**2. Destruction.** Supprimer une piece referencee, un article mouvemente, un
document rattache, un message. **Zero destruction est un invariant de
conception.** Si une piece peut disparaitre : la conception est en cause,
remonter, pas rustiner.

**3. Survie de la tracabilite.** Apres chaque tentative, la chaine remonte
integralement. Une chaine interrompue est rouge meme si chaque maillon existe.

## Verification de la persistance

Toujours par **requete en base apres action**, jamais par l'ecran — une
interface peut afficher ce qu'elle tient en memoire. C'est ainsi qu'une regle
« tout en base » se viole sans etre vue.

## Rapport

`TENTE n · REFUSE n · REUSSI n (rouge) · SILENCIEUX n (rouge) · TRACABILITE oui/non`
Aucune ponderation, aucune contextualisation de gravite.
