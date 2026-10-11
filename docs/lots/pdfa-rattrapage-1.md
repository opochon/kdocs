# Lot pdfa-rattrapage-1 — relancer une copie PDF/A en échec, sans perdre le document

Agent : DeepCode · Juge : Claude (poste principal) · Branche : `ged/pdfa-rattrapage-1`

## Cause (constat du juge ged-pdfa-1)

`CopieArchivage::pour()` rendait l'échec déjà persisté tant que l'empreinte d'origine ne changeait pas. Un
document dont la copie PDF/A avait échoué pour une **cause d'environnement** (Ghostscript absent au moment de
l'ingestion, profil ICC ou `PDFA_def.ps` introuvable, publication refusée…) restait donc **en échec pour
toujours** : installer Ghostscript après coup, ou simplement relancer, ne changeait rien. Seule une
modification du fichier débloquait la situation — contraire au besoin « réparer sans toucher à la pièce qui
fait foi ».

## Doc officielle lue (Ghostscript 10.02.1 installé)

- `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 14.7 « Creating a PDF/A document », p. 315-316**
  (pages physiques 319-320) : options `-dPDFA=2`, `-sColorConversionStrategy=RGB`, fichier de définition
  PDF/A portant l'`ICCProfile` de l'OutputIntent, et `PDFACompatibilityPolicy` (0 = sortie non conforme
  silencieuse, **1 = l'opération non conforme est ignorée, la sortie reste conforme** — choix du lot
  ged-pdfa-1, 2 = abandon). Le binaire seul ne distingue donc pas « document en cause » et « outil absent ».
- `C:\Program Files\gs\gs10.02.1\doc\Ghostscript.pdf`, **§ 8.4 « Return codes », p. 155** : les fonctions
  `gsapi_*` rendent un entier — `0` = aucune erreur, `gs_error_Quit` (un `quit` n'est PAS une erreur),
  `< 0` = erreur, `≤ gs_error_Fatal` = erreur fatale. Le processus `gswin64c` suit ces codes, mais ils ne
  disent rien de la **nature** de l'échec (outil vs document). D'où un classement explicite à partir du
  diagnostic lu, pas du seul code de sortie (déjà l'esprit de `ConversionPdfA::executer()`).
- `docs/secteurs/ingestion.md`, journal « ged-pdfa-1 — copie d'archivage PDF/A-2b » : convention `.pdfa/`,
  état `pdfa` dans le `.meta.json` (ou flanc `.pdfa/<fichier>.pdfa.json` pour un scellé), échec
  `statut: echec` + `raison`, aucune copie partielle, `RattrapagePdfA` reprenable et écarté.

## Classement des échecs (tenu par test)

| Cause d'échec | Nature | Relancée automatiquement par le rattrapage ? |
| --- | --- | --- |
| Ghostscript absent | environnement | **oui** (`reprise: auto`) |
| Profil ICC `srgb.icc` introuvable | environnement | **oui** |
| `PDFA_def.ps` introuvable | environnement | **oui** |
| Ghostscript n'a pas pu démarrer | environnement | **oui** |
| Dossier de destination non créé | environnement | **oui** |
| Publication du fichier refusée | environnement | **oui** |
| Aucune sortie exploitable | environnement | **oui** |
| Fichier source introuvable | environnement | **oui** |
| PDF chiffré (mot de passe requis) | document | **non** (`reprise: jamais`) |
| PDF corrompu / illisible (`Couldn't initialise file`, `Unrecoverable error`) | document | **non** |
| Format non pris en charge | document | **non** |
| Autre refus nommé de Ghostscript | document | **non** (défaut prudent) |

## Correction (commits)

- `c2a30d5` — fiche du lot ouverte, citations Ghostscript et classement écrits avant le code.
- `8fd881c` — **oracle rouge** `tests/php/Ingestion/RelancePdfATest.php` (3 tests vus rouges : `reprise`
  absente, `reprises` absente).
- `775a65d` — mécanisme : `ConversionPdfA` nomme le PDF corrompu (`MSG_CORROMPU`) et classe chaque raison
  (`reprise()` → `auto` / `jamais`) ; `CopieArchivage::pour(..., bool $forcer = false)` ne rejoue rien sans
  relance explicite, persiste `reprise`, et un ancien échec sans champ est classé à la lecture ;
  `RattrapagePdfA` relance D'ELLE-MÊME les seuls échecs `auto`, laisse les définitifs, compte `reprises`
  et accepte une conversion injectée (couture de test).
- `8926415` — écrans : fiche document `pdfa-reprenable` / `pdfa-definitif`, écran Application
  `pdfa-reprises` ; 2 tests d'écran.
- `9a87133` — **revue ECC** : `array_merge` au lieu de l'opérateur `+` (qui ne remplace pas les clés) pour
  les états `absent` / `non_applicable` / `copie altérée` ; test de non-régression de l'état neutre.
- `ffd2eeb` — `reprises` ne compte que les copies **effectivement** retrouvées, jamais une tentative.

## Comportement final

- **Relance explicite** : `pour(..., forcer: true)` refait la conversion même après un échec persisté.
- **Sans relance** : un échec déjà persisté est rendu tel quel (aucune reprise silencieuse).
- **Rattrapage automatique** : il ne force que les causes d'environnement (`reprise: auto`, via
  `aReprendre()`). Un échec définitif (chiffré, corrompu) n'est **jamais** rejoué automatiquement.
- **Anciens échecs** : un bloc sans champ `reprise` est classé à la lecture depuis sa `raison`, donc les
  documents déjà bloqués avant ce lot redeviennent réparables.
- **Original intact** : toucher à la copie ne modifie jamais l'original (empreinte vérifiée par test).

## Mesures et preuves

- Suite PHPUnit **complète** : `php vendor/bin/phpunit --no-coverage` → **606 tests, 3 471 assertions,
  1 sauté, OK** (5 min 32 s).
- Ciblé PDF/A : `RelancePdfATest` (3) + `CopieArchivageTest` (12) + `PdfaEcranTest` (5) →
  **20 tests, 147 assertions**, vert.
- PHPStan niveau 6 : `php vendor/bin/phpstan analyse --memory-limit=2G` → **0 erreur** ;
  `phpstan-baseline.neon` **vide** (jamais grossi).
- `composer audit` : non rejoué dans ce worktree (`composer` / `tools/composer.phar` absent) — couche 8 du
  harness à rejouer par le juge sur le poste principal, sans lien avec ce diff.
- Ghostscript : 10.02.1 (`C:\Program Files\gs\gs10.02.1\bin\gswin64c.exe`), conversion réelle des oracles.

## Commandes

```
php vendor/bin/phpunit tests/php/Ingestion/RelancePdfATest.php --no-coverage
php vendor/bin/phpunit tests/php/Ingestion tests/php/Ui/PdfaEcranTest.php --no-coverage
php vendor/bin/phpunit --no-coverage
php vendor/bin/phpstan analyse --memory-limit=2G
php tools/tache.php pdfa [--lot=25] [--tours=N]
```

## Porte qualité (ECC)

- **Analyse statique** : PHPStan niveau 6, 0 erreur, baseline vide, aucun `@phpstan-ignore` ajouté.
- **TDD** : oracle `RelancePdfATest` écrit et vu rouge (`assertSame('auto', null)` sur `reprise`,
  `assertSame(1, null)` sur `reprises`) avant toute correction ; aucune assertion affaiblie.
- **Revue du diff** (`php-reviewer`, `security-reviewer`, `silent-failure-hunter`) :
  - *PHP* : `declare(strict_types=1)`, classes `final`, propriétés `readonly`, types explicites, méthodes
    courtes ; `ConversionPdfA::reprise()` est un classement pur, sans effet de bord.
  - *Sécurité* : aucun écran ni route nouveau ; aucun SQL construit par concaténation (l'existant
    `idDocument()` reste paramétré) ; le périmètre vient du fonds et le chemin est joint à `STORAGE_ROOT`
    comme avant ; la commande Ghostscript garde ses arguments fixes ; aucune donnée d'entrée relue dans le
    classement.
  - *Silent-failure* : aucun échec muet — la `raison` est persistée, `reprise` la qualifie, la relance du
    rattrapage est comptée seulement quand l'échec devient une copie juste ; le `catch (\Throwable)` de la
    tranche trace déjà par `error_log` et n'avale rien (document laissé en place).
  - **Constat corrigé** : l'opérateur `+` de tableau laissait `etat()` rendre `en_attente` / raison vide pour
    un document absent ou non applicable — remplacé par `array_merge`, avec un test de non-régression.
  - **Limite motivée** : un refus Ghostscript non listé est classé `jamais` (défaut prudent : on ne boucle
    pas sur un document cassé). Étendre la liste = ajouter une constante nommée dans `ConversionPdfA` et
    son test ; le champ `raison` rend toujours l'échec lisible.

ETAT: fini
