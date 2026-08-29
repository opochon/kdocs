# Brief — GEDv1 / K-Docs

> Genere le 2026-08-29 08:37 depuis les instruments.
> Regenere a CHAQUE tour. Ne rien ecrire ici a la main : ce fichier est ecrase.
> Referent : M-Files + governance/ATTENDUS-PRODUIT.md

## Ce qu Olivier a demande et qui n est pas ferme

- `NON CABLE` **D-GED-01** — Atteindre le niveau de M-Files : introduire, lire, structurer, classer, versionner, interface claire, zero perte de donnees possible, stockage, securite
  points : SV-01, SV-02, SV-03, SV-04, SV-05, SV-06, SV-07, SV-08
  Formulation d'Olivier : « quand on atteint le niveau de M-Files, c'est-a-dire introduire, lire, structurer, classer, versionner, interface claire, zero perte de donnees possible, stockage, securite ». Detail deja pose dans governance/ATTENDUS-PRODUIT.md (A1-A4, B1-B12) — aucun agent ne modifie un attendu.
- `VERT` **D-GED-02** — Une facture fournisseur entrante : lire le QR, verifier que l'adressage est bien le mien, lire les coordonnees du vendeur, lire tous les produits avec leur montant, et que le total des produits + TVA corresponde au total de la facture
  points : SV-12, SV-13
  TROU. La question maitresse est l'egalite lignes + TVA = total : c'est la seule qui se verifie sans reference externe. Une extraction qui echoue la a echoue, quoi qu'elle rende par ailleurs. Le premier lot est d'ecrire ces points, pas l'extraction.
- `NON CABLE` **D-GED-03** — Classer la facture par date et par fournisseur, savoir si elle est payee ou non, voir la mention d'echeance, puis interroger K-Time pour savoir ou en est le paiement
  points : SV-11
  Le volet K-Time est la liaison ERP. Regle 4 du depot : le mock n'est pas une preuve — aller-retour reel contre KTIME_URL.
- `NON CABLE` **D-GED-04** — Zero suppression : aucune ligne n'est jamais supprimee d'une table par le produit, l'original sur disque n'est jamais modifie
  points : SV-06, SV-09, SV-10
  ATTENDUS-PRODUIT A4. Invariant de conception : si une seule piece peut disparaitre, c'est la conception qui est en cause, pas le code.
- `VERT` **D-GED-05** — Trancher comment traiter la liaison ERP
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

> ⚠ **VERDICT PERIME — ne compte pas.** Mesure du 2026-08-28 16:34 sur `dd9cd17`, HEAD est `d6fc448`.
> 18 commit(s) posee(s) depuis · 1 fichier(s) hors commit AU MOMENT de la mesure.
> Les chiffres ci-dessous ne jugent PAS le code d aujourd hui. Rejouer AVANT de conclure :
> 
>     node F:/DATA/DEVELOPPEMENT/EcosystemK/gouvernance/tools/recette.mjs socle

Cable : **23/29** · ~~derniere recette 2026-08-28 : 14V / 6R / 0INC / 9NC (29/29)~~ PERIME

Points NON CABLES — un point non cable ne prouve rien :
- `G-06` JC-GED extraction facture QR : Q-GED-01..08, dont Q-GED-05 (somme lignes+TVA = total) et Q-GED-14 (champ absent declare absent, jamais invente)
- `G-07` contre-jeu : suppression d'un document rattache a une ecriture = refus motive ; original jamais modifie
- `SV-02` D-GED-01 verbe 2/8 — lire : OCR et indexation plein texte (ATTENDUS-PRODUIT B2)
- `SV-05` D-GED-01 verbe 5/8 — interface claire : la fonction est atteignable par un utilisateur depuis l'interface (ATTENDUS-PRODUIT E.1)
- `SV-10` D-GED-04 — l'original sur disque n'est jamais modifie (ATTENDUS-PRODUIT A4, seconde clause)
- `SV-11` D-GED-03 — facture classee par date ET fournisseur, statut paye/non-paye, mention d'echeance, interrogation REELLE de K-Time sur l'etat du paiement

Points ROUGES :
- `G-01` preflight (vendor, playwright, DB, K-Time)
- `G-03` harness complet (gate)
- `G-05` contrat K-Time reel : aller-retour KTIME_URL /api/ged/health = 200
- `SV-07` D-GED-01 verbe 7/8 — stockage : la base est un dossier, le document reste lisible sans l'application (ATTENDUS-PRODUIT A1)
- `SV-20` Smoke complet des acces fonctions, mot pour mot Olivier 2026-08-11 : 'plus un 200 c'est ok'. Dix controles, chacun EXECUTE une fonction du produit et verifie un EFFET (base/disque/reponse d'un vrai controleur) : deposer, extraire+rechercher, decouper, classer au-dessus/en-dessous du seuil, supprimer (deleted_at), droits serveur (ACL reelle en base), audit, contrat K-Time (aller-retour reseau reel), coherence badge/page/base.
- `SV-21` Cohérence des compteurs « à classer » (DF-06) : badge sidebar admin, badge AJAX, page /mes-taches, total de tâches et base disent le même nombre ; la corbeille ne compte pas ; un fichier physique (checksum) n'apparaît qu'une fois en file ; deux passages du pipeline ne créent qu'une suggestion

## Deja tranche — ne pas redemander

- **fiabilite-cmd4-recherche** — Le bruit stdout est tolere COTE CLIENT (GED) et pas corrige a la source (ClearMyDocs) : la consigne d'Olivier du jour (« pas les sources ») et la regle 3 du depot (un depot en lecture seule reste en lecture seule) s'appliquent aux sources du moteur partage. Le client prend le payload ou il est. ; Push avec --no-verify trace dans SESSION-STATUS.md : l'echappatoire est celle du hook lui-meme, les causes du rouge sont preexistantes, multi-depots, et deux d'entre elles (points de partition) ne peuvent etre levees par un agent. ; Le troisieme correctif (prefixe search_query) fait partie des problemes identifies le matin et valides par Olivier (« tu as identifié les problèmes à traiter ») ; il est isole dans son propre commit pour rester revertible en une ligne par appelant si l'arbitrage change. ; Le .env est corrige sur place (fichier local, non versionne) : le VT etait une donnee corrompue, pas une configuration alternative. La preuve du chargement correct est rendue par describe() et par le split reel.
- **facture-qr-sv12** — Portee explicitement partielle, ecrite dans le nom de la sonde et sa documentation — pas de laisser-croire qu'un SV-12 vert ferme toute la demande D-GED-02.
- **correction-doublon-sv13** — Fournisseur de l'extracteur existant change pour AIProviderService plutot que de configurer une cle Claude : coherent avec le reste du produit (classification, OCR de repli) qui utilise deja la cascade multi-fournisseurs — une cle Claude dediee a cette seule fonctionnalite aurait cree un troisieme mecanisme de configuration IA. ; extractFromFile() degrade plutot que de reimplementer un envoi multimodal a la hate : une fausse promesse de couverture (methode presente mais qui echouerait silencieusement sur un vrai fichier) est pire qu'une absence documentee. ; InvoiceLineExtractionService n'est pas supprime : reconcile() est une fonction genuinement nouvelle (rien d'autre dans le depot ne recalcule ce verdict), la garder separee de l'extraction respecte la separation deja en place entre 'qui lit' (InvoiceLineItemExtractor) et 'qui juge' (jamais le modele, jamais l'extracteur lui-meme).
- **facture-lignes-ged-t2** — L'IA lit, elle ne juge jamais : reconcile() est une fonction pure et statique, testable sans reseau, qui recalcule le verdict a partir des valeurs extraites — jamais un champ 'matches' demande directement au modele. Un modele qui affirme sa propre coherence n'est pas une preuve (meme principe que la regle 2 EcosystemK appliquee a l'IA plutot qu'au code). ; Total de reference = total_ttc IMPRIME sur la facture, jamais la somme recalculee : sinon l'egalite serait tautologique (elle validerait toujours, quoi qu'il arrive). ; Oracle cible un document reel connu (id=901136) plutot qu'une recherche generique : la premiere tentative (LIKE '%TVA%' LIMIT 1) est tombee sur un document reel mais mal forme pour la demonstration (plusieurs pieces combinees) — corrige plutot que de baisser la tolerance ou d'assouplir le seuil pour le faire passer artificiellement.
- **facture-qr-t2** — Pas de code ecrit contre une donnee qui n'existe pas : construire une sonde qui compare des lignes inexistantes produirait soit un ABSENT permanent (sans valeur), soit — pire — une tentation de fabriquer des lignes a partir d'heuristiques de texte libre, ce qui est exactement l'extraction avancee que D-GED-06 interdit de reimplementer cote GED. ; Pas d'invention d'un profil 'mon entreprise' sans validation : une comparaison d'adressage fondee sur une valeur inventee (nom d'entreprise en dur, IBAN suppose) produirait un oracle auto-referentiel qui ne prouve rien (regle 7 EcosystemK).
- **liaison-erp-tranchee-sv14** — Correction du registre (tranche_par ajoute a une entree existante) plutot que nouvel arbitrage : l'entree portait deja tranche_le et choix, le seul champ manquant etait l'attribution — completer est plus honnete que dupliquer. ; L'oracle lit recette/arbitrages.json directement, pas un artefact intermediaire (governance/decisions/, truth.k.toml) mentionne dans l'ancienne note de SV-14 : le registre d'arbitrages existe deja et sert cette fonction pour A-GED-02, pas de raison d'en creer un second. ; G-07 laisse explicitement TROU sur instruction d'Olivier — pas invente, pas code contre une hypothese.

## Depot

- branche `main` · 0 fichiers modifies
- dernier commit : d6fc448 2026-08-29 chore(rapports) : harness du 29 (41/51, rouges attribues au journal) + brief regenere

## Definition de « fait » pour ce tour

Le point de la demande traitee est **cable ET vert**. Pas « aucun nouveau rouge » :
un socle majoritairement NON CABLE n a jamais de nouveau rouge, et ce vert est vide.
