# Defauts constates — GEDv1

> Tout defaut rencontre par un utilisateur reel devient un point de la partition.
> **Aucun defaut n est declare corrige tant que son point n est pas cable.**
> La colonne cause est obligatoire : un defaut sans cause revient sous une autre forme.

| id | defaut | date | cause | point ajoute |
|---|---|---|---|---|
| DF-01 | J ouvre un document, l apercu s ouvre a droite, puis la page se recharge sous moi. Impossible de travailler. | 2026-08-11 | `templates/layouts/main.php` appelait `window.location.reload()` 1.5 s apres une fin d indexation, depuis le **gabarit global** donc sur toutes les pages, sans aucune garde : ni apercu ouvert, ni formulaire en cours de saisie, ni position de defilement. Introduit par `a9c2eef` « Plan d amelioration production-ready ». | **manquant** — corrige (`bf496f3`) mais aucune sonde ne verifie qu un rechargement automatique ne revienne pas. A cabler. |
| DF-02 | Depuis « A traiter », je clique « Voir » sur un document a classer : 404. | 2026-08-11 | `app/Services/TaskUnifiedService.php` construisait `'link' => '/admin/consume'` en chemin brut, sans passer par `url()` qui prefixe `/kdocs`. Quatre assignations concernees, pas une. | `persona-dead-links` — cable, actif, `governance/specs.json`. Detecte la classe entiere : href de route interne sans prefixe, **avant** le clic. |
| DF-03 | « Deconnexion » ne fait rien d utile : 404. | 2026-08-11 | `templates/partials/header.php` pointait vers `url('/auth/logout')` alors que la route declaree est `/logout` (`index.php:164`). Le lien vit dans le menu utilisateur, donc present sur presque tous les ecrans en session. Jamais remonte parce que personne ne se deconnecte en developpement. | `persona-dead-links` — meme sonde, trouve au premier passage. |
| DF-04 | Pour classer, il faut ouvrir 195 documents un par un. Avant, ca se faisait dans la liste. | 2026-08-11 | Pas une regression du moteur : `/admin/consume` EST l ecran de classement et il classe en ligne. Trois causes cumulees — (1) DOUBLON, `/kdocs/mes-taches` et un troisieme ecran atteint du tableau de bord refont le meme travail ; (2) POINT D ENTREE, le bandeau « A traiter » mene au doublon et non a l ecran de classement ; (3) VOLUME, la file construit tous les formulaires d un coup. | **manquant** — D-GED-07 et D-GED-10 sont en TROU. Le point devra mesurer : un seul chemin pour classer, et un temps d affichage tenable a volume. |
| DF-05 | Le dossier scanne doit pouvoir etre ailleurs que sous la racine, et passe en revue tout seul a intervalle regulier. | 2026-08-11 | Trois moities absentes — (1) `templates/admin/settings.php` n expose aucun `storage[consume]`, la source est en dur ; (2) `templates/admin/scheduled_tasks.php` **affiche** `schedule_cron` en texte sans champ de saisie ; (3) la route `/admin/scheduled-tasks` n est atteignable par aucun menu. Et rien ne tourne : `last_run_at` NULL sur les quatre taches. | **manquant** — D-GED-08 en TROU. |
| DF-06 | « 195 documents a classer » et « A traiter 385 » sur le meme ecran. | 2026-08-11 | Les deux comptent la meme chose ; `helpers.php:162` omettait `deleted_at IS NULL`, donc additionnait la corbeille. `385 - 195 = 190` = le nombre exact de documents supprimes. La file de validation les presentait vraiment, formulaire par formulaire. Le badge reste par ailleurs un `max()` de deux grandeurs sans rapport (`helpers.php:174`). | **manquant** — corrige (`9925fb5`) mais aucune sonde ne verifie la coherence des compteurs entre eux. C est le constat n°1 du manuel et il n a pas d oracle. |

## Ce que ce tableau apprend

Six defauts, **tous trouves a l usage en une seance**, aucun par le harness — qui
etait vert sur 36 suites pendant ce temps. Quatre sur six n ont toujours **aucun
point de partition** : ils sont corriges ou nommes, pas outilles. Un defaut sans
point revient.

Deux d entre eux — DF-02 et DF-03 — sont desormais couverts par une meme sonde,
`persona-dead-links`, ecrite apres coup. C est la seule ligne de ce tableau ou le
systeme a appris quelque chose de reutilisable.
