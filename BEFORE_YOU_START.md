# K-DOCS — BEFORE YOU START

> **⚠️ LIS CE FICHIER EN ENTIER AVANT TOUTE ACTION**
>
> **🤖 Agent IA ?** Exécute d'abord [AGENT_BOOTSTRAP.md](AGENT_BOOTSTRAP.md)

---

## 📍 ÉTAT ACTUEL

**Maturité : 95%** — Production-ready

> **🔒 Multi-instances ?** Consulter [COORDINATION.md](COORDINATION.md) AVANT de commencer

### En cours
- [x] POC validé et intégré
- [x] Tests automatisés (smoke, api, integration, visual)
- [x] Anti-régression (pre-commit)
- [x] Error tracking, Migrations, Backups, Rate limiting
- [ ] Visual regression pixel-perfect (basique OK, Puppeteer optionnel)

### Prochaine session

> **→ Lire [SESSION_STATE.md](docs/pilotage/SESSION_STATE.md) pour le contexte complet**

```
Reprendre sur: voir SESSION_STATE.md (mémoire inter-sessions)
Branche: main
État git: À vérifier avec clean.bat
```

---

## 🚨 RÈGLES ABSOLUES (ne jamais violer)

1. **JAMAIS** déplacer/supprimer un fichier utilisateur sans demande EXPLICITE
2. **JAMAIS** de credentials dans le code
3. **TOUJOURS** `test.bat check` avant commit
4. **TOUJOURS** mode local fonctionnel (sans internet)

> Détail complet → [docs/REGLES_IMMUABLES.md](docs/REGLES_IMMUABLES.md)

---

## ✅ PREMIÈRE ACTION

```cmd
cd C:\wamp64\www\kdocs
```

1. **Lire** `docs/pilotage/SESSION_STATE.md` (ou `get_session_state` via MCP)
2. **Lire** `docs/pilotage/PILOTAGE.md` (ou `get_pilotage` via MCP)
3. **Lancer** `clean.bat` — si échec → réparer AVANT de coder

### Pendant la session
**Logger chaque action** via `log_action` MCP (qui, quoi, où, pourquoi)

### En fin de session
**Mettre à jour** `docs/pilotage/SESSION_STATE.md` (ou `update_session_state` via MCP)

---

## 🧪 COMMANDES ESSENTIELLES

| Commande | Usage |
|----------|-------|
| `clean.bat` | État complet (git + config + tests) |
| `test.bat check` | **OBLIGATOIRE** avant commit |
| `test.bat visual` | Après modif UI (non-bloquant) |
| `kdocs.bat errors` | Voir erreurs récentes |
| `kdocs.bat backup` | Créer backup DB |

---

## 📁 STRUCTURE CRITIQUE

```
kdocs/
├── app/
│   ├── Controllers/        # HTTP uniquement
│   ├── Services/           # Logique métier
│   └── Core/               # Config, DB, Validator, Migrations, ErrorTracker
├── config/config.php       # Configuration
├── storage/                # NE PAS TOUCHER directement
├── tests/                  # Tests automatisés
└── docs/                   # Documentation
```

---

## 📖 DOCUMENTATION (si besoin de détails)

| Fichier | Quand le lire |
|---------|---------------|
| [docs/REGLES_IMMUABLES.md](docs/REGLES_IMMUABLES.md) | Avant de modifier une règle |
| [docs/PROCESS_DEV.md](docs/PROCESS_DEV.md) | Pour le workflow git/commit |
| [docs/pilotage/PILOTAGE.md](docs/pilotage/PILOTAGE.md) | Pour l'architecture détaillée |
| [docs/pilotage/SESSION_STATE.md](docs/pilotage/SESSION_STATE.md) | **Mémoire inter-sessions — LIRE EN PREMIER** |
| [CHANGELOG.md](CHANGELOG.md) | Pour l'historique |

---

## 🔀 WORKFLOW

```
1. clean.bat              → Vérifier état
2. git checkout -b xxx    → Créer branche si nouvelle feature
3. [coder]
4. test.bat check         → DOIT passer
5. git commit -m "type(scope): msg"
6. test.bat visual        → Si modif UI (warning OK)
```

---

## 🆘 SI PROBLÈME

```cmd
clean.bat                 # Diagnostic complet
kdocs.bat errors          # Erreurs récentes
kdocs.bat backup:list     # Backups disponibles
kdocs.bat backup:restore X # Restaurer si nécessaire
```

---

## ❓ SI "FINALISE CECI" SANS CONTEXTE

1. Lire la section "En cours" ci-dessus
2. `git status` → fichiers modifiés = travail inachevé
3. `test.bat check` → ce qui échoue = à corriger
4. Demander clarification si ambiguïté

---

*Version: 4.0 | Mise à jour: 2026-02-04*
