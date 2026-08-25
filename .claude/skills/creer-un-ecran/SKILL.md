# Skill : creer-un-ecran

**Quand** : creer un ecran qui n existe pas encore, ou reprendre un ecran dont la FORME
est fausse (pas seulement le style). Declenche par « faire l ecran X », « reprendre
l ecran comme WinBiz », « il manque l ecran de Y ».

Ne pas confondre avec **refonte-ui**, qui uniformise un ecran deja juste vers le
design-system. Celui-ci decide de ce que l ecran DOIT contenir, avant tout style.

## La lecon du 12-08, qui justifie ce skill

Des semaines de description n ont pas produit l ecran devis attendu. Une matinee avec
les captures WinBiz cote a cote l a produit — et a fait ressortir au passage les
categories et les numeros de serie, invisibles apres des allers-retours.

> **Une description est interpretable. Une capture ne l est pas.**

D ou la regle : **pas de capture du referent, pas d ecran.** Si le referent n a pas ete
releve, le lot n est pas de faire l ecran, c est de relever le referent.

## 1. Relever le referent — AVANT toute ligne de code

Le referent est le logiciel que le client utilise aujourd hui (WinBiz, Outlook,
M-Files), ou l ecran voisin deja valide si le domaine n en a pas.

Relever dans `docs/ux-refs/<ecran>/` :
- les captures : liste, fiche, modale, menu contextuel, ecran de parametrage lie
- l inventaire ECRIT : chaque zone, chaque champ, chaque action, chaque onglet
- ce qui est **hors perimetre**, nomme explicitement — il ne produira aucun ecart

Puis inscrire la source dans `governance/sources.json`, au format du depot. Un ecran
construit sans entree de source est un ecran dont personne ne pourra dire s il est juste.

## 2. Distinguer les axes AVANT de dessiner

Le defaut le plus couteux n est pas esthetique, il est semantique : **collapser deux
axes distincts dans un seul champ**.

Cas reel : la liste devis affichait `STATUT : Accepte`. WinBiz porte quatre axes
independants — **Etat** (cycle de vie, CALCULE), **Classification** (ASSIGNEE, coloree,
un defaut par famille), **Categorie**, **Commercial**. `Accepte` est une classification,
pas un etat. Les fusionner casse l import, pas seulement l affichage.

Pour chaque colonne et chaque champ, ecrire : d ou vient la valeur, qui la choisit, ce
qui arrive quand elle est vide. Un axe calcule et un axe assigne ne partagent JAMAIS
un champ.

## 3. Ecrire les sondes avant l ecran

**Contradiction interne** — gratuit, aucun referent requis, transposable a tout ecran.
`EcosystemK/gouvernance/tools/oracle-tableaux.mjs` : colonne annoncee et vide (C1),
tri offert sur colonne vide (C2), entropie nulle des donnees de semis (C3), asymetrie
de filtres non declaree (C4), **type de l en-tete non respecte par les valeurs (C5)**.
C5 est le plus fort : une colonne DATE sans date n est pas un defaut d affichage, c est
le mauvais champ — et le tri, le filtre, l export et la logique metier l utilisent tous.

**Conformite au referent** — chaque element de l inventaire du §1 devient une assertion.

**Controle de mutation** — casser volontairement le cablage (ignorer le filtre, vider
la colonne) et verifier que la sonde le DETECTE. Une sonde qui reste verte quand on
sabote la cible est decorative. Sans ce controle, on ne sait pas si elle mesure.

**La sonde doit etre ROUGE avant la premiere ligne de code.** Verte du premier coup =
elle ne mesure rien.

## 4. Construire, dans cet ordre

1. **Structure** : zones, champs, onglets, actions. Aucun style.
2. **Donnees reelles** — jamais un semis uniforme. 7 294 lignes identiques rendent
   invisible une colonne vide : l uniformite neutralise l oeil humain.
3. **Etats** : vide, une ligne, volumineux, en erreur, verrouille. L etat verrouille
   doit etre coherent entre la liste et le detail.
4. **Style** — en dernier, via `refonte-ui` et le design-system.

## 5. Prouver

- les trois sondes vertes, dont le controle de mutation
- l ecran joue **en persona**, pas en admin : l admin voit tout, le scoping est invisible
- capture avant/apres dans `docs/ux-refs/<ecran>/`
- **le geste correspondant passe a la main** : `recette.bat ok N`. C est la seule
  signature. Un agent ne pose jamais `adoptee_le`.

## Anti-patterns constates

- Construire depuis une description au lieu d une capture.
- Un seul champ pour deux axes — erreur de modele, pas d affichage.
- Semer des donnees uniformes : ca masque exactement ce qu on cherche.
- Deux implementations du meme ecran (POC + officiel) vivant en parallele : deux
  verites qui divergeront. Decider laquelle survit, et le noter.
- Style avant structure. Un bel ecran faux reste faux.
