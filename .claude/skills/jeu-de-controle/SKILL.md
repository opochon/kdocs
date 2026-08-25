---
name: jeu-de-controle
description: Construire, sceller ou faire evoluer un jeu de questions a reponse connue pour verifier un produit ou un corpus documentaire. A utiliser avant une recette, avant un candidat de version, quand un defaut constate doit devenir un test permanent, ou pour juger la completude d'un recapitulatif. Couvre la derivation depuis un produit de reference.
---

# Jeu de controle — questions a reponse connue

Un systeme ne peut pas juger sa propre completude. Le seul instrument qui y
echappe : une question dont la reponse est connue **avant** l'execution.
L'agent repond ; la comparaison est arithmetique.

## Les six registres

| Registre | Question type | Seuil |
|---|---|---|
| ancrage | une valeur unique, actuelle | exact |
| attribution | qui, sur quoi, depuis quand | exact |
| enumeration | liste fermee, exhaustive | manquants + surnumeraires |
| tracabilite | remonter une piece a son origine | chaine complete |
| invariant | vrai quoi qu'on fasse | exact, connu d'avance |
| refus | l'action illegitime echoue explicitement | refus motive + trace |

Un jeu serieux couvre au moins quatre registres. Toute question d'ancrage porte
sa **piece d'appui** : une reponse juste sur un document perime est juste par accident.

## Nature des attendus (modele JC-VENTE, K-Time)

Chaque question porte `nature` :

- **derive** — calculable depuis le scenario, se scelle sans arbitrage
- **invariant** — connu sans connaitre le projet, ne se negocie pas
- **a_sceller** — depend d'un choix produit ; **l'agent ne le remplit jamais**

`gele_le: null` = **mode releve** : le jeu tourne, repond, affiche les ecarts,
mais ne peut pas etre rouge — une cible non scellee ne juge rien. Olivier pose
la date pour sceller.

## Derivation depuis le referent — l'agent ne demande pas

Interdit de demander a Olivier quoi tester dans un domaine qui a un referent
(Outlook, WinBiz, M-Files). Protocole : nommer le referent · nommer le
hors-perimetre par ecrit · se placer dans le ROLE (juriste, assistante) ·
deriver · ne remonter que les seuils chiffres et arbitrages.

## Question gelee, reponse versionnee

La question ne change jamais. La reponse est versionnee (piece d'appui + date).
L'agent recalcule et produit un **ecart** ; Olivier adopte ; l'historique reste.
Ecart **justifie** (piece nouvelle) → adoption. Ecart **injustifie** (rien n'a
bouge) → **defaut du systeme**, rouge.

## Construction

Attendus figes avant execution · 15-20 questions · seuil brutal (exact ou faux,
jamais de pourcentage) · 2-3 questions dont la bonne reponse est « pas
disponible » · differentiel entre passes · corpus documentaires : corpus clos +
motifs d'ecartement a vocabulaire ferme + recapitulatif gele.

Ne remplace pas la seance humaine : jeu vert d'abord, 5 personnes reelles ensuite.
