# Lot Codex — second auditeur du manuel utilisateur

Tu es **auditeur, pas correcteur**. Tu ne repares rien. Tu verifies des affirmations
et tu rends un verdict par affirmation.

## Pourquoi ce lot existe

`docs/MANUEL-UTILISATEUR.md` a ete redige le 2026-08-11 par une session Claude qui a
parcouru le produit ecran par ecran. Son recapitulatif final porte **17 incoherences**
affirmees. Deux affirmations de ce meme manuel se sont deja revelees FAUSSES :

1. « 50 balises image, 0 chargee » -> les miniatures rendent en realite `200 image/png`.
   La mesure lisait `naturalWidth` sur une page encore en cours de chargement.
2. « le mecanisme de declaration d'un dossier externe existe via `storage_paths` »
   -> cette table porte `match` / `matching_algorithm` : ce sont des regles de
   RANGEMENT, pas des sources a surveiller.

Deux erreurs sur des points ou l'auteur se croyait sur. **Presume donc que d'autres
affirmations sont fausses**, et va les chercher.

## Ce que tu produis

Un seul fichier : `docs/AUDIT-MANUEL.md`. Rien d'autre. Tu ne modifies ni le manuel,
ni le code, ni les tests, ni `recette/`.

Pour **chacune des 17 lignes** du recapitulatif final du manuel, un verdict :

| verdict | quand |
|---|---|
| `CONFIRME` | tu l'as reproduit toi-meme, et tu donnes la commande et sa sortie |
| `INFIRME` | tu as la preuve du contraire, et tu la donnes |
| `IMPRECIS` | le fait existe mais l'enonce le deforme, l'exagere ou en confond la cause |
| `NON VERIFIABLE` | tu n'as pas pu trancher, et tu dis pourquoi |

Un verdict sans sortie de commande ne vaut rien. **Preuve, pas affirmation** — c'est
la regle 6 de `EcosystemK/AGENTS.md`, qui s'applique nommement a toi.

## Comment verifier

Le produit tourne : `http://127.0.0.1:8765/kdocs`, compte `root`, **mot de passe
vide**. Base `kdocs` sur le port **3307**.

Ne te fie a AUCUNE mesure prise dans le navigateur sans t'assurer que la page a fini
de charger — c'est exactement le piege qui a produit la premiere erreur. Quand un
fait peut se verifier en HTTP ou en SQL plutot que dans un rendu, fais-le comme ca.

Les affirmations chiffrees du manuel (six valeurs pour « combien de documents »,
trois pour « ce qui attend », 385 formulaires, 6682 Ko, 190 documents supprimes dans
la file) sont toutes reproductibles : refais la mesure, ne recopie pas le chiffre.

## Ce qui compte le plus

Ne te contente pas de cocher les 17. **Ce qui vaut le plus, c'est ce que le manuel
NE DIT PAS** : un ecran qu'il decrit comme sain et qui ne l'est pas, une fonction
qu'il presente comme disponible et qui ne l'est pas, une methode de travail qu'il
recommande et qui ne marche pas. Ajoute une section « ce que le manuel ne voit pas ».

Attention en particulier a la confusion source/destination qui a deja piege l'auteur
une fois, et aux ecrans qu'il a documentes comme distincts alors qu'ils font le meme
travail.

## Interdits

- **Zero suppression** : aucune ligne effacee d'une table. 231 documents vivants,
  invariant absolu du depot.
- Ne clique sur aucune action destructrice : supprimer, purger, vider la corbeille,
  valider en masse.
- Jamais `git add -A` : ce depot porte `vendor/` et `node_modules/`.
- Tu n'ecris rien hors de `F:\DATA\DEVELOPPEMENT\GEDv1`. `K-TIME` est en LECTURE SEULE.
- Tu ne modifies aucun attendu (`governance/ATTENDUS-PRODUIT.md`) et tu ne poses
  jamais `adoptee_le` ni `tranche_le` : ces champs appartiennent a Olivier seul.

## Rapport final

En tete de `docs/AUDIT-MANUEL.md` : combien de CONFIRME, d'INFIRME, d'IMPRECIS, de
NON VERIFIABLE sur 17. Puis le detail. Puis « ce que le manuel ne voit pas ».

Un audit qui confirme 17 sur 17 sera lu comme un audit qui n'a pas cherche.
