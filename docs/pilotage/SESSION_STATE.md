# ClearMyDocs — SESSION STATE

## DERNIERE SESSION

| Champ | Valeur |
|-------|--------|
| Date | 2026-03-01 |
| Agent | Claude.ai (Opus) |
| Sujet | Audit etat projet + verrouillage modele 14b→7b |

## ETAT ACTUEL

### Modele local — VERROUILLE 7b-instruct
Toutes les references au 14b ont ete eliminees du code :
- `agent_config.py` : commentaires dataclass → 7b-instruct, fallback installed[0] → RuntimeError
- `corpus_profiler.py` : docstring "14b ou superieur" → "modele configure"
- `document_profiler.py` : docstring nettoyee
- `providers.py` : defaults deja corrects (7b-instruct)
- `CLAUDE.md` : regle MODELE LOCAL VERROUILLE ajoutee, references 14b supprimees
- `config.json` (AppData) : VERIFIER que local_planner_model et local_reader_model = qwen2.5:7b-instruct

### Code source — FIX APPLIQUES DANS CORE
Les 5 fix identifies lors des sessions precedentes sont dans le vrai code :
- `extractive_summary.py` : regex date (19|20)xx, titre section plancher score=1.0, penalite adresse *0.4
- `utils.py` : `<==` retire AVANT strip tags, regex HTML restrictif `</?[a-zA-Z]...`
- `ner_extractor.py` : normalisation unicode NFD pour mois accentues

### Bench Rules — RENFORCE
`BENCH_RULES.md` et `CLAUDE.md` a jour avec regles zero classement hardcode + modele verrouille

### Comparaison Cowork vs ClearMyDocs
Cowork (abstractif LLM) produit un resume juridique excellent sur 3 docs.
ClearMyDocs (extractif profile) ne rivalise pas sur la lisibilite d'un resume individuel.
Avantage ClearMyDocs : indexation, navigation, tracabilite, volume (50+ docs), cout zero LLM.

## CE QUI RESTE A FAIRE

- [ ] **VERIFIER config.json AppData** : s'assurer que local_planner_model = qwen2.5:7b-instruct
- [ ] Lancer l'indexation du dossier test unitaire (via UI ou bench_human.py --destroy-db)
- [ ] Verifier que les fix produisent les bons resultats dans le vrai pipeline
- [ ] bench_extraction.py sur le dossier test pour valider end-to-end
- [ ] Relancer bench_quality.py sur bench_staub pour mesurer le delta

## FICHIERS CLES

```
CLAUDE.md                                Directives agent (regle modele verrouille + zero classement)
Bench/BENCH_RULES.md                     Regles bench
Sources/app/core/agent_config.py         Routage modele (fallback = erreur, pas substitution)
Sources/app/core/extractive_summary.py   3 fix scoring
Sources/app/core/utils.py               2 fix clean
Sources/app/core/ner_extractor.py        1 fix dates
Sources/app/providers.py                 Defaults modele (7b-instruct)
```
