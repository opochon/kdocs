# K-Docs - Script d'audit et tests API
# Execute: .\tests\audit_api_tests.ps1

param(
    [string]$BaseUrl = "http://localhost/kdocs",
    [string]$Username = "admin",
    [string]$Password = "admin",
    [string]$OutputDir = ".\tests\audit_results"
)

$ErrorActionPreference = "Continue"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"

# Create output directory
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

$reportFile = Join-Path $OutputDir "audit_report_$timestamp.md"
$jsonResults = Join-Path $OutputDir "audit_results_$timestamp.json"

# Initialize results
$results = @{
    timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    base_url = $BaseUrl
    tests = @()
    summary = @{
        total = 0
        passed = 0
        failed = 0
        warnings = 0
    }
}

# Session cookie storage
$session = $null

function Write-Log {
    param([string]$Message, [string]$Color = "White")
    Write-Host $Message -ForegroundColor $Color
}

function Add-TestResult {
    param(
        [string]$Category,
        [string]$Name,
        [string]$Method,
        [string]$Endpoint,
        [int]$StatusCode,
        [string]$Status,
        [string]$Details = "",
        [double]$Duration = 0
    )

    $result = @{
        category = $Category
        name = $Name
        method = $Method
        endpoint = $Endpoint
        status_code = $StatusCode
        status = $Status
        details = $Details
        duration_ms = $Duration
    }

    $script:results.tests += $result
    $script:results.summary.total++

    switch ($Status) {
        "PASS" { $script:results.summary.passed++; Write-Log "  [PASS] $Name" "Green" }
        "FAIL" { $script:results.summary.failed++; Write-Log "  [FAIL] $Name - $Details" "Red" }
        "WARN" { $script:results.summary.warnings++; Write-Log "  [WARN] $Name - $Details" "Yellow" }
    }
}

function Test-Endpoint {
    param(
        [string]$Category,
        [string]$Name,
        [string]$Method = "GET",
        [string]$Endpoint,
        [hashtable]$Headers = @{},
        [string]$Body = "",
        [int[]]$ExpectedCodes = @(200),
        [string]$ContentType = "application/json"
    )

    $url = "$BaseUrl$Endpoint"
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

    try {
        $params = @{
            Uri = $url
            Method = $Method
            Headers = $Headers
            UseBasicParsing = $true
            ErrorAction = "Stop"
        }

        if ($session) {
            $params.WebSession = $session
        }

        if ($Body -and $Method -ne "GET") {
            $params.Body = $Body
            $params.ContentType = $ContentType
        }

        $response = Invoke-WebRequest @params
        $stopwatch.Stop()

        $statusCode = $response.StatusCode
        $status = if ($statusCode -in $ExpectedCodes) { "PASS" } else { "WARN" }

        Add-TestResult -Category $Category -Name $Name -Method $Method -Endpoint $Endpoint `
            -StatusCode $statusCode -Status $status -Duration $stopwatch.ElapsedMilliseconds

        return $response
    }
    catch {
        $stopwatch.Stop()
        $statusCode = 0
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
        }

        $status = if ($statusCode -in $ExpectedCodes) { "PASS" } else { "FAIL" }
        Add-TestResult -Category $Category -Name $Name -Method $Method -Endpoint $Endpoint `
            -StatusCode $statusCode -Status $status -Details $_.Exception.Message -Duration $stopwatch.ElapsedMilliseconds

        return $null
    }
}

function Test-Login {
    Write-Log "`n=== Test Authentification ===" "Cyan"

    # Test login page
    Test-Endpoint -Category "Auth" -Name "Page de login" -Endpoint "/login"

    # Test login POST
    $loginBody = "username=$Username&password=$Password"

    try {
        $response = Invoke-WebRequest -Uri "$BaseUrl/login" -Method POST `
            -Body $loginBody -ContentType "application/x-www-form-urlencoded" `
            -SessionVariable newSession -UseBasicParsing -MaximumRedirection 0 -ErrorAction SilentlyContinue
    }
    catch {
        if ($_.Exception.Response.StatusCode -eq 302) {
            $script:session = $newSession
            Add-TestResult -Category "Auth" -Name "Login POST" -Method "POST" -Endpoint "/login" `
                -StatusCode 302 -Status "PASS" -Details "Redirect to dashboard"
        }
        else {
            Add-TestResult -Category "Auth" -Name "Login POST" -Method "POST" -Endpoint "/login" `
                -StatusCode 0 -Status "FAIL" -Details $_.Exception.Message
        }
    }
}

function Test-PublicEndpoints {
    Write-Log "`n=== Test Endpoints Publics ===" "Cyan"

    Test-Endpoint -Category "Public" -Name "Health Check" -Endpoint "/health"
    Test-Endpoint -Category "Public" -Name "Login Page" -Endpoint "/login"
}

function Test-DashboardPages {
    Write-Log "`n=== Test Pages Dashboard ===" "Cyan"

    Test-Endpoint -Category "Dashboard" -Name "Page principale" -Endpoint "/"
    Test-Endpoint -Category "Dashboard" -Name "Dashboard" -Endpoint "/dashboard"
    Test-Endpoint -Category "Dashboard" -Name "Mes taches" -Endpoint "/mes-taches"
    Test-Endpoint -Category "Dashboard" -Name "Chat IA" -Endpoint "/chat"
}

function Test-DocumentPages {
    Write-Log "`n=== Test Pages Documents ===" "Cyan"

    Test-Endpoint -Category "Documents" -Name "Liste documents" -Endpoint "/documents"
    Test-Endpoint -Category "Documents" -Name "Upload page" -Endpoint "/documents/upload"
}

function Test-AdminPages {
    Write-Log "`n=== Test Pages Administration ===" "Cyan"

    $adminPages = @(
        @{Name="Admin index"; Endpoint="/admin"},
        @{Name="Parametres"; Endpoint="/admin/settings"},
        @{Name="Utilisateurs"; Endpoint="/admin/users"},
        @{Name="Roles"; Endpoint="/admin/roles"},
        @{Name="Groupes"; Endpoint="/admin/user-groups"},
        @{Name="Correspondants"; Endpoint="/admin/correspondents"},
        @{Name="Tags"; Endpoint="/admin/tags"},
        @{Name="Types documents"; Endpoint="/admin/document-types"},
        @{Name="Champs personnalises"; Endpoint="/admin/custom-fields"},
        @{Name="Chemins stockage"; Endpoint="/admin/storage-paths"},
        @{Name="Workflows"; Endpoint="/admin/workflows"},
        @{Name="Webhooks"; Endpoint="/admin/webhooks"},
        @{Name="Logs audit"; Endpoint="/admin/audit-logs"},
        @{Name="Export/Import"; Endpoint="/admin/export-import"},
        @{Name="Comptes email"; Endpoint="/admin/mail-accounts"},
        @{Name="Taches planifiees"; Endpoint="/admin/scheduled-tasks"},
        @{Name="Consume folder"; Endpoint="/admin/consume"},
        @{Name="Champs classification"; Endpoint="/admin/classification-fields"},
        @{Name="Indexation"; Endpoint="/admin/indexing"},
        @{Name="API Usage"; Endpoint="/admin/api-usage"}
    )

    foreach ($page in $adminPages) {
        Test-Endpoint -Category "Admin" -Name $page.Name -Endpoint $page.Endpoint
    }
}

function Test-DocumentsApi {
    Write-Log "`n=== Test API Documents ===" "Cyan"

    # List documents
    $response = Test-Endpoint -Category "API Documents" -Name "GET /api/documents" -Endpoint "/api/documents"

    # Get specific document (if exists)
    Test-Endpoint -Category "API Documents" -Name "GET /api/documents/1" -Endpoint "/api/documents/1" -ExpectedCodes @(200, 404)
}

function Test-TagsApi {
    Write-Log "`n=== Test API Tags ===" "Cyan"

    Test-Endpoint -Category "API Tags" -Name "GET /api/tags" -Endpoint "/api/tags"

    # Create tag
    $tagBody = '{"name":"Test Tag Audit","color":"#ff0000"}'
    Test-Endpoint -Category "API Tags" -Name "POST /api/tags" -Method "POST" -Endpoint "/api/tags" -Body $tagBody
}

function Test-CorrespondentsApi {
    Write-Log "`n=== Test API Correspondants ===" "Cyan"

    Test-Endpoint -Category "API Correspondents" -Name "GET /api/correspondents" -Endpoint "/api/correspondents"
    Test-Endpoint -Category "API Correspondents" -Name "Search correspondents" -Endpoint "/api/correspondents/search?q=test"
}

function Test-FoldersApi {
    Write-Log "`n=== Test API Dossiers ===" "Cyan"

    Test-Endpoint -Category "API Folders" -Name "GET tree" -Endpoint "/api/folders/tree"
    Test-Endpoint -Category "API Folders" -Name "GET tree-html" -Endpoint "/api/folders/tree-html"
    Test-Endpoint -Category "API Folders" -Name "GET children" -Endpoint "/api/folders/children"
    Test-Endpoint -Category "API Folders" -Name "GET documents" -Endpoint "/api/folders/documents"
    Test-Endpoint -Category "API Folders" -Name "GET crawl-status" -Endpoint "/api/folders/crawl-status"
    Test-Endpoint -Category "API Folders" -Name "GET indexing-status" -Endpoint "/api/folders/indexing-status"
}

function Test-SearchApi {
    Write-Log "`n=== Test API Recherche ===" "Cyan"

    Test-Endpoint -Category "API Search" -Name "Quick search" -Endpoint "/api/search/quick?q=test"

    # Natural language search
    $searchBody = '{"question":"Combien de documents?"}'
    Test-Endpoint -Category "API Search" -Name "NL Search (ask)" -Method "POST" -Endpoint "/api/search/ask" -Body $searchBody

    # Advanced search with operators
    $advancedBody = '{"question":"facture AND 2024","scope":"all"}'
    Test-Endpoint -Category "API Search" -Name "Advanced search AND" -Method "POST" -Endpoint "/api/search/ask" -Body $advancedBody

    $phraseBody = '{"question":"\"contrat de bail\"","scope":"content"}'
    Test-Endpoint -Category "API Search" -Name "Phrase search" -Method "POST" -Endpoint "/api/search/ask" -Body $phraseBody
}

function Test-WorkflowApi {
    Write-Log "`n=== Test API Workflows ===" "Cyan"

    Test-Endpoint -Category "API Workflow" -Name "GET workflows" -Endpoint "/api/workflows"
    Test-Endpoint -Category "API Workflow" -Name "GET node-catalog" -Endpoint "/api/workflow/node-catalog"
    Test-Endpoint -Category "API Workflow" -Name "GET options" -Endpoint "/api/workflow/options"
}

function Test-ValidationApi {
    Write-Log "`n=== Test API Validation ===" "Cyan"

    Test-Endpoint -Category "API Validation" -Name "GET pending" -Endpoint "/api/validation/pending"
    Test-Endpoint -Category "API Validation" -Name "GET statistics" -Endpoint "/api/validation/statistics"
    Test-Endpoint -Category "API Validation" -Name "GET roles" -Endpoint "/api/roles"
}

function Test-NotificationsApi {
    Write-Log "`n=== Test API Notifications ===" "Cyan"

    Test-Endpoint -Category "API Notifications" -Name "GET all" -Endpoint "/api/notifications"
    Test-Endpoint -Category "API Notifications" -Name "GET unread" -Endpoint "/api/notifications/unread"
    Test-Endpoint -Category "API Notifications" -Name "GET count" -Endpoint "/api/notifications/count"
}

function Test-ChatApi {
    Write-Log "`n=== Test API Chat ===" "Cyan"

    Test-Endpoint -Category "API Chat" -Name "GET conversations" -Endpoint "/api/chat/conversations"

    # Create conversation
    $response = Test-Endpoint -Category "API Chat" -Name "POST conversation" -Method "POST" -Endpoint "/api/chat/conversations"
}

function Test-TasksApi {
    Write-Log "`n=== Test API Taches ===" "Cyan"

    Test-Endpoint -Category "API Tasks" -Name "GET tasks" -Endpoint "/api/tasks"
    Test-Endpoint -Category "API Tasks" -Name "GET counts" -Endpoint "/api/tasks/counts"
    Test-Endpoint -Category "API Tasks" -Name "GET summary" -Endpoint "/api/tasks/summary"
}

function Test-EmailIngestionApi {
    Write-Log "`n=== Test API Email Ingestion ===" "Cyan"

    Test-Endpoint -Category "API Email" -Name "GET logs" -Endpoint "/api/email-ingestion/logs"
}

function Test-OtherApis {
    Write-Log "`n=== Test Autres APIs ===" "Cyan"

    Test-Endpoint -Category "API Other" -Name "Document types" -Endpoint "/api/document-types"
    Test-Endpoint -Category "API Other" -Name "Classification fields" -Endpoint "/api/classification-fields"
    Test-Endpoint -Category "API Other" -Name "User groups" -Endpoint "/api/user-groups"
    Test-Endpoint -Category "API Other" -Name "User notes" -Endpoint "/api/notes"
    Test-Endpoint -Category "API Other" -Name "OnlyOffice status" -Endpoint "/api/onlyoffice/status"
}

function Generate-Report {
    Write-Log "`n=== Generation du rapport ===" "Cyan"

    $report = @"
# Rapport d'Audit K-Docs
## Date: $($results.timestamp)
## URL de base: $($results.base_url)

---

## Resume

| Metrique | Valeur |
|----------|--------|
| Total tests | $($results.summary.total) |
| Reussis | $($results.summary.passed) |
| Echecs | $($results.summary.failed) |
| Avertissements | $($results.summary.warnings) |
| Taux de reussite | $([math]::Round(($results.summary.passed / [math]::Max(1, $results.summary.total)) * 100, 1))% |

---

## Details par categorie

"@

    # Group by category
    $categories = $results.tests | Group-Object -Property category

    foreach ($cat in $categories) {
        $report += "`n### $($cat.Name)`n`n"
        $report += "| Test | Methode | Endpoint | Code | Statut | Duree (ms) |`n"
        $report += "|------|---------|----------|------|--------|------------|`n"

        foreach ($test in $cat.Group) {
            $statusIcon = switch ($test.status) {
                "PASS" { "OK" }
                "FAIL" { "ECHEC" }
                "WARN" { "WARN" }
            }
            $report += "| $($test.name) | $($test.method) | $($test.endpoint) | $($test.status_code) | $statusIcon | $($test.duration_ms) |`n"
        }
    }

    # List failures
    $failures = $results.tests | Where-Object { $_.status -eq "FAIL" }
    if ($failures.Count -gt 0) {
        $report += "`n---`n`n## Echecs details`n`n"
        foreach ($fail in $failures) {
            $report += "- **$($fail.name)** ($($fail.endpoint)): $($fail.details)`n"
        }
    }

    # Save report
    $report | Out-File -FilePath $reportFile -Encoding UTF8
    $results | ConvertTo-Json -Depth 10 | Out-File -FilePath $jsonResults -Encoding UTF8

    Write-Log "`nRapport genere: $reportFile" "Green"
    Write-Log "Resultats JSON: $jsonResults" "Green"
}

# Main execution
Write-Log "========================================" "Cyan"
Write-Log "   K-Docs - Audit Automatise" "Cyan"
Write-Log "   $timestamp" "Cyan"
Write-Log "========================================" "Cyan"
Write-Log "URL: $BaseUrl"

# Run all tests
Test-PublicEndpoints
Test-Login

if ($session) {
    Test-DashboardPages
    Test-DocumentPages
    Test-AdminPages
    Test-DocumentsApi
    Test-TagsApi
    Test-CorrespondentsApi
    Test-FoldersApi
    Test-SearchApi
    Test-WorkflowApi
    Test-ValidationApi
    Test-NotificationsApi
    Test-ChatApi
    Test-TasksApi
    Test-EmailIngestionApi
    Test-OtherApis
}
else {
    Write-Log "`n[ERREUR] Login echoue, tests authentifies ignores" "Red"
}

Generate-Report

Write-Log "`n========================================" "Cyan"
Write-Log "   Resume Final" "Cyan"
Write-Log "========================================" "Cyan"
Write-Log "Total: $($results.summary.total) tests" "White"
Write-Log "Reussis: $($results.summary.passed)" "Green"
Write-Log "Echecs: $($results.summary.failed)" "Red"
Write-Log "Avertissements: $($results.summary.warnings)" "Yellow"
