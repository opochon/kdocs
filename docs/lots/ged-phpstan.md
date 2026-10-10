# Lot ged-phpstan — réduire la baseline PHPStan niveau 6

Branche `ged/phpstan` (worktree `C:\ged\wt\ged-phpstan`), juge : Claude sur le poste principal.
Objectif : diviser la baseline `phpstan-baseline.neon` au moins par deux (idéalement zéro), sans
changer de comportement. PHPStan niveau 6 vert après chaque commit ; PHPUnit complet vert après
chaque module.

## Mesures de départ (2026-10-10, commit 16482d5)

- `phpstan-baseline.neon` : **167 entrées**.
  - niveaux 0 à 5 : 35 occurrences (32 entrées) — argument.type, arguments.count, arrayValues.list,
    classConstant.unused, function.alreadyNarrowedType, identical.alwaysFalse, match.alwaysTrue,
    method.unused, notIdentical.alwaysTrue, nullCoalesce.offset, nullsafe.neverNull, property.onlyWritten,
    return.type, ternary.alwaysTrue ;
  - niveau 6 : 135 entrées (127 `missingType.iterableValue` + 8 `missingType.generics`).
- `php vendor\bin\phpstan analyse --memory-limit=2G` : vert avec la baseline d'origine.
- `vendor\bin\phpunit` : OK, 461 tests, 2618 assertions, 1 ignoré.

## Note d'environnement

Le lanceur crée `vendor` et `node_modules` en **jonctions** vers le dépôt principal. Or l'autoloader
Composer calcule sa racine avec `dirname(__DIR__)` : par la jonction, `__DIR__` se résout dans
`C:\ged\GEDv2`, et PHPUnit chargeait donc `C:\ged\GEDv2\src` — les modifications du worktree
n'étaient pas testées. Correction locale (hors dépôt) : `vendor` a été **copié** dans le worktree
(jonction supprimée) pour que l'autoloader pointe sur `C:\ged\wt\ged-phpstan\src`. Aucun fichier du
dépôt n'est concerné.

## Fait

### Phase 1 — erreurs de niveaux 0 à 5 (à corriger en premier)

Défaut de comportement réel trouvé et corrigé (test d'abord, rouge → vert) :

- `Ui\ATraiter::types()` construisait `new Nomenclature($this->pdo)` alors que le constructeur attend
  un `Ged\Config`. Le `TypeError` était avalé par le repli, donc la nomenclature du fonds
  n'atteignait jamais l'écran « À traiter » (types figés sur la maquette). Corrigé en
  `new Nomenclature($this->config)`.
  Oracle : `tests/php/Ui/ATraiterNomenclatureTest.php` — rouge avant le correctif
  (`Failed asserting that an array contains 'Devis'`), vert après.

Autres causes corrigées (code mort, annotations trop strictes, garde-fous conservés) :

- `Archivage\HorlogeFigee`, `Workflows\HorlogeFigee` : `modify() ?: $this->instant` mort (PHP 8.3+
  lève `DateMalformedStringException`).
- `Archivage\Scellement::parcourir()` : appel `descendre($racine, true)` avec un argument inexistant.
- `Archivage\Scelleur` : `isset($meta['type']) && ... !== null` (redondant).
- `Classement\ClasseurGed` : `$emetteur !== null` toujours vrai sous `validationParHabitude` ;
  `?->montant` / `?->etiquettes` redondants à gauche de `??`.
- `Classement\InfomaniakClient` : constante `CHEMIN_V1` morte supprimée.
- `Ingestion\Cmd4Decoupeur` : `$etat['exitcode'] ?? $code` mort.
- `Ingestion\EditionPieces` : `?? 'regle'` / `?? 0` morts (contrat `Classeur`).
- `Ingestion\Pilote` : `$piece['pages'] ?? []`, `$piece['texte'] ?? ''` morts (forme garantie).
- `Jonctions\DepotApi::json()` : `@param array<string, mixed>` trop strict → `array<mixed>` (liste de
  dossiers légitime).
- `Recherche\Service` : propriété `$index` jamais lue supprimée (et passée par 5 constructions) ;
  `$morceaux === []` toujours faux.
- `Securite\RegistreSessions` : `array_values()` sur une `list` (neutre).
- `Socle\DepotFichiers` : `isset(...) && !== null` redondants.
- `Socle\EcranBibliotheque` : `arbreVivant()` annoté du mauvais type (le gabarit lit
  `chemin/nom/profondeur/compte`) ; propriété `$config` devenue inutile supprimée.
- `Socle\Registre::modifier()` : `is_array()` conservé, annotation du callable ramenée à `: mixed`.
- `Ui\ATraiter` : `$meta['type'] ?? ''` mort ; `piecesDuLot()` annoté `array<int, ...>` (clés = ids).
- `Ui\ExtraitsLisibles` : constante `SIGNES_FORTS` et méthode `bruitAvant()` mortes supprimées ;
  `retenir()` tolère une entrée mal formée (annotation `list<list<mixed>>`) ; `$trouvesMelanges[0] ?? []`
  mort (la clé 0 existe toujours).
- `Workflows\AdaptateurGraphe` : `isset && !== null` redondant ; `default` mort du second `match`.
- `Workflows\Conditions::satisfaites()` : `is_array()` conservé, annotation `list<mixed>`.

Mesure : baseline **167 → 134 entrées** après régénération (`--generate-baseline`), PHPStan niveau 6
vert, aucune erreur nouvelle hors baseline.

## En cours

- Niveau 6, module par module.

### Commit phase 1

`cea063d` — baseline 167 → 134 ; PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Socle (niveau 6)

Annotations de types de valeur sur 13 fichiers (`@param`/`@return` `array<...>`,
`list<...>`, `array{...}`), plus `@param App<Psr\Container\ContainerInterface>` sur
`Socle\Module::enregistrer`. Aucun changement de comportement.

Mesure : baseline **134 → 87 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Ui (niveau 6)

`ATraiter` (documentDepuisLigne, validerPropositions), `Document` (versions, historique —
les `@return` inline n'étaient pas lus), `Ecrans::document`, `ExtraitsLisibles` (jeton :
fragmentDeBord, courtNonUsuel, implausible, jetonDepuisMot), `Ui\Module::enregistrer`
(App générique). Aucun changement de comportement.

Mesure : baseline **87 → 76 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Ingestion (niveau 6)

`EditeurPdf` (appel, executer), `EditionPieces` (pages, appliquer, metaEditee, verifierPages,
instantane, finir — plusieurs `@return` inline non lus), `Imap\Boite` (interface),
`Imap\ConnexionDifferee`, `Imap\ImapBoite`, `Pilote` (decouper, origineCourriel,
pieceQuiProlonge), `Source` (interface), `SourceAdresse`, `Travaux` (ajouterPiece, plages).
Aucun changement de comportement ; un `array_values()` devenu neutre retiré.

Mesure : baseline **76 → 56 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Classement (niveau 6)

`ClasseurGed::dateFacture`, `Regles` (lireEmetteur, emetteurIaCredible, estDestinataire :
`list<string>`). Aucun changement de comportement.

Mesure : baseline **56 → 52 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Recherche (niveau 6)

`Service` (chercherPleinTexte, chercherLike, lister, executer) et `Termes::__construct` :
`list<array<string, mixed>>` / `list<string>`. Deux docblocks multi-tags sur une seule
ligne n'étaient pas lus : scindés. Aucun changement de comportement.

Mesure : baseline **52 → 47 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Workflows (niveau 6)

`AdaptateurGraphe` (entreeEtat, entreeRegle), `Catalogue::operateurs`, `Circuits` (creer,
mettreAJour, instance, instancesDocument, transition, etatInitial, aEtat, decoder,
decoderInstance), `Ecran` (catalogue, jsonSur, entreeJournal), `Moteur` (appliquerEtape,
substituer), `Regles` (creer, mettreAJour, decoder) et `Workflows\Module::enregistrer`
(App générique). Plusieurs docblocks multi-tags sur une seule ligne n'étaient pas lus.
Aucun changement de comportement.

Mesure : baseline **47 → 26 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Archivage (niveau 6)

`EcranArchives::donnees`, `Garde` (estScelle, journaliserAlerte), `Module` (enregistrer,
filtrerParDroits), `Retention` (durees, enregistrerDurees, reference, journaliser),
`Scellement` (ecrire, estActif, lire), `Scelleur::archiverJustificatif`. Aucun changement
de comportement.

Mesure : baseline **26 → 12 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

## Reste

- Niveau 6 par module (un commit par module) : Socle (47), Ui (12), Ingestion (20), Classement (4),
  Recherche (5), Workflows (21), Archivage (14), Securite (3), Jonctions (7) — plus `Apercu` (1) et
  `Kernel.php` (1) hors liste.
- Régénérer la baseline à la baisse après chaque module, PHPStan + PHPUnit verts.
- `powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` avant `ETAT: fini`.
