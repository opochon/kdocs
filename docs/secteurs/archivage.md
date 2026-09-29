# Secteur ARCHIVAGE LÉGAL — scellement, horodatage, rétention

Agent : DeepCode · Juge : Claude Code · Lot : V2-09

## Mission
Un document scellé devient intouchable pendant sa durée légale (10 ans par défaut, CO art. 958f ; OLICO pour la forme) :
- **Scellement** : empreinte sha256 du fichier et de son `.meta.json`, jeton d'horodatage RFC 3161 (TSA) sur cette
  empreinte, le tout écrit dans le dossier (`.scellement/<fichier>.tsr` + `.json`) — le dossier fait foi.
- **Intouchable** : toute écriture (mise à jour des métadonnées, nouvelle version, corbeille, déplacement) sur un document
  scellé est refusée **au niveau du service** (Socle `DepotFichiers`, corbeille, workflows), pas route par route — c'est
  le défaut exact de GEDv1 (5 routes gardées, le reste ouvert).
- **Vérification** : un outil rejoue l'empreinte et le jeton ; un fichier altéré hors application est signalé.
- **Rétention** : échéance calculée par type (table de durées modifiable), liste « à échéance », jamais d'effacement
  automatique — la fin de rétention propose, un humain décide (et le journal le trace).

## Chemins
`src/Archivage/`, `tests/php/Archivage/`, `tests/oracles/archivage/`, `tools/verifier-scellements.php`,
`tools/sceller.php`

## Demandes

- **À la vue d'ensemble (Kernel)** : `src/Kernel.php` liste en dur les secteurs dont il appelle le `Module`.
  `Archivage` n'y figure pas : mes routes ne sont donc pas enregistrées. Ajouter `'Archivage'` à la liste
  (une seule ligne), puis je poserai `src/Archivage/Module.php` avec ses permissions dans `DroitsRoutes`.
  En attendant, le scellement s'opère par le service (`Ged\Archivage\Scelleur`) et l'outil
  `tools/sceller.php` ; aucune route morte n'est déclarée.
- **À l'Interface** : bouton « Sceller » sur la fiche document (`data-testid="document-sceller"`) et un
  écran/liste « rétention » branché sur `Ged\Archivage\Retention::aEcheance()` et `::lever()` — le geste
  doit rester un acte humain tracé. Détails dans `docs/secteurs/interface.md`.
- **Au Socle** : la garde d'écriture est branchée (appels d'une ligne signalés) dans
  `DepotFichiers::mettreAJour()`, `DepotFichiers::miseALaCorbeille()` et `Indexeur::passer()`.
  À confirmer au prochain lot Socle ; détails dans `docs/secteurs/socle.md`.
- **Au Harness** : `TSA_URL` est absente de `.env.example`. La ajouter (vide, commentée « TSA RFC 3161
  réelle ; simulée en test ») pour que la recette sache quoi renseigner.
- **Aux Droits** : aucune permission nouvelle tant que le Kernel n'appelle pas `Archivage\Module`
  (`archivage:sceller`, `archivage:gerer`, `archivage:lire` à déclarer alors).


## Lot V2-09 — porte (F11)
1. Sceller un document du corpus → jeton TSA vérifiable (TSA simulée en test, réelle en recette via `TSA_URL`).
2. Toute mutation d'un document scellé refusée et nommée, par TOUTES les voies (service, écran, API, workflow, indexeur
   si le fichier change sur disque → alerte « scellé altéré », jamais nouvelle version silencieuse).
3. `tools/verifier-scellements.php` : 0 écart sur un fonds sain ; l'écart est trouvé si un octet change.
4. Rétention : échéances justes sur horloge figée ; aucune suppression.

## Reprendre de GEDv1 (lecture seule, audit `GEDv1/var/audit-v2/deepcode-stockage.md`)
`app/Services/Compliance/TsaService.php` (client RFC 3161 réel et testé, jamais câblé : le câbler),
`RetentionPolicyService` (table de durées), l'idée de `LegalArchiveService` — mais la garde au niveau du service.
