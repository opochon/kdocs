# K-Docs — Claude Context

## 🚀 AU DÉMARRAGE (OBLIGATOIRE)

**EXÉCUTER LA PROCÉDURE :** [AGENT_BOOTSTRAP.md](../AGENT_BOOTSTRAP.md)

```
1. M'identifier (Claude-1, Claude-2, etc.)
2. Lire COORDINATION.md → verrous, co-workers
3. M'enregistrer dans le journal
4. Lire BEFORE_YOU_START.md → état, règles
5. Lancer clean.bat → vérifier état
6. Verrouiller mes fichiers si modification
```

---

## ⚠️ RÈGLES CRITIQUES

- JAMAIS modifier un fichier verrouillé par un autre agent
- JAMAIS déplacer/supprimer fichiers utilisateur sans demande
- JAMAIS credentials dans le code
- TOUJOURS `test.bat check` avant commit
- TOUJOURS retirer mes verrous en fin de session

---

## 📁 FICHIERS CLÉS

| Fichier | Quand |
|---------|-------|
| `AGENT_BOOTSTRAP.md` | **EN PREMIER** - Procédure démarrage |
| `COORDINATION.md` | Verrous, tâches, co-workers |
| `BEFORE_YOU_START.md` | État projet, workflow |
| `docs/REGLES_IMMUABLES.md` | Règles permanentes |

---

## 🔒 SI MULTI-INSTANCES

Vérifier COORDINATION.md :
- Verrous actifs → ne pas toucher ces fichiers
- Tâches en cours → ne pas refaire
- Messages → lire et répondre
