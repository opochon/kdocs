# KDOCS - Application directe des patches
# Ce script applique les corrections sans Claude Code
# Usage: .\apply-validation-fixes.ps1

param(
    [string]$KdocsPath = "C:\wamp64\www\kdocs"
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host "  KDOCS - Application des patches Fichiers a Valider" -ForegroundColor Cyan
Write-Host "==============================================================" -ForegroundColor Cyan
Write-Host ""

# ============================================================
# BUG-2: Ajouter extractSuggestedTags dans ClassificationService
# ============================================================

Write-Host "[1/3] BUG-2: Ajout extractSuggestedTags..." -ForegroundColor Yellow

$classificationFile = "$KdocsPath\app\Services\ClassificationService.php"
$content = Get-Content $classificationFile -Raw -Encoding UTF8

# Verifier si deja present
if ($content -match "extractSuggestedTags") {
    Write-Host "  -> Deja present, skip" -ForegroundColor Green
} else {
    # Ajouter la methode avant la derniere accolade
    $newMethod = @'

    /**
     * Extrait des tags suggeres depuis le contenu OCR
     * @param string $content Contenu OCR du document
     * @return array Liste de tags suggeres
     */
    public function extractSuggestedTags(string $content): array
    {
        $tags = [];
        
        // 1. Extraire les noms propres (mots avec majuscule, > 3 lettres)
        preg_match_all('/\b([A-ZÀÂÄÉÈÊËÏÎÔÙÛÜÇ][a-zàâäéèêëïîôùûüç]{2,})\b/u', $content, $matches);
        $properNouns = array_unique($matches[1] ?? []);
        // Filtrer les mots trop communs
        $commonWords = ['Le', 'La', 'Les', 'Un', 'Une', 'Des', 'Du', 'De', 'Et', 'En', 'Au', 'Aux', 'Ce', 'Cette', 'Ces', 'Son', 'Sa', 'Ses', 'Leur', 'Leurs', 'Mon', 'Ma', 'Mes', 'Notre', 'Nos', 'Votre', 'Vos'];
        $properNouns = array_diff($properNouns, $commonWords);
        $tags = array_merge($tags, array_slice($properNouns, 0, 5));
        
        // 2. Extraire les annees (19xx, 20xx)
        preg_match_all('/\b(19\d{2}|20\d{2})\b/', $content, $years);
        $tags = array_merge($tags, array_unique($years[0] ?? []));
        
        // 3. Mots-cles juridiques/metier courants
        $keywords = ['Tribunal', 'Arrêt', 'Jugement', 'Contrat', 'Convention', 'Facture', 'Devis', 'Attestation', 'Certificat', 'Décision', 'Accord', 'Ordonnance', 'Procès', 'Appel', 'Recours'];
        foreach ($keywords as $kw) {
            if (stripos($content, $kw) !== false) {
                $tags[] = $kw;
            }
        }
        
        // Dedupliquer et limiter a 10
        return array_slice(array_unique($tags), 0, 10);
    }
}
'@
    
    # Remplacer la derniere accolade par la methode + accolade
    $content = $content -replace '\}\s*$', $newMethod
    
    Set-Content $classificationFile -Value $content -Encoding UTF8 -NoNewline
    Write-Host "  -> OK" -ForegroundColor Green
}

# ============================================================
# BUG-3: Ajouter extractTitle dans ConsumeFolderService
# ============================================================

Write-Host "[2/3] BUG-3: Ajout extractTitle..." -ForegroundColor Yellow

$consumeFile = "$KdocsPath\app\Services\ConsumeFolderService.php"
$content = Get-Content $consumeFile -Raw -Encoding UTF8

if ($content -match "function extractTitle") {
    Write-Host "  -> Deja present, skip" -ForegroundColor Green
} else {
    $newMethod = @'

    /**
     * Extrait un titre depuis le contenu OCR ou le nom de fichier
     */
    public function extractTitle(string $filename, ?string $ocrContent): string
    {
        // 1. D'abord essayer depuis le nom de fichier (sans extension)
        $nameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
        
        // Nettoyer le nom (remplacer _ et - par espaces)
        $cleanName = preg_replace('/[_-]+/', ' ', $nameWithoutExt);
        $cleanName = preg_replace('/\s+/', ' ', trim($cleanName));
        
        // Si le nom est informatif (pas juste des chiffres ou "scan", "doc", etc.)
        if (strlen($cleanName) > 5 && !preg_match('/^(scan|doc|document|img|image|file|\d+)$/i', $cleanName)) {
            return ucfirst($cleanName);
        }
        
        // 2. Sinon, extraire depuis le contenu OCR
        if ($ocrContent) {
            $lines = array_filter(array_map('trim', explode("\n", $ocrContent)));
            $lines = array_slice($lines, 0, 5);
            
            foreach ($lines as $line) {
                if (strlen($line) < 10 || strlen($line) > 100) continue;
                
                // Patterns courants pour documents juridiques/administratifs
                if (preg_match('/^(Arrêt|Jugement|Décision|Contrat|Convention|Facture|Devis|Attestation|Certificat|Ordonnance)/iu', $line)) {
                    return $line;
                }
            }
            
            // Sinon prendre la premiere ligne significative
            foreach ($lines as $line) {
                if (strlen($line) >= 15 && strlen($line) <= 80) {
                    return $line;
                }
            }
        }
        
        // 3. Fallback
        return $cleanName ?: 'Document sans titre';
    }
'@
    
    # Ajouter avant la derniere accolade
    $content = $content -replace '\}\s*$', "$newMethod`n}"
    
    Set-Content $consumeFile -Value $content -Encoding UTF8 -NoNewline
    Write-Host "  -> OK" -ForegroundColor Green
}

# ============================================================
# BUG-2 suite: Appeler extractSuggestedTags dans ConsumeController
# ============================================================

Write-Host "[3/3] Integration dans ConsumeController..." -ForegroundColor Yellow

$controllerFile = "$KdocsPath\app\Controllers\ConsumeController.php"
$content = Get-Content $controllerFile -Raw -Encoding UTF8

if ($content -match "suggested_tags") {
    Write-Host "  -> Deja present, skip" -ForegroundColor Green
} else {
    # Chercher la ligne avec content_preview et ajouter apres
    $pattern = "\`$doc\['content_preview'\]\s*=\s*substr\([^;]+;"
    $replacement = @'
$doc['content_preview'] = substr($doc['content'] ?? $doc['ocr_text'] ?? '', 0, 500);
            
            // Extraire les tags suggeres depuis le contenu OCR
            $doc['suggested_tags'] = $classifier->extractSuggestedTags($doc['content'] ?? $doc['ocr_text'] ?? '');
'@
    
    if ($content -match $pattern) {
        $content = $content -replace $pattern, $replacement
        Set-Content $controllerFile -Value $content -Encoding UTF8 -NoNewline
        Write-Host "  -> OK" -ForegroundColor Green
    } else {
        Write-Host "  -> Pattern non trouve, modification manuelle requise" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Green
Write-Host "  Patches appliques !" -ForegroundColor Green
Write-Host "==============================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Prochaines etapes:" -ForegroundColor White
Write-Host "  1. Aller sur http://localhost/kdocs/admin/consume" -ForegroundColor Gray
Write-Host "  2. Cliquer sur 'Re-scanner les documents'" -ForegroundColor Gray
Write-Host "  3. Verifier: accents OK, tags suggeres, titre correct" -ForegroundColor Gray
Write-Host ""

$open = Read-Host "Ouvrir la page dans le navigateur ? (o/n)"
if ($open -eq "o") {
    Start-Process "http://localhost/kdocs/admin/consume"
}
