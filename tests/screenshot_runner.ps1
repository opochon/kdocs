# K-Docs - Script de capture d'ecran automatique
# Utilise Edge/Chrome en mode headless pour capturer toutes les pages

param(
    [string]$BaseUrl = "http://localhost/kdocs",
    [string]$OutputDir = ".\tests\screenshots",
    [string]$Username = "admin",
    [string]$Password = "admin"
)

# Create output directory
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$screenshotDir = Join-Path $OutputDir $timestamp

if (-not (Test-Path $screenshotDir)) {
    New-Item -ItemType Directory -Path $screenshotDir -Force | Out-Null
}

# Find browser
$chromePath = @(
    "C:\Program Files\Google\Chrome\Application\chrome.exe",
    "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

$edgePath = @(
    "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

$browserPath = $chromePath ?? $edgePath

if (-not $browserPath) {
    Write-Host "Aucun navigateur trouve (Chrome ou Edge requis)" -ForegroundColor Red
    exit 1
}

Write-Host "Navigateur: $browserPath" -ForegroundColor Cyan

# Pages to capture
$pages = @(
    @{name="login"; url="/login"; auth=$false},
    @{name="dashboard"; url="/"; auth=$true},
    @{name="dashboard_main"; url="/dashboard"; auth=$true},
    @{name="chat"; url="/chat"; auth=$true},
    @{name="mes_taches"; url="/mes-taches"; auth=$true},
    @{name="documents"; url="/documents"; auth=$true},
    @{name="documents_upload"; url="/documents/upload"; auth=$true},
    @{name="admin"; url="/admin"; auth=$true},
    @{name="admin_settings"; url="/admin/settings"; auth=$true},
    @{name="admin_users"; url="/admin/users"; auth=$true},
    @{name="admin_roles"; url="/admin/roles"; auth=$true},
    @{name="admin_groups"; url="/admin/user-groups"; auth=$true},
    @{name="admin_correspondents"; url="/admin/correspondents"; auth=$true},
    @{name="admin_tags"; url="/admin/tags"; auth=$true},
    @{name="admin_document_types"; url="/admin/document-types"; auth=$true},
    @{name="admin_workflows"; url="/admin/workflows"; auth=$true},
    @{name="admin_mail_accounts"; url="/admin/mail-accounts"; auth=$true},
    @{name="admin_consume"; url="/admin/consume"; auth=$true},
    @{name="admin_indexing"; url="/admin/indexing"; auth=$true}
)

Write-Host "`n=== Capture d'ecran K-Docs ===" -ForegroundColor Cyan
Write-Host "URL: $BaseUrl"
Write-Host "Output: $screenshotDir"
Write-Host ""

$captured = 0
$failed = 0

foreach ($page in $pages) {
    $url = "$BaseUrl$($page.url)"
    $filename = Join-Path $screenshotDir "$($page.name).png"

    Write-Host -NoNewline "  Capture $($page.name)... "

    try {
        # Use headless browser to capture
        $tempHtml = [System.IO.Path]::GetTempFileName() + ".html"

        # Create a simple HTML that will redirect after capturing
        $args = @(
            "--headless",
            "--disable-gpu",
            "--window-size=1920,1080",
            "--screenshot=$filename",
            $url
        )

        $process = Start-Process -FilePath $browserPath -ArgumentList $args -Wait -PassThru -NoNewWindow

        if (Test-Path $filename) {
            Write-Host "OK" -ForegroundColor Green
            $captured++
        } else {
            Write-Host "ECHEC" -ForegroundColor Red
            $failed++
        }
    }
    catch {
        Write-Host "ERREUR: $_" -ForegroundColor Red
        $failed++
    }
}

# Generate HTML gallery
$galleryHtml = @"
<!DOCTYPE html>
<html>
<head>
    <title>K-Docs - Captures d'ecran - $timestamp</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        h1 { color: #333; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; }
        .screenshot { background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .screenshot img { width: 100%; height: auto; display: block; }
        .screenshot .title { padding: 10px; font-weight: bold; background: #333; color: white; }
        .summary { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>K-Docs - Captures d'ecran</h1>
    <div class="summary">
        <p><strong>Date:</strong> $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")</p>
        <p><strong>URL:</strong> $BaseUrl</p>
        <p><strong>Captures reussies:</strong> $captured / $($pages.Count)</p>
    </div>
    <div class="gallery">
"@

foreach ($page in $pages) {
    $filename = "$($page.name).png"
    if (Test-Path (Join-Path $screenshotDir $filename)) {
        $galleryHtml += @"
        <div class="screenshot">
            <div class="title">$($page.name) - $($page.url)</div>
            <a href="$filename" target="_blank"><img src="$filename" alt="$($page.name)"></a>
        </div>
"@
    }
}

$galleryHtml += @"
    </div>
</body>
</html>
"@

$galleryFile = Join-Path $screenshotDir "index.html"
$galleryHtml | Out-File -FilePath $galleryFile -Encoding UTF8

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "   Resume" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Captures reussies: $captured" -ForegroundColor Green
Write-Host "Echecs: $failed" -ForegroundColor $(if ($failed -gt 0) { "Red" } else { "Green" })
Write-Host "Galerie: $galleryFile" -ForegroundColor Cyan
