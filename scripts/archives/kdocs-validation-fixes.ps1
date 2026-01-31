# KDOCS - Orchestrateur Fichiers a Valider
# Fix des bugs OCR, Tags, Titre, Confiance
# Usage: .\kdocs-validation-fixes.ps1

param(
    [Parameter(Mandatory=$false)]
    [string]$KdocsPath = "C:\wamp64\www\kdocs",
    
    [Parameter(Mandatory=$false)]
    [switch]$DryRun = $false,
    
    [Parameter(Mandatory=$false)]
    [int]$StartAt = 1
)

$ErrorActionPreference = "Stop"

# Couleurs
function Write-Step { param($msg) Write-Host "> $msg" -ForegroundColor Cyan }
function Write-Success { param($msg) Write-Host "[OK] $msg" -ForegroundColor Green }
function Write-Err { param($msg) Write-Host "[ERREUR] $msg" -ForegroundColor Red }
function Write-Info { param($msg) Write-Host "  $msg" -ForegroundColor Gray }
function Write-Warn { param($msg) Write-Host "[WARN] $msg" -ForegroundColor Yellow }

# Verifie Claude Code CLI
function Test-ClaudeCode {
    try {
        $null = claude --version 2>&1
        Write-Success "Claude Code CLI disponible"
        return $true
    } catch {
        Write-Err "Claude Code CLI non trouve"
        Write-Info "Installez avec: npm install -g @anthropic-ai/claude-code"
        return $false
    }
}

# Execute une tache Claude Code
function Invoke-ClaudeTask {
    param(
        [string]$Prompt,
        [string]$WorkDir = $KdocsPath
    )
    
    if ($DryRun) {
        Write-Warn "[DRY RUN] Commande non executee"
        return $true
    }
    
    Push-Location $WorkDir
    try {
        Write-Info "Execution Claude Code..."
        # Sauvegarder le prompt dans un fichier temporaire pour eviter les problemes d'echappement
        $tempFile = [System.IO.Path]::GetTempFileName()
        $Prompt | Out-File -FilePath $tempFile -Encoding UTF8
        
        # Lancer claude avec le fichier
        $result = Get-Content $tempFile -Raw | claude --print 2>&1
        Remove-Item $tempFile -Force
        
        Write-Success "Tache terminee"
        return $true
    } catch {
        Write-Err "Erreur: $_"
        return $false
    } finally {
        Pop-Location
    }
}

# Demande test visuel
function Request-VisualTest {
    param(
        [string]$TestDescription,
        [string]$TestUrl = ""
    )
    
    Write-Host ""
    Write-Host "==============================================================" -ForegroundColor Magenta
    Write-Host "  TEST VISUEL REQUIS" -ForegroundColor Magenta
    Write-Host "==============================================================" -ForegroundColor Magenta
    Write-Host ""
    Write-Host "  $TestDescription" -ForegroundColor White
    if ($TestUrl) {
        Write-Host ""
        Write-Host "  URL: $TestUrl" -ForegroundColor Cyan
    }
    Write-Host ""
    Write-Host "  -> Demandez a Claude.ai de rafraichir et verifier" -ForegroundColor Gray
    Write-Host ""
    
    $key = Read-Host "Test valide ? (Entree = OK, s = Skip, q = Quitter)"
    if ($key -eq "q") { exit 0 }
    return ($key -ne "s")
}

# ============================================================
# TACHES - Definies comme fichiers texte inline
# ============================================================

$Prompt1 = @'
Lis WORKLOG.md pour le contexte du BUG-1.

Modifie app/Services/OCRService.php pour corriger l'encodage UTF-8.

Dans la methode extractTextFromImage(), APRES la ligne:
    $text = file_get_contents($outputFile . '.txt');

Ajoute ce code de conversion UTF-8:

// Fix encodage: Tesseract peut retourner ISO-8859-1
if ($text && !mb_check_encoding($text, 'UTF-8')) {
    $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($detected && $detected !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $detected);
    }
}

Fais la meme modification dans extractTextFromPDF() si elle utilise aussi file_get_contents pour lire le resultat.

Ne casse pas le code existant, ajoute juste la conversion.
'@

$Prompt2 = @'
Lis WORKLOG.md pour le contexte.

Cree une methode pour extraire des tags suggeres depuis le contenu OCR.

1. Dans app/Services/ClassificationService.php, ajoute une methode extractSuggestedTags qui:
   - Extrait les noms propres (mots avec majuscule initiale, > 3 lettres)
   - Extrait les annees (19xx, 20xx)
   - Detecte les mots-cles juridiques: Tribunal, Arret, Jugement, Contrat, Facture, Devis, Convention, Accord, Attestation, Certificat, Decision
   - Retourne un tableau de max 10 tags uniques

2. Dans app/Controllers/ConsumeController.php, dans la methode index(),
   apres avoir recupere le contenu OCR de chaque document pending,
   appelle cette methode et passe les tags suggeres a la vue dans $doc['suggested_tags']

Ne modifie PAS encore le template.
'@

$Prompt3 = @'
Modifie templates/admin/consume.php ou consume_card.php.

Trouve le champ Tags et ajoute l'affichage des tags suggeres sous forme de badges cliquables.

Ajoute des boutons avec classe "px-2 py-0.5 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
qui affichent "+ tagname" pour chaque tag suggere.

Ajoute une fonction JavaScript addSuggestedTag(btn, tagName) qui:
- Trouve le select des tags le plus proche
- Selectionne l'option correspondante
- Desactive le bouton apres clic

Adapte le code au HTML existant.
'@

$Prompt4 = @'
Le probleme: le titre du document est "toclassify" (nom du dossier) au lieu d'un vrai titre.

1. Dans app/Services/ConsumeFolderService.php, ajoute une methode extractTitle(string filename, string ocrContent) qui:
   - D'abord essaie le nom de fichier (nettoye, sans extension)
   - Si pas informatif, extrait depuis le contenu OCR la premiere ligne significative
   - Detecte les patterns juridiques: Arret, Jugement, Decision, Contrat, Convention, Facture, Devis, Attestation
   - Retourne un titre ou "Document sans titre" en fallback

2. Appelle cette methode lors de la creation du document pending pour alimenter le champ title.

3. Trouve ou le titre est initialise dans scan() et remplace par extractTitle().
'@

$Prompt5 = @'
Le probleme: la confiance est toujours a 0%.

1. Dans app/Services/ClassificationService.php, modifie ou cree calculateConfidence(array suggestions) qui:
   - +30% si document_type_id est suggere
   - +20% si doc_date est extraite
   - +20% si correspondent_id est suggere
   - +15% si title est extrait (pas generique)
   - +15% si des tags sont suggeres
   - Retourne min(100, total)

2. Assure-toi que cette confiance est bien passee a la vue et affichee.
'@

$Prompt6 = @'
Tous les bugs ont ete corriges. 

Reponds simplement: OK, pret pour le test final.

L'utilisateur va faire un re-scan via l'interface pour valider.
'@

$Tasks = @(
    @{
        Number = 1
        Name = "BUG-1: Fix OCR encodage UTF-8"
        Priority = "HAUTE"
        Prompt = $Prompt1
        Test = "Re-scanner un document avec accents, verifier que federal s'affiche correctement (pas f?d?ral)"
        TestUrl = "http://localhost/kdocs/admin/consume"
    },
    @{
        Number = 2
        Name = "BUG-2: Extraction automatique de tags depuis OCR"
        Priority = "MOYENNE"
        Prompt = $Prompt2
        Test = "Verifier que le code compile sans erreur PHP au chargement de la page"
        TestUrl = "http://localhost/kdocs/admin/consume"
    },
    @{
        Number = 3
        Name = "BUG-2 suite: Affichage des tags suggeres dans UI"
        Priority = "MOYENNE"
        Prompt = $Prompt3
        Test = "Verifier que les tags suggeres apparaissent sous le champ Tags et sont cliquables"
        TestUrl = "http://localhost/kdocs/admin/consume"
    },
    @{
        Number = 4
        Name = "BUG-3: Extraction du titre depuis OCR/nom fichier"
        Priority = "MOYENNE"
        Prompt = $Prompt4
        Test = "Re-scanner, verifier que le titre est Arret du 5 juin 2024 ou similaire (pas toclassify)"
        TestUrl = "http://localhost/kdocs/admin/consume"
    },
    @{
        Number = 5
        Name = "BUG-4: Ameliorer le calcul de confiance"
        Priority = "BASSE"
        Prompt = $Prompt5
        Test = "Verifier que la confiance affiche un pourcentage > 0% (ex: 50%, 65%)"
        TestUrl = "http://localhost/kdocs/admin/consume"
    },
    @{
        Number = 6
        Name = "Test final: Re-scan complet"
        Priority = "VALIDATION"
        Prompt = $Prompt6
        Test = "Test complet: accents OK, tags suggeres, titre correct, confiance > 0%"
        TestUrl = "http://localhost/kdocs/admin/consume"
    }
)

# ============================================================
# MAIN
# ============================================================

Clear-Host
Write-Host ""
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host "  KDOCS - Fix Bugs Fichiers a Valider" -ForegroundColor Cyan
Write-Host "  4 bugs: OCR, Tags, Titre, Confiance" -ForegroundColor Cyan
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host ""

# Verifications
if (-not (Test-Path $KdocsPath)) {
    Write-Err "Dossier kdocs non trouve: $KdocsPath"
    exit 1
}

if (-not (Test-ClaudeCode)) {
    exit 1
}

Write-Host ""
Write-Host "6 etapes a executer (demarrage a l'etape $StartAt)" -ForegroundColor White
Write-Host ""

$successCount = 0
$skipCount = 0

foreach ($task in $Tasks) {
    if ($task.Number -lt $StartAt) {
        Write-Info "Etape $($task.Number) skippee (StartAt=$StartAt)"
        continue
    }
    
    Write-Host ""
    Write-Host "--------------------------------------------------------------" -ForegroundColor DarkGray
    Write-Host ""
    
    # Couleur selon priorite
    $prioColor = switch ($task.Priority) {
        "HAUTE" { "Red" }
        "MOYENNE" { "Yellow" }
        "BASSE" { "Green" }
        default { "White" }
    }
    
    Write-Host "  ETAPE $($task.Number)/6" -ForegroundColor DarkGray
    Write-Host "  $($task.Name)" -ForegroundColor White
    Write-Host "  Priorite: " -NoNewline
    Write-Host $task.Priority -ForegroundColor $prioColor
    Write-Host ""
    
    # Executer Claude Code
    $success = Invoke-ClaudeTask -Prompt $task.Prompt
    
    if (-not $success) {
        Write-Err "Echec de l'etape $($task.Number)"
        $continue = Read-Host "Continuer quand meme ? (o/n)"
        if ($continue -ne "o") { exit 1 }
    }
    
    # Demander test visuel
    Write-Host ""
    $validated = Request-VisualTest -TestDescription $task.Test -TestUrl $task.TestUrl
    
    if ($validated) {
        $successCount++
        Write-Success "Etape $($task.Number) validee"
    } else {
        $skipCount++
        Write-Warn "Etape $($task.Number) skippee"
    }
}

# Resume final
Write-Host ""
Write-Host "==============================================================" -ForegroundColor Green
Write-Host ""
Write-Host "  RESUME" -ForegroundColor Green
Write-Host "  [OK] $successCount etape(s) validee(s)" -ForegroundColor Green
if ($skipCount -gt 0) {
    Write-Host "  [WARN] $skipCount etape(s) skippee(s)" -ForegroundColor Yellow
}
Write-Host ""
Write-Host "==============================================================" -ForegroundColor Green
Write-Host ""
Write-Host "N'oubliez pas de mettre a jour WORKLOG.md !" -ForegroundColor Yellow
Write-Host ""

# Proposer de lancer un re-scan
$rescan = Read-Host "Lancer le re-scan des documents maintenant ? (o/n)"
if ($rescan -eq "o") {
    Write-Step "Ouverture de la page de validation..."
    Start-Process "http://localhost/kdocs/admin/consume"
}
