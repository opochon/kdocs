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

### Module Securite (niveau 6)

`DroitsDossiers::regleDuDossier` (`list<int>`), `Securite\Module::enregistrer` (App
générique), `RegistreSessions::ouvrir` (@return inline non lu). Aucun changement de
comportement.

Mesure : baseline **12 → 9 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

### Module Jonctions (niveau 6)

`AppairageApi::champs`, `Courriel::rendu`, `DepotApi::origine`, `Module::enregistrer`
(App générique), et le trait `MessagePhpMailer::message` (corrige d'un coup
`TransportFichier::message` et `TransportSmtp::message`). Aucun changement de comportement.

Mesure : baseline **9 → 2 entrées**. PHPStan niveau 6 vert ; PHPUnit 462 tests verts.

Reste alors `Apercu\Module::enregistrer` et `Kernel::creer` (hors liste des 9 modules).

### Apercu et Kernel (niveau 6, hors liste)

`Apercu\Module::enregistrer` (App générique). Puis `Kernel::creer` : **annotation de type
seule** (`@return App<Psr\Container\ContainerInterface>`), aucune ligne de logique touchée
— c'était le dernier type générique manquant pour atteindre zéro.

Mesure : baseline **2 → 0 entrée** (`ignoreErrors: []`). PHPStan niveau 6 vert.
PHPUnit : 462 tests, 2620 assertions, 1 ignoré.

## Harness (avant `ETAT: fini`)

`powershell -ExecutionPolicy Bypass -File tools\harness.ps1 -SansBanc` rejoué le
2026-10-10 sur le commit `c696dbe` (durée 541 s) :

| Couche | Verdict | Chiffres |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules présents, DB_NAME=gedv2_test |
| 1-unitaire | VERT | 462 tests, 2620 assertions, 1 ignoré |
| 3-banc | ROUGE | sautée (`-SansBanc`, attendu) |
| 4-ecrans | ROUGE | **1 failed / 64 passed (3.0 m)** — voir ci-dessous |
| 7-securite | VERT | sonde sécurité : aucun contournement accepté |
| 2-contrat | VERT | contrats de service et HTTP : toutes les routes couvertes |
| 6-charge | VERT | p95 lecture 695 ms, p95 écriture 239 ms, 0 erreur, 0 collision |
| 8-statique | VERT | **phpstan : 0 erreur hors baseline** ; composer audit : aucune vulnérabilité |
| 5-personas | ROUGE | « pas encore écrite » (couche non livrée, indépendante du lot) |

**Échec 4-ecrans (pré-existant, hors lot).** Le test `tests/e2e/droits-b6.spec.ts`
« B6 : trois comptes réels respectent les droits sur les dossiers A et B » attend que la
Bibliothèque de l'utilisateur ne montre pas `ligne-<document B>` ; elle l'affiche
(`toHaveCount(0)` reçu `1`). Le même test échoue **à l'identique sur le dépôt principal
`C:\ged\GEDv2` au commit `16482d5` (v2), sans une seule de mes modifications** : le défaut
de filtrage ACL de la Bibliothèque pré-existe au lot. Il n'est pas corrigé ici, car le lot
est une réduction de baseline « sans changer le comportement ». Aucun test n'a été
affaibli, aucune sonde retirée, aucune porte relevée.

Les deux couches que ce lot touche réellement — 8-statique (PHPStan) et 1-unitaire — sont
vertes.

## Porte qualité

1. **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` vert,
   niveau 6, `reportUnmatchedIgnoredErrors: true`. Baseline **167 → 0** entrée
   (`phpstan-baseline.neon` = `ignoreErrors: []`). Aucun `@phpstan-ignore` ajouté ;
   la baseline n'a jamais grossi (régénérée à la baisse après chaque module).
2. **Revue du diff** (grilles ECC php-reviewer / security-reviewer / silent-failure-hunter) :
   aucun `catch (Throwable)` muet ajouté ; aucun SQL par concaténation d'entrée ; tous les
   fichiers touchés portent `declare(strict_types=1)`. Les annotations ajoutées décrivent
   les types réels (listes, shapes, `array<string, mixed>`) ; les garde-fous existants
   (`is_array`, `isset`) ont été **conservés** en corrigeant l'annotation trop stricte qui
   les rendait « toujours vrais ».
3. **TDD** : l'unique défaut de comportement trouvé (`Ui\ATraiter` passait un `PDO` à
   `Nomenclature`, `TypeError` avalé → nomenclature jamais lue) a reçu son oracle
   `tests/php/Ui/ATraiterNomenclatureTest.php` : **rouge** avant correctif
   (`Failed asserting that an array contains 'Devis'`), **vert** après. Aucun test existant
   n'a été affaibli ; 4 constructions de `Recherche\Service` dans les tests ont suivi la
   suppression du paramètre `$index` (aucune assertion changée).

ETAT: fini
