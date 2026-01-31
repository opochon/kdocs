# K-Docs Queue Worker - Script pour Task Scheduler Windows
#
# Installation :
# 1. Ouvrir Task Scheduler (taskschd.msc)
# 2. Créer une tâche basique :
#    - Nom: KDocs Queue Worker
#    - Déclencheur: Au démarrage
#    - Action: Démarrer un programme
#    - Programme: powershell.exe
#    - Arguments: -ExecutionPolicy Bypass -File "C:\wamp64\www\kdocs\install\queue-worker-task.ps1"
# 3. Dans les propriétés avancées :
#    - Cocher "Exécuter même si l'utilisateur n'est pas connecté"
#    - Cocher "Redémarrer si la tâche échoue" (intervalle: 1 minute, max 3 fois)

param(
    [switch]$Install,
    [switch]$Uninstall,
    [switch]$Status
)

$TaskName = "KDocs Queue Worker"
$PhpPath = "c:\wamp64\bin\php\php8.3.14\php.exe"
$WorkerPath = "c:\wamp64\www\kdocs\app\workers\queue_worker.php"
$LogPath = "c:\wamp64\www\kdocs\storage\logs\queue_worker.log"
$PidFile = "c:\wamp64\www\kdocs\storage\queue_worker.pid"

function Write-Log {
    param($Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logMessage = "[$timestamp] $Message"
    Add-Content -Path $LogPath -Value $logMessage
    Write-Host $logMessage
}

# Installer la tâche planifiée
if ($Install) {
    Write-Host "Installation de la tâche planifiée '$TaskName'..."

    $action = New-ScheduledTaskAction -Execute "powershell.exe" `
        -Argument "-ExecutionPolicy Bypass -WindowStyle Hidden -File `"$PSCommandPath`""

    $trigger = New-ScheduledTaskTrigger -AtStartup

    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -RestartCount 3 `
        -RestartInterval (New-TimeSpan -Minutes 1) `
        -ExecutionTimeLimit (New-TimeSpan -Hours 0) `
        -StartWhenAvailable

    $principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force

    Write-Host "[OK] Tâche '$TaskName' installée"
    Write-Host "    Démarrer manuellement: schtasks /run /tn '$TaskName'"
    exit 0
}

# Désinstaller la tâche planifiée
if ($Uninstall) {
    Write-Host "Désinstallation de la tâche '$TaskName'..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "[OK] Tâche supprimée"
    exit 0
}

# Vérifier le statut
if ($Status) {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($task) {
        Write-Host "Tâche: $TaskName"
        Write-Host "État: $($task.State)"

        if (Test-Path $PidFile) {
            $pid = Get-Content $PidFile
            $process = Get-Process -Id $pid -ErrorAction SilentlyContinue
            if ($process) {
                Write-Host "Worker PID: $pid (actif)"
            } else {
                Write-Host "Worker PID: $pid (inactif)"
            }
        }
    } else {
        Write-Host "Tâche '$TaskName' non installée"
    }
    exit 0
}

# Mode normal : boucle infinie du worker
Write-Log "=== Démarrage du Queue Worker ==="

# Créer le dossier logs si nécessaire
$logDir = Split-Path $LogPath -Parent
if (!(Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

while ($true) {
    try {
        Write-Log "Lancement du worker PHP..."

        # Lancer le worker
        $process = Start-Process -FilePath $PhpPath -ArgumentList $WorkerPath `
            -NoNewWindow -PassThru -RedirectStandardOutput $LogPath -RedirectStandardError $LogPath

        # Enregistrer le PID
        $process.Id | Out-File -FilePath $PidFile -Force

        # Attendre la fin du worker (normal après 1h ou 100 jobs)
        $process.WaitForExit()

        Write-Log "Worker arrêté (code: $($process.ExitCode)), redémarrage dans 5 secondes..."

    } catch {
        Write-Log "Erreur: $_"
    }

    Start-Sleep -Seconds 5
}
