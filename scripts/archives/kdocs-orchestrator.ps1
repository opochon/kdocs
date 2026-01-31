# KDOCS - Orchestrateur multi-agents PowerShell
# Simule un workflow multi-agents avec Claude Code CLI
# Usage: .\kdocs-orchestrator.ps1 -Task "indexation-ui"

param(
    [Parameter(Mandatory=$false)]
    [string]$Task = "indexation-ui",
    
    [Parameter(Mandatory=$false)]
    [string]$KdocsPath = "C:\wamp64\www\kdocs",
    
    [Parameter(Mandatory=$false)]
    [switch]$DryRun = $false
)

$ErrorActionPreference = "Stop"

# Couleurs pour output
function Write-Step { param($msg) Write-Host "▶ $msg" -ForegroundColor Cyan }
function Write-Success { param($msg) Write-Host "✓ $msg" -ForegroundColor Green }
function Write-Error { param($msg) Write-Host "✗ $msg" -ForegroundColor Red }
function Write-Info { param($msg) Write-Host "  $msg" -ForegroundColor Gray }

# Vérifie que Claude Code CLI est disponible
function Test-ClaudeCode {
    try {
        $version = claude --version 2>&1
        Write-Success "Claude Code CLI disponible: $version"
        return $true
    } catch {
        Write-Error "Claude Code CLI non trouvé. Installez-le avec: npm install -g @anthropic-ai/claude-code"
        return $false
    }
}

# Exécute une tâche Claude Code
function Invoke-ClaudeTask {
    param(
        [string]$Prompt,
        [string]$WorkDir = $KdocsPath,
        [int]$TimeoutMinutes = 5
    )
    
    Write-Step "Tâche Claude Code..."
    Write-Info $Prompt.Substring(0, [Math]::Min(100, $Prompt.Length)) + "..."
    
    if ($DryRun) {
        Write-Info "[DRY RUN] Commande non exécutée"
        return $true
    }
    
    Push-Location $WorkDir
    try {
        # Lance Claude Code avec le prompt
        $result = claude --print "$Prompt" 2>&1
        Write-Success "Tâche terminée"
        Write-Info $result
        return $true
    } catch {
        Write-Error "Erreur: $_"
        return $false
    } finally {
        Pop-Location
    }
}

# Attend confirmation utilisateur
function Wait-UserConfirmation {
    param([string]$Message = "Appuyez sur Entrée pour continuer (ou 'q' pour quitter)...")
    
    Write-Host ""
    Write-Host $Message -ForegroundColor Yellow
    $key = Read-Host
    if ($key -eq 'q') {
        Write-Info "Arrêt demandé"
        exit 0
    }
}

# Notifie Claude.ai pour test visuel
function Request-VisualTest {
    param([string]$TestDescription)
    
    Write-Host ""
    Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Magenta
    Write-Host "  🔍 TEST VISUEL REQUIS - Claude.ai" -ForegroundColor Magenta
    Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Magenta
    Write-Host ""
    Write-Host "  $TestDescription" -ForegroundColor White
    Write-Host ""
    Write-Host "  → Demandez à Claude.ai de rafraîchir et vérifier" -ForegroundColor Gray
    Write-Host ""
    
    Wait-UserConfirmation "Test validé ? (Entrée = OK, 'q' = Arrêter)"
}

# ============================================================
# TÂCHES POUR FEATURE: INDEXATION UI
# ============================================================

$Tasks_IndexationUI = @(
    @{
        Name = "1. Endpoint API indexing-status"
        Prompt = @"
Lis WORKLOG.md pour contexte.

Crée un endpoint API GET /api/indexing-status qui:
1. Accepte paramètre ?path=xxx (chemin relatif du dossier)
2. Lit le fichier .indexing dans ce dossier s'il existe
3. Retourne JSON: {"status": "idle|indexing|completed", "total": n, "current": n, "percent": n}
4. Si pas de .indexing, retourne {"status": "idle"}

Fichiers à modifier:
- app/routes.php (ajouter route)
- app/controllers/ApiController.php (ajouter méthode)

Utilise IndexingService existant si possible.
"@
        Test = "Vérifier que /api/indexing-status?path=toclassify retourne du JSON"
    },
    
    @{
        Name = "2. DocumentController lit .indexing"
        Prompt = @"
Lis WORKLOG.md pour contexte.

Modifie app/controllers/DocumentController.php:
1. Dans la méthode index(), après avoir déterminé le dossier courant
2. Vérifie si .indexing existe pour ce dossier
3. Si oui, lis son contenu et passe à la vue:
   - `$indexingStatus` = array avec total, current, percent, status
4. Passe aussi `$currentFolderPath` (chemin relatif) pour le polling JS

Ne casse pas le code existant. Ajoute juste ces variables.
"@
        Test = "Rafraîchir page Documents, vérifier pas d'erreur PHP"
    },
    
    @{
        Name = "3. Barre de progression UI"
        Prompt = @"
Lis WORKLOG.md pour contexte.

Modifie templates/documents/index.php:
1. Ajoute en bas de page (avant </main>) une div fixe pour la barre de progression:
   - Position fixed bottom, full width, bg-blue-50
   - Affiche "Indexation: X sur Y (Z%)" avec barre de progression
   - Visible seulement si indexation en cours
   
2. Ajoute script JS qui:
   - Si `$indexingStatus` indique indexation en cours, affiche la barre
   - Poll /api/indexing-status?path=XXX toutes les 2 secondes
   - Met à jour la barre avec les nouvelles valeurs
   - Masque la barre et rafraîchit la page quand status = "completed" ou "idle"

Utilise vanilla JS, pas de framework.
"@
        Test = "Créer un .indexing manuellement dans toclassify et vérifier l'affichage de la barre"
    },
    
    @{
        Name = "4. Déclenchement auto indexation"
        Prompt = @"
Lis WORKLOG.md pour contexte.

Modifie app/controllers/DocumentController.php:
1. Dans index(), après lecture du .index du dossier courant
2. Si .index n'existe pas OU si file_count != nombre fichiers réels dans le dossier:
   - Queue une tâche d'indexation (utilise IndexingService->queueIndexation si existe)
   - OU crée le fichier dans storage/crawl_queue/ comme fait smart_indexer.php
3. Ne bloque PAS l'affichage de la page, c'est asynchrone

L'indexation réelle sera faite par smart_indexer.php en CLI ou cron.
"@
        Test = "Supprimer .index de toclassify, rafraîchir, vérifier qu'une queue est créée"
    },
    
    @{
        Name = "5. Script batch pour cron/tâche planifiée"
        Prompt = @"
Crée un fichier run_indexer.bat à la racine de kdocs qui:
1. Change vers le répertoire kdocs
2. Lance php app/workers/smart_indexer.php
3. Log le résultat dans storage/logs/indexer.log avec date

Et un fichier run_indexer.ps1 équivalent pour PowerShell.

Ces scripts pourront être utilisés dans le Planificateur de tâches Windows.
"@
        Test = "Exécuter run_indexer.bat manuellement, vérifier le log"
    }
)

# ============================================================
# MAIN
# ============================================================

Write-Host ""
Write-Host "╔══════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  KDOCS - Orchestrateur Multi-Agents                      ║" -ForegroundColor Cyan
Write-Host "║  Task: $Task                                    ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# Vérifications
if (-not (Test-Path $KdocsPath)) {
    Write-Error "Dossier kdocs non trouvé: $KdocsPath"
    exit 1
}

if (-not (Test-ClaudeCode)) {
    exit 1
}

# Sélection des tâches
$tasks = switch ($Task) {
    "indexation-ui" { $Tasks_IndexationUI }
    default { 
        Write-Error "Tâche inconnue: $Task"
        Write-Info "Tâches disponibles: indexation-ui"
        exit 1
    }
}

Write-Host ""
Write-Host "📋 $($tasks.Count) étapes à exécuter" -ForegroundColor White
Write-Host ""

foreach ($task in $tasks) {
    Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor DarkGray
    Write-Step $task.Name
    Write-Host ""
    
    # Exécute la tâche Claude Code
    $success = Invoke-ClaudeTask -Prompt $task.Prompt
    
    if (-not $success) {
        Write-Error "Échec de l'étape. Arrêt."
        exit 1
    }
    
    # Demande test visuel
    Request-VisualTest -TestDescription $task.Test
}

Write-Host ""
Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "  ✓ TOUTES LES ÉTAPES TERMINÉES" -ForegroundColor Green
Write-Host "═══════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host ""
Write-Host "N'oubliez pas de mettre à jour WORKLOG.md !" -ForegroundColor Yellow
