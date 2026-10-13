# Archivage, versions et réversibilité — « le dossier fait foi »

Principe directeur de GEDv2 face au modèle « coffre » (vault) de M-Files : **ne jamais forcer
l'abandon du serveur de fichiers**. On pose la GED sur un partage et il devient immédiatement un
système en ligne connecté — sous Windows comme sous Linux. Celui qui n'a pas accès à la GED
continue de travailler directement sur le serveur de fichiers.

## 1. Le file server reste la source de vérité
- La GED indexe un partage existant ; la base reste un index reconstructible.
- On doit pouvoir revenir à tout moment à une gestion de fichiers traditionnelle, sans migration
  monstrueuse ni renommages ingérables (le piège classique du re-migrage « dans l'autre sens »).
- Le langage et les conventions de nommage évitent toute incompatibilité avec un autre système.
  Migration Windows ↔ Linux : prévoir le rattrapage des noms de fichiers.

## 2. Scan initial et empreintes
- Premier balayage de la totalité du dépôt : une empreinte (hash) pour **tous** les fichiers.
- Permet de détecter les fichiers modifiés directement sur le serveur de fichiers (hors GED).
- Permet de retrouver un déplacement ou une suppression faite hors GED : par le hash, on retrouve
  où un fichier est parti, et on en garde une trace.
- Exemple d'usage : « le fichier x.xml n'est plus disponible » → la GED répond où il se trouve
  désormais, où était sa version précédente ; avec le journal Windows, qui l'a déplacé ; et si
  l'action a été faite depuis la GED, toute l'information est déjà là.

## 3. Versions
- Pour chaque fichier, ses versions précédentes vivent dans un dossier masqué `.<nom du fichier>`
  à côté du fichier, directement dans le système de fichiers (conservable sur le serveur ou non).
- **Mais** ce dossier masqué peut être supprimé par un malveillant : on ne laisse donc pas
  vraiment ce choix comme garantie. Les versions, les fichiers déplacés et supprimés sont aussi
  copiés dans un **dépôt séparé** (un autre repository), avec la correspondance vers l'emplacement
  d'origine sur le stockage et les métadonnées associées (extraites des fichiers au besoin par la
  GED). Le dépôt séparé est la garantie ; le dossier masqué local est le confort.
- On évite de surcharger la base de données avec le contenu des versions.

## 4. Ce que la GED apporte par-dessus le partage
- Recherche ultra-rapide, affichage des documents, et tout ce qu'une GED doit offrir.
- Pas un simple classeur filtrable : analyse des documents (expéditeur, destinataire, type de
  document), déjà produite par la chaîne de classement automatique.

_(Spécification dictée par Olivier Pochon le 13.10.2026 — à affiner en lots.)_
