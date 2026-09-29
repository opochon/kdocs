# Secteur INGESTION — dossier, adresse, agent, découpe, lecture

Agent : Claude Code · Juge : GLM · Lot en cours : V2-02

## Mission
Deux entrées, légères : **un dossier d'ingestion** et **une adresse d'ingestion** (boîte IMAP relevée, pièces jointes
vers la même chaîne). ClearMyMails reste le pont lourd (Gmail, Microsoft 365…) — secteur Jonctions.
Chaîne : dépôt → découpe (`cmd4_ingest`, moteur partagé avec CMD v4, dépôt ClearMyDocs) → lecture → secteur Classement →
secteur Socle (rangement). Le dossier d'ingestion n'est jamais montré aux utilisateurs : seulement le bilan.

## Chemins
`src/Ingestion/`, `agent/` (agent d'ingestion Python, appel sortant), `tests/php/Ingestion/`, `tests/corpus/`, `tools/banc/`

## Lot V2-02 — porte
1. Lot `courrier-100p` (repris de GEDv1 avec sa vérité) : 53/53 exacts, 0 page perdue, 0 doublon.
2. Chaque nouveau lot du corpus (photos, 90°/180°, versos blancs, QR multipages, DE/IT/EN, pages mélangées, bureautique,
   300 pages, PDF protégé) a sa `verite.json` et passe la même porte.
3. Adresse d'ingestion : un courriel de test avec 3 pièces jointes → 3 documents, 0 perdu, 0 doublon au second relevé.
4. Tout en jobs courts avec point de reprise ; une tâche tuée reprend sans rien perdre.

## Reprendre de GEDv1
`app/Services/Ingest/Cmd4IngestClient.php` (stderr en fichier : l'interblocage du 27-09), `PDFSplitterService::compterPagesNatif`,
`tools/banc/rejouer-ingestion.php` et `mesurer-decoupe.py` (mesure contre vérité, pages blanches neutres),
`tests/corpus-banc/courrier-100p/`, la reprise des jobs abandonnés (`TaskService::processClassificationQueue`).

## Journal V2-02
- Fait : dossier surveillé (fichier stable sur deux relevés, déplacement atomique), travaux en base avec verrous courts
  reprenables et 3 essais, découpe par `cmd4_ingest`, rangement via `Contrats\Depot` + `Contrats\Classeur`.
- Fait : adresse d'ingestion. Client IMAP PHP pur (`Imap\ImapBoite` : l'extension imap n'existe plus en PHP 8.4),
  connexion différée, pièces utiles seulement (ni logos de signature, ni vCard), un message traité une fois
  (`ingestion_messages`), expéditeurs autorisés optionnels, message lu puis rangé dans `GED-traites` / `GED-refuses`
  (MOVE, ou COPY si le serveur n'a pas MOVE) — jamais EXPUNGE ni `\Deleted`.
- Fait : `php tools/tache.php ingestion [--budget=20] [--tours=N]` (verrou fichier, une ligne JSON par tour).
  Une porte en panne devient une alerte, les autres continuent ; sans dépôt (Socle), les pièces prêtes attendent.
- Preuves : `tests/php/Ingestion` 13 tests (dont client IMAP réel contre `tests/outils/faux-imap.php`).
- Reste : porte 1-2 (corpus `courrier-100p` + vérité, mesure 53/53 via `Cmd4Decoupeur`), branchement du dépôt Socle.

## Demandes
- Au Socle : `new Ged\Socle\DepotFichiers(Config $config)` — c'est ainsi que `tools/tache.php` le construit.
- À l'Ingestion (V2-08a) : ajouter `Ged\Jonctions\SourceApi` à `Ged\Ingestion\Fabrique::sources()` — `new SourceApi(Fabrique::zoneTravail($config) . '/api', new Travaux($pdo), $pdo)` — pour que les fichiers déposés par l'API et non encore enregistrés (reprise après panne) entrent dans la chaîne ; aucun autre changement demandé.
- 29-09 : demande Jonctions faite — `Ged\Jonctions\SourceApi` ajoutée à `Fabrique::sources()` (reprise des dépôts API non enregistrés).

## Demandes du HARNESS (lot H-6 « 6-charge », 29-09-2026)

Mesure : `php tools/tache.php ingestion --budget=300 --tours=200` sur le lot `courrier-100p`, pendant une charge
de 10 000 documents (`tools/banc/charge.php`). Preuves : `recette/preuves/charge/charge.{json,md}`.

- **Une invocation unique n'ingère pas un fichier fraîchement déposé** : `SourceDossier::relever()` exige DEUX
  relevés (fichier stable) avant de déplacer/prendre un fichier, alors que `tools/tache.php` s'arrête au premier
  tour sans travail (`recus + decoupes + ranges === 0`). Résultat : lancée une fois juste après un dépôt, la
  tâche sort sans rien faire (c'est ce qui s'est produit au premier rejeu de la couche 6) ; il faut un second
  appel. Le banc amorce donc l'état du dossier (`etat-dossier.json`) pour qu'une seule invocation fasse le
  travail. Attendu : un tour de « chauffe » interne, ou une stabilité constatée dans la même invocation.
- **Le lot parent ne quitte jamais l'état `decoupe`** : une fois les pièces produites et rangées, la ligne du lot
  (`ingestion_travaux.parent_id = 0`) reste à `decoupe` (le pilote n'avance que les pièces). Comme
  `Travaux::bilan()` et `Ged\Ui\ATraiter::enCours()` comptent les états `recu|decoupe|pret`, un lot **fini**
  reste compté « en cours de lecture » indéfiniment. Attendu : passer le lot à `range`/`fini` quand toutes ses
  pièces sont rangées.

Preuve positive du reste de la chaîne : avec l'amorçage, la tâche finit en 137 s, **53/53 pièces rangées**,
0 travail restant, 0 erreur, 53 documents déposés par le Socle — soit exactement le lot attendu.
- 29-09 : demandes du banc de charge H-6 faites — `tache.php` s'arrête après deux tours à vide (le dossier ne prend un fichier qu'au second relevé) ; un lot dont toutes les pièces sont rangées passe en « range » (`Travaux::clore`) et n'est plus compté « en cours de lecture ».
