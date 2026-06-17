# K-DOCS — AGENT BOOTSTRAP

> **CHAQUE AGENT (Claude, Cursor, autre) DOIT EXÉCUTER CETTE PROCÉDURE AU DÉMARRAGE**

---

## 🚀 PROCÉDURE DE DÉMARRAGE (obligatoire)

### Étape 1 : S'identifier et logger
```cmd
php agent_lock.php log [MON-ID] "Démarrage session - [MA TÂCHE]"
```
Exemple:
```cmd
php agent_lock.php log Agent-1 "Démarrage session - Fix compteur documents"
```

### Étape 2 : Vérifier les verrous actifs
```cmd
php agent_lock.php status
```
→ Voir qui travaille sur quoi

### Étape 3 : Verrouiller MES fichiers AVANT de les modifier
```cmd
php agent_lock.php check [FICHIER]
php agent_lock.php lock [FICHIER] [MON-ID] "[TÂCHE]"
```
Exemple:
```cmd
php agent_lock.php check app/Services/IndexingService.php
php agent_lock.php lock app/Services/IndexingService.php Agent-1 "Fix compteur"
```

**SI LE FICHIER EST DÉJÀ VERROUILLÉ → NE PAS LE MODIFIER**

### Étape 4 : Lire les règles
- `BEFORE_YOU_START.md` → État actuel, workflow
- `docs/REGLES_IMMUABLES.md` → Règles à ne JAMAIS violer

### Étape 5 : Vérifier l'état du projet
```cmd
clean.bat
```
Si erreurs → les corriger AVANT de commencer autre chose.

---

## 📝 ÊTRE VERBOSE (obligatoire)

**Logger CHAQUE action importante :**

```cmd
php agent_lock.php log [MON-ID] "ACTION: description"
```

Exemples:
```cmd
php agent_lock.php log Agent-1 "Lecture IndexingService.php"
php agent_lock.php log Agent-1 "Bug identifié: requête compte fichiers pas documents DB"
php agent_lock.php log Agent-1 "Modification ligne 145: ajout WHERE indexed=1"
php agent_lock.php log Agent-1 "Test OK - compteur affiche maintenant 36"
```

**Format recommandé:**
- `LECTURE: fichier` → Je lis un fichier
- `ANALYSE: description` → J'analyse quelque chose
- `BUG: description` → J'ai trouvé un problème
- `FIX: description` → J'applique une correction
- `TEST: résultat` → Je teste
- `COMMIT: message` → Je commite
- `BLOQUÉ: raison` → Je suis bloqué

---

## 🔒 AVANT DE MODIFIER UN FICHIER

```cmd
REM 1. Vérifier si disponible
php agent_lock.php check [FICHIER]

REM 2. Si disponible, verrouiller
php agent_lock.php lock [FICHIER] [MON-ID] "[TÂCHE]"

REM 3. Logger
php agent_lock.php log [MON-ID] "LOCK: [FICHIER]"

REM 4. Modifier le fichier

REM 5. Logger la modification
php agent_lock.php log [MON-ID] "FIX: [description]"
```

---

## 🛑 AVANT DE TERMINER LA SESSION

```cmd
REM 1. Tester
test.bat check

REM 2. Logger le résultat
php agent_lock.php log [MON-ID] "TEST: check passed/failed"

REM 3. Commit si OK
git add .
git commit -m "type(scope): message"
php agent_lock.php log [MON-ID] "COMMIT: type(scope): message"

REM 4. Déverrouiller MES fichiers
php agent_lock.php unlock [FICHIER1] [MON-ID]
php agent_lock.php unlock [FICHIER2] [MON-ID]

REM 5. Logger fin de session
php agent_lock.php log [MON-ID] "Fin session - [RÉSUMÉ]"
```

---

## 🤝 SI UN AUTRE AGENT A VERROUILLÉ UN FICHIER DONT J'AI BESOIN

1. **NE PAS modifier le fichier**
2. Logger: `php agent_lock.php log [MON-ID] "BLOQUÉ: attente [FICHIER] verrouillé par [AUTRE]"`
3. Travailler sur autre chose ou attendre
4. Vérifier régulièrement: `php agent_lock.php status`

---

## 📋 COMMANDES LOCK

| Commande | Usage |
|----------|-------|
| `lock.bat status` | Voir tous les verrous |
| `lock.bat check <file>` | Vérifier si fichier dispo |
| `lock.bat lock <file> <agent> <task>` | Verrouiller |
| `lock.bat unlock <file>` | Déverrouiller |
| `lock.bat log <agent> <message>` | Logger une action |
| `lock.bat clear-old 60` | Supprimer verrous > 60 min |

---

## 📊 VOIR LES LOGS

```cmd
type storage\logs\agents.log
```

Ou les 20 dernières lignes:
```cmd
powershell -command "Get-Content storage\logs\agents.log -Tail 20"
```

---

*Ce fichier est la LOI pour tout agent IA travaillant sur ce projet*
