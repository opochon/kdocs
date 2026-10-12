# Lot ged-parite-comportement-1 — rattraper les écarts de comportement Paperless-ngx

Mesure de parité d'effet du lot `ged-oracle-paperless-1` (fusionné, `bd0d761`) : correspondance 22/137,
dates 10/25. Ce lot implémente dans GEDv2 les comportements manquants, à la manière de GEDv2, jusqu'à
faire passer TOUS les vecteurs. Les vecteurs (`tests/parite/vecteurs/*.json`) et le juge ne sont pas
modifiés : seule la couche de rejeu (`tests/php/Parite/ComportementTest.php`) a été adaptée pour appeler
les nouveaux services, et elle affirme désormais chaque vecteur au lieu de seulement mesurer.

## Fait (commits)

- `213abb6` — **oracle rouge** : tests unitaires `CorrespondanceTest` (none/all/any/literal/regex/fuzzy
  + casse) et `DatesDansTexteTest` ; `ComportementTest` affirme chaque vecteur. Squelette seul :
  27 échecs sur 53. Aucun vecteur ni sortie attendue modifié.
- `c9bfd2a` — `Ged\Classement\Correspondance`, mode `MATCH_ALL` : chaque mot du motif (jeton ou groupe
  entre guillemets, espaces internes = `\s+`) est un mot entier du texte.
- `9c98485` — mode `MATCH_ANY` : le premier mot présent suffit.
- `b302cd2` — mode `MATCH_REGEX` : expression régulière avec drapeau de casse ; un motif invalide est
  une non-correspondance nommée (comme `safe_regex_search`), jamais une erreur.
- `9fe1fef` — mode `MATCH_LITERAL` : le motif entier échappé, entre deux limites de mot, espaces
  littéraux.
- `6b5a85a` — mode `MATCH_FUZZY` : similarité partielle (fenêtre de même longueur, plus longue
  sous-séquence commune normalisée, seuil 90/100), ponctuation ignorée.
- `aa09988` — `Regles::dateDansTexte` : première date reconnue et plausible dans TOUT le texte
  (numeric JJ.MM.AAAA, mois en lettres ou abrégés FR/DE/IT/EN, ordinaux anglais, mois puis jour,
  refus des nombres collés à 6 ou 8 chiffres). `dateEnTete` et ses oracles restent inchangés ; seuls
  des mois anglais ont été ajoutés à la table partagée `mois()`.
- `130cc89` — `docs/parite/correspondance.json` régénéré : les six algorithmes, la fonction `matches`
  et `_split_match` citent le service `Ged\Classement\Correspondance` et une preuve PHPUnit qui passe.

## Mesures exactes (dernier rejeu)

Phase 2 (`var/parite/comportement.json`, rejouée par la couche 1) :

| Catégorie Paperless | Passes | Total | Taux |
| --- | ---: | ---: | ---: |
| dates (test_date_parsing.py) | 25 | 25 | 100,00 % |
| correspondance (test_matchables.py) | 137 | 137 | 100,00 % |

Détail correspondance : MATCH_NONE 2/2, MATCH_ALL 40/40, MATCH_ANY 42/42, MATCH_LITERAL 29/29,
MATCH_REGEX 19/19, MATCH_FUZZY 5/5. **Aucun vecteur en échec** : il n'y a donc ni raison ni référence
Paperless à documenter pour un écart.

Inventaire (couche 9, `recette/parite-paperless.json`) : 780 éléments, **187 couverts** (180 avant),
parité stricte **23,97 %**, hors exclusions **25,55 %**. Les 7 éléments gagnés sont les six
algorithmes de correspondance (4 passent d'`absent`, 2 changent de preuve) plus `matches` et
`_split_match`. Sans surclassement : les quatre fonctions d'orchestration
`match_correspondents` / `match_document_types` / `match_tags` / `match_storage_paths` restent
`absent` (elles filtrent un queryset et prédisent `MATCH_AUTO`, ce que GEDv2 ne fait pas par ce
modèle).

PHPUnit complet : **670 tests, 9 830 assertions, 1 sauté** (préexistant), 0 échec.
PHPStan niveau 6 : **0 erreur** hors baseline (baseline vide, inchangée).

## Choix documentés

- **Sensibilité à la casse.** Paperless porte `is_insensitive` dans le modèle ; l'extraction des
  vecteurs ne conserve que `{algorithme, match_texte, doc_texte}`. Rejouer en **sensible** est le seul
  choix cohérent avec les 137 vecteurs : aucun couple (algorithme, motif, texte) n'a deux sorties
  contradictoires, et les vecteurs de la classe insensible de Paperless ne font jamais varier la casse,
  donc ils passent aussi en sensible. Le service expose `$insensible` ; le rejeu passe `false`.
- **Flou.** L'algorithme exact de rapidfuzz `fuzz.partial_ratio` n'est pas reproduit à la virgule.
  GEDv2 retient la meilleure fenêtre de même longueur et normalise la plus longue sous-séquence
  commune (distance Indel) ; sur les 5 vecteurs, scores 93,75 / 93,75 / 93,75 / 100 (vrais) contre
  87,5 (faux) — mêmes verdicts que rapidfuzz (93,75 / 93,75 / 96,77 / 100 contre 87,5) au seuil 90.
  C'est une approximation assumée et bornée aux verdicts.
- **Dates.** Paperless s'appuie sur `dateparser` ; GEDv2 implémente les formes couvertes par les
  vecteurs et refuse les nombres collés. La borne de plausibilité reste celle de GEDv2
  (`datePlausible` : 1990 → aujourd'hui + 31 j), plus stricte que celle de Paperless (> 1900,
  ≤ maintenant) ; aucun vecteur n'exerce la différence.
- **Réutilisation dans le classement.** Le service de correspondance est l'équivalent Paperless ; le
  classeur GEDv2 (`lireEmetteur`, `typeEtPreuve`, `nomSeLit`, `emetteursCorrespondent`) garde ses
  lecteurs métier, qui ne sont pas le modèle de correspondance de Paperless. Les remplacer ferait
  régresser les oracles de classement (`courrier-100p`, `classement-bruite`) : le service est exposé
  pour un futur moteur de règles par motif (étiquettes automatiques), pas branché de force aujourd'hui.

## Demandes / points pour le juge

- **Juge (hors périmètre de l'agent).** `tools/parite/juge.php` écrit encore, en phase 2, la phrase
  « Un écart de comportement est mesuré, jamais corrigé dans ce lot », devenue fausse (137/137 et
  25/25). L'agent n'a pas modifié le juge ; à corriger côté juge.
- **Flou (limite connue).** Le mode `MATCH_REGEX` n'a pas de délai propre ; PHP borne une recherche par
  `pcre.backtrack_limit` (dépassement = non-correspondance), et le motif vient d'une règle
  d'administrateur, pas d'une entrée de bout en bout. Un délai par recherche (comme le fait Paperless)
  demanderait l'extension PCRE dédiée.

## Harness (`powershell -File tools\harness.ps1 -SansBanc`)

Second rejeu, `tools/composer.phar` restauré (outil gitignoré absent de ce worktree, même constat que
le lot ged-oracle-paperless-1) :

| Couche | État | Détail |
| --- | --- | --- |
| 0-garde-fous | VERT | vendor + node_modules, `DB_NAME=gedv2_test` imposé |
| 1-unitaire | VERT | 670 tests, 9 830 assertions, 1 sauté |
| 3-banc | ROUGE | sautée par `-SansBanc` (attendu) |
| 4-écrans | VERT | 80 passés (3,6 m) |
| 7-sécurité | VERT | aucun contournement accepté |
| 2-contrat | VERT | toutes les routes déclarées couvertes |
| 6-charge | VERT | p95 lecture 671 ms, p95 écriture 220 ms, 0 erreur, ingestion finie |
| 8-statique | VERT | PHPStan niveau 6 : 0 erreur ; `composer audit` : aucune vulnérabilité |
| 5-personas | VERT | 6 personas, tous les gestes trouvés (médianes 343–686 ms) |
| 9-parité | VERT | 780 éléments, 187 couverts, strict 23,97 %, hors exclusions 25,55 %, 0 non prouvé |

La couche 3 étant sautée par `-SansBanc`, le banc de classement a été rejoué à part, car la table
`mois()` est partagée avec `dateEnTete` : `classement-bruite --ia-simule` 65/65, 54 validés seuls
(83,1 %), 0 validé faux, 0 type faux, 0 émetteur faux, 0 date fausse, porte FRANCHIE ;
`classement-regression --ia-simule` 20/20, 17 validés seuls (85,0 %), 0 validé faux, porte FRANCHIE.
Aucune régression de classement.

## Porte qualité

- **Analyse statique** : `php vendor\bin\phpstan analyse --memory-limit=2G` → 0 erreur, niveau 6,
  baseline vide inchangée.
- **TDD** : oracle écrit et lancé rouge avant le code (commit `213abb6`, 27 échecs / 53) ; chaque
  famille rebranchée ensuite. Aucun test affaibli : `ComportementTest` passe de la mesure à
  l'affirmation par vecteur, et les vecteurs ne sont pas touchés.
- **Revue du diff** (grilles php-reviewer / security-reviewer / silent-failure-hunter) :
  `declare(strict_types=1)` partout, classes `final`, méthodes courtes et typées, aucun SQL, aucun
  secret, aucun `shell_exec`. Le seul `@` est `@preg_match` du mode regex : l'échec est testé
  explicitement (`$resultat === 1`), motivé par le commentaire, et reproduit le comportement de
  `safe_regex_search` (motif invalide = non-correspondance). Pas de `catch` silencieux introduit.
- **Réserve** : `flou()` est en O(fenêtres × n²) ; pour des motifs très longs et des textes très
  longs, le coût croît. Ce mode n'est appelé par aucune route aujourd'hui ; à surveiller si un moteur
  de règles l'expose.

ETAT: fini
