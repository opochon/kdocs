# Brief — GEDv1 / K-Docs

> Genere le 2026-08-28 16:22 depuis les instruments.
> Regenere a CHAQUE tour. Ne rien ecrire ici a la main : ce fichier est ecrase.
> Referent : M-Files + governance/ATTENDUS-PRODUIT.md

## Ce qu Olivier a demande et qui n est pas ferme

- `NON CABLE` **D-GED-01** — Atteindre le niveau de M-Files : introduire, lire, structurer, classer, versionner, interface claire, zero perte de donnees possible, stockage, securite
  points : SV-01, SV-02, SV-03, SV-04, SV-05, SV-06, SV-07, SV-08
  Formulation d'Olivier : « quand on atteint le niveau de M-Files, c'est-a-dire introduire, lire, structurer, classer, versionner, interface claire, zero perte de donnees possible, stockage, securite ». Detail deja pose dans governance/ATTENDUS-PRODUIT.md (A1-A4, B1-B12) — aucun agent ne modifie un attendu.
- `NON CABLE` **D-GED-02** — Une facture fournisseur entrante : lire le QR, verifier que l'adressage est bien le mien, lire les coordonnees du vendeur, lire tous les produits avec leur montant, et que le total des produits + TVA corresponde au total de la facture
  points : SV-12, SV-13
  TROU. La question maitresse est l'egalite lignes + TVA = total : c'est la seule qui se verifie sans reference externe. Une extraction qui echoue la a echoue, quoi qu'elle rende par ailleurs. Le premier lot est d'ecrire ces points, pas l'extraction.
- `NON CABLE` **D-GED-03** — Classer la facture par date et par fournisseur, savoir si elle est payee ou non, voir la mention d'echeance, puis interroger K-Time pour savoir ou en est le paiement
  points : SV-11
  Le volet K-Time est la liaison ERP. Regle 4 du depot : le mock n'est pas une preuve — aller-retour reel contre KTIME_URL.
- `NON CABLE` **D-GED-04** — Zero suppression : aucune ligne n'est jamais supprimee d'une table par le produit, l'original sur disque n'est jamais modifie
  points : SV-06, SV-09, SV-10
  ATTENDUS-PRODUIT A4. Invariant de conception : si une seule piece peut disparaitre, c'est la conception qui est en cause, pas le code.
- `NON CABLE` **D-GED-05** — Trancher comment traiter la liaison ERP
  points : SV-14
  TROU — decision produit en attente, pas un defaut. Elle reste ouverte et visible jusqu'a arbitrage d'Olivier. Contexte : truth.k.toml declare ged->ktime `interroge+action`, et l'arete ktime->ged (archivage) est declaree mais absente d'ecosystem.k.toml.
- `TROU` **D-GED-06** — La GED detecte une facture fournisseur depuis l OCR, ne refait pas le travail, et affiche l interface de rapprochement servie par K-Time
  points : _aucun — ecrire le point AVANT le correctif_
  TROU. Contrepartie de D-KT-13. Le point doit rougir si une analyse de facture fournisseur ou un ecran de rapprochement apparait cote GED : ce serait une seconde implementation, donc deux verites qui divergeront.
- `TROU` **D-GED-07** — Le classement de base, c'est /admin/consume : la liste des documents a classer, chacun directement visible et classable sur place. « A traiter » (/kdocs/mes-taches) refait exactement le meme travail dans une autre liste. Deux ecrans pour un seul metier. · **redemandee 1x**
  points : _aucun — ecrire le point AVANT le correctif_
  CORRIGE le 2026-08-11 apres mise au point d'Olivier. Ma premiere redaction disait que le classement en ligne avait ete PERDU : c'est FAUX, il est a /admin/consume et il fonctionne — apercu a gauche, formulaire a droite, « Valider et classer ». Je l'avais moi-meme capture (docs/captures/04-validation-documents.jpg) sans reconnaitre que c'etait l'ecran de classement d'Olivier, et j'ai documente mes-taches et consume comme deux ecrans legitimes au lieu de nommer le doublon. Le vrai defaut est en trois morceaux : (1) DOUBLON — /kdocs/mes-taches refait le travail de /admin/consume ; (2) POINT D'ENTREE DEPLACE — le bandeau « A traiter » de la barre laterale, badge 385, mene a mes-taches et non a l'ecran de classement, et son bouton « Voir » tombe en 404 (TaskUnifiedService lignes 182-183, chemin sans prefixe) ; (3) VOLUME — /admin/consume construit tous les formulaires d'un coup : mesure du 2026-08-11, 385 formulaires, 1540 selects, 8462 options, 36824 noeuds, 6682 Ko de HTML, ni pagination ni recherche. C'est ce qui rend l'ecran intenable, pas son principe. Le point de partition devra mesurer : un seul chemin pour classer, et un temps d'affichage tenable a volume.
- `TROU` **D-GED-08** — L'import se fait dans un dossier. Dans les Parametres, on choisit CE dossier et tous les combien de temps il est passe en revue. · **redemandee 1x**
  points : _aucun — ecrire le point AVANT le correctif_
  TROU. Verifie le 2026-08-11, les trois moities manquent : (1) LE DOSSIER n'est pas reglable — templates/admin/settings.php expose storage[type], storage[base_path], storage[allowed_extensions], mais AUCUN storage[consume]. La source est en dur : F:\DATA\DEVELOPPEMENT\GEDv1\storage\consume, sous la racine du produit. (2) LA FREQUENCE n'est pas reglable — templates/admin/scheduled_tasks.php AFFICHE schedule_cron en texte (htmlspecialchars), sans aucun champ de saisie. Le seul bouton est « Executer » a la main. On peut lancer, pas planifier. (3) L'ECRAN EST ORPHELIN — la route /admin/scheduled-tasks existe mais aucun lien n'y mene : ni sidebar_admin.php, ni carte du hub AdminController. Et rien ne tourne : index_filesystem est declaree toutes les 6 h, active, last_run_at NULL, aucun ordonnanceur sur le poste. ERREUR D'AGENT CORRIGEE : j'avais ecrit que le mecanisme de declaration d'un dossier externe existait via storage_paths. Faux — cette table porte match / matching_algorithm / is_insensitive, ce sont des regles de RANGEMENT (ou deposer un document selon son contenu), pas des sources a surveiller. Confondu destination et source sur le mot `path`.
- `TROU` **D-GED-09** — Le badge « A traiter » affiche max(documents en attente, taches de l'utilisateur) — deux grandeurs sans rapport. Choisir ce qu'il compte.
  points : _aucun — ecrire le point AVANT le correctif_
  DERIVE — proposition d'agent, NON VALIDEE. helpers.php:174 : `max($stats['pending_validation'], $stats['tasks'])`. Le badge vaut juste tant que les deux coincident et ment des qu'elles divergent. Le 2026-08-11 il affichait 385 quand la page annoncait 195, parce que pending_validation additionnait la corbeille (corrige depuis, commit 9925fb5). Le max() lui-meme n'est pas corrige : c'est un choix d'affichage, il appartient a Olivier. Question fermee : le badge compte-t-il les documents a classer, les taches, ou leur somme ?
- `TROU` **D-GED-10** — Trois ecrans se disputent le meme travail : /admin/consume, /kdocs/mes-taches, et un troisieme atteint depuis le tableau de bord. Un seul chemin pour classer.
  points : _aucun — ecrire le point AVANT le correctif_
  DERIVE — proposition d'agent, NON VALIDEE. Prolonge D-GED-07 : Olivier avait nomme le doublon consume/mes-taches, l'audit contradictoire du 2026-08-11 a trouve un TROISIEME ecran de taches atteignable depuis le tableau de bord (docs/AUDIT-MANUEL.md, section « ce que le manuel ne voit pas »). Constat annexe : /kdocs/mes-taches n'inclut meme pas le gabarit layouts/main.php — pas de sonde d'indexation, pas de bandeau — ce qui explique qu'il derive au lieu de completer.
- `TROU` **D-GED-11** — Une page inexistante rend la trace de pile complete : chemins du serveur, arborescence vendor/. A couper avant toute mise a disposition.
  points : _aucun — ecrire le point AVANT le correctif_
  DERIVE — proposition d'agent, NON VALIDEE. Reproduit le 2026-08-11 sur une URL absente (capture docs/captures/07-404-trace-de-pile.jpg) : type d'exception Slim, fichier, ligne, et dix niveaux de pile avec les chemins absolus du poste. Confirme par l'audit contradictoire (constat 16, CONFIRME). Sans consequence sur un poste de developpement, inacceptable des que le produit est joignable par quelqu'un d'autre.
- `TROU` **D-GED-12** — La file de validation n'a jamais ete videe : le plus ancien document attend depuis le 25 janvier 2026 et UN SEUL document a ete valide dans toute la vie du produit.
  points : _aucun — ecrire le point AVANT le correctif_
  DERIVE — proposition d'agent, NON VALIDEE. Ce n'est pas un defaut de code, c'est un constat d'usage qui rend les autres mesurables : tant que la file n'est jamais videe, elle grossit, la page s'alourdit, et le badge reste rouge en permanence — donc il cesse d'etre un signal. Question ouverte : reprend-on la file existante, ou repart-on d'une base de travail nettoyee par un outil externe precede d'un dump (ce que la regle 5 autorise, hors application) ?

## Socle

> ⚠ **VERDICT PERIME — ne compte pas.** Mesure du 2026-08-10 10:10 sur `?`, HEAD est `dd9cd17`.
> commit de la mesure inconnu.
> Les chiffres ci-dessous ne jugent PAS le code d aujourd hui. Rejouer AVANT de conclure :
> 
>     node F:/DATA/DEVELOPPEMENT/EcosystemK/gouvernance/tools/recette.mjs socle

Cable : **20/29** · ~~derniere recette 2026-08-10 : 7V / 2R / 0INC / 12NC (21/21)~~ PERIME

Points NON CABLES — un point non cable ne prouve rien :
- `G-06` JC-GED extraction facture QR : Q-GED-01..08, dont Q-GED-05 (somme lignes+TVA = total) et Q-GED-14 (champ absent declare absent, jamais invente)
- `G-07` contre-jeu : suppression d'un document rattache a une ecriture = refus motive ; original jamais modifie
- `SV-02` D-GED-01 verbe 2/8 — lire : OCR et indexation plein texte (ATTENDUS-PRODUIT B2)
- `SV-05` D-GED-01 verbe 5/8 — interface claire : la fonction est atteignable par un utilisateur depuis l'interface (ATTENDUS-PRODUIT E.1)
- `SV-10` D-GED-04 — l'original sur disque n'est jamais modifie (ATTENDUS-PRODUIT A4, seconde clause)
- `SV-11` D-GED-03 — facture classee par date ET fournisseur, statut paye/non-paye, mention d'echeance, interrogation REELLE de K-Time sur l'etat du paiement
- `SV-12` D-GED-02 — lire le QR d'une facture, verifier que l'adressage est le mien, lire les coordonnees du vendeur, lire les lignes produits avec montants
- `SV-13` D-GED-02 — egalite somme des lignes + TVA = total facture (question maitresse, verifiable sans reference externe)
- `SV-14` D-GED-05 — la liaison ERP a une decision tranchee et tracee (pas codee) sur son traitement

Points ROUGES :
- `G-03` harness complet (gate)
- `SV-07` D-GED-01 verbe 7/8 — stockage : la base est un dossier, le document reste lisible sans l'application (ATTENDUS-PRODUIT A1)

## Deja tranche — ne pas redemander

- **ingestion-parc-courriers** — storage/courrier-matin/** ajoute au .gitignore dans la meme categorie que /storage/documents (donnees utilisateur, jamais versionnees) — pas une exception au motif existant, un trou dans sa couverture : config/config.php pointe une racine storage differente du defaut sans que le .gitignore l'ait jamais su. ; Le commit du lot ne porte pas la correction du .gitignore : deux prealables independants (fuite de donnees potentielle vs fonctionnalite), deux commits, pour que l'un ne masque pas l'autre dans l'historique. ; eval-full non force a completion plutot que tue une troisieme fois sans resultat exploitable : le temps d'execution disponible en session est une contrainte de l'environnement (documentee dans AGENTS.md — sous-agents tues a 600 s), pas un defaut du produit ; noter le manque plutot qu'affirmer un vert non observe (regle 6 EcosystemK, preuve pas affirmation).
- **personas-retours** — Les retours de personas vivent dans recette/defauts.md, pas dans un nouveau mécanisme : le tableau existant est le registre unique des défauts constatés, avec cause obligatoire — un deuxième registre diluerait. ; DF-01 reste NON CABLÉ volontairement plutôt que câblé sur un environnement contaminé : un point qui mesurerait la contention au lieu du défaut fabriquerait du vert/rouge sans valeur (règle 2 étendue à l'environnement de mesure).
- **interface-modulaire-slots** — Fondation + pilotes (arbitrage d'Olivier du 2026-08-25) : la migration complète des gabarits attendra les lots suivants — un big-bang des 95 templates sur une queue de tests flaky n'aurait produit aucune preuve exploitable. ; La preuve du lot est une SONDE DE RENDU PHP (test_ui_modulaire.php), pas une spec Playwright : déterministe, insensible à la contention documentée du serveur monothread. Les specs chrome existantes (admin-hub, chrome-coherence) ont été rejouées pour prouver la non-régression du shell réel. ; Le gating vit dans le registre (PluginRegistry::isEnabled), pas dans les gabarits : un module éteint disparaît de l'interface tout seul — c'est la traduction directe de « un module desactive ne doit pas rester visible et produire des 404 » (invariant du secteur). ; K-Portail déclare son slot dès maintenant alors que l'app est éteinte : coût nul, et la sonde prouve par effet qu'il reste invisible — le jour de l'allumage, zéro modification du shell.
- **versioning-etat-des-lieux** — La bascule is_current vit dans le modèle (DocumentVersion::create), pas dans un trigger : l'erreur 1442 n'est pas un bug de code mais une limite du moteur — aucun trigger AFTER INSERT ne peut mettre à jour sa propre table. Le trigger before_document_version_insert (numérotation) reste : un SELECT en BEFORE est légal et il fonctionne. ; La garde « suivi par git » du versioning ne s'applique qu'hors des arbres de stockage GED : pour un document du fonds, l'attendu A3 (versions à côté du fichier) prime sur un accident d'index git du repo de déploiement. ; L'attribut caché Windows (+H) plutôt qu'un préfixe de nom : le dossier reste .versions/ (convention mac posée par l'architecture du 2026-08-09) et disparaît de l'Explorateur sans configuration, sans jamais gêner l'accès direct par chemin. ; Répartition des déclencheurs (arbitrage d'Olivier, « les deux ») : la page admin ne fait QUE le snapshot métadonnées (rapide, intervalle-gardé) ; l'outil cron porte l'indexation complète — le travail lourd ne retourne jamais dans une requête d'affichage (leçon du lot ingestion : le serveur est monothread). ; Les quatre corrections de SnapshotService ne sont pas des choix d'architecture mais des alignements au schéma réel (le service n'avait jamais tourné) : chaque correction cite la colonne ou table réelle visée. ; La réconciliation purge désormais last_classified_by avec le type imposé retiré : un marqueur de classification sans classement est un mensonge d'état, et l'invariant SV-20 contrôle 5 le fait rougir à juste titre.
- **ingestion-x2-dossier-invisible** — Le seuil 0.8 et auto_apply=true ne sont pas touchés (règle 9 : ils appartiennent à Olivier). ; Un document classé par le tri auto au-dessus du seuil QUITTE la file (pending -> validated). C'est la lecture directe du texte SV-19 (« ceux qui dépassent le seuil sont classés ») et la réponse à D-GED-12 (file jamais vidée : rien ne sortait jamais). needs_review n'est jamais franchi par l'IA : une relecture demandée par un humain ne se lève pas toute seule. ; Un classement HUMAIN n'est jamais écrasé par le tri auto : l'IA remplace au plus un classement IA antérieur (last_classified_by='ai'). Motivation mesurée : la facture synthétique d'eval-full était re-typée au premier classify-ai suivant ; pour un utilisateur réel, la même garde protège toute validation manuelle. Contre-épreuve câblée dans SV-17 (volet 3). ; L'étape 4 de la sonde SV-19 est corrigée pour mesurer ce que le point énonce (classés visibles, suggérés en file) au lieu d'exiger l'impossible (tout en vue standard). L'ancienne formulation contredisait le point qu'elle devait mesurer et serait restée rouge par construction. Tracé ici et dans recette/partition.json. ; tools/eval-full.php : l'heuristique d'identification ne s'applique plus qu'aux documents SANS type (garde document_type_id IS NULL). L'écriture inconditionnelle écrasait la confiance d'un classement IA appliqué (>= seuil) par une confiance heuristique 0.20 — c'est l'outil de mesure qui FABRIQUAIT les violations « ai classé sous le seuil » détectées ensuite par le smoke contrôle 5. Les 5 violations du 2026-08-11 étaient des artefacts de l'outil, pas du produit. ; Réconciliation des données par outil tracé et ré-exécutable (--dry-run par défaut dans la procédure), marquage deleted_at uniquement, jamais de DELETE ni de checksum effacé. La ligne gardée par groupe : fichier existant en priorité, sinon id le plus petit. ; DF-05 partiellement couvert : storage[consume] réglable dans Paramètres + existence du dossier + dernier scan affiché (rendu réel vérifié, 41986 octets). Le champ cron et la route orpheline /admin/scheduled-tasks restent. ; Deux exécutions complètes du harness ce jour (point zéro puis à blanc après correctifs) — la seconde fait foi pour l'état du dépôt ; les preuves post-run (garde humaine) sont rendues par leurs propres sondes et le phpunit complet.
- **etat-commit-gate** — Le commit d'état se fait AVANT tout correctif — figer d'abord, mesurer ensuite : c'est la condition pour que le point zéro du gate soit attribuable. ; Le bug d'échappement du harness est corrigé dans ce lot plutôt que reporté : un faux rouge persistant décrédibilise le gate autant qu'un faux vert. ; Aucune modification du .env KTIME_URL : la preuve réelle (200) a remplacé l'hypothèse de correctif. On ne répare pas ce qui répond.

## Depot

- branche `main` · 0 fichiers modifies
- dernier commit : dd9cd17 2026-08-28 chore(rapports) : eval-full rejoue seul (--no-ocr) — OK, merge dans harness-latest.json

## Definition de « fait » pour ce tour

Le point de la demande traitee est **cable ET vert**. Pas « aucun nouveau rouge » :
un socle majoritairement NON CABLE n a jamais de nouveau rouge, et ce vert est vide.
