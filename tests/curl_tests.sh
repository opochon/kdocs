#!/bin/bash
# K-Docs - Tests curl complets
# Usage: ./tests/curl_tests.sh [base_url]

BASE_URL="${1:-http://localhost/kdocs}"
COOKIE_FILE="/tmp/kdocs_cookies.txt"
OUTPUT_DIR="./tests/curl_results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Counters
TOTAL=0
PASSED=0
FAILED=0

mkdir -p "$OUTPUT_DIR"
REPORT="$OUTPUT_DIR/curl_report_$TIMESTAMP.md"

echo -e "${CYAN}========================================${NC}"
echo -e "${CYAN}   K-Docs - Tests curl${NC}"
echo -e "${CYAN}   $TIMESTAMP${NC}"
echo -e "${CYAN}========================================${NC}"
echo "URL: $BASE_URL"
echo ""

# Initialize report
cat > "$REPORT" << EOF
# Rapport Tests curl K-Docs

**Date:** $(date)
**URL:** $BASE_URL

---

## Tests Executes

| Test | Methode | Endpoint | Code | Statut |
|------|---------|----------|------|--------|
EOF

test_endpoint() {
    local name="$1"
    local method="$2"
    local endpoint="$3"
    local data="$4"
    local expected="${5:-200}"

    TOTAL=$((TOTAL + 1))

    local curl_opts="-s -o /dev/null -w '%{http_code}' -b $COOKIE_FILE -c $COOKIE_FILE"

    case "$method" in
        POST)
            if [ -n "$data" ]; then
                code=$(curl $curl_opts -X POST -H "Content-Type: application/json" -d "$data" "$BASE_URL$endpoint")
            else
                code=$(curl $curl_opts -X POST "$BASE_URL$endpoint")
            fi
            ;;
        PUT)
            code=$(curl $curl_opts -X PUT -H "Content-Type: application/json" -d "$data" "$BASE_URL$endpoint")
            ;;
        DELETE)
            code=$(curl $curl_opts -X DELETE "$BASE_URL$endpoint")
            ;;
        *)
            code=$(curl $curl_opts "$BASE_URL$endpoint")
            ;;
    esac

    if [[ "$expected" == *"$code"* ]] || [ "$code" == "$expected" ]; then
        echo -e "  ${GREEN}[PASS]${NC} $name (HTTP $code)"
        status="OK"
        PASSED=$((PASSED + 1))
    else
        echo -e "  ${RED}[FAIL]${NC} $name (HTTP $code, attendu $expected)"
        status="ECHEC"
        FAILED=$((FAILED + 1))
    fi

    echo "| $name | $method | $endpoint | $code | $status |" >> "$REPORT"
}

# Login first
echo -e "\n${CYAN}=== Authentification ===${NC}"
curl -s -c $COOKIE_FILE -d "username=admin&password=admin" "$BASE_URL/login" > /dev/null
test_endpoint "Login" "POST" "/login" "" "200|302"

# Public endpoints
echo -e "\n${CYAN}=== Endpoints Publics ===${NC}"
test_endpoint "Health Check" "GET" "/health"
test_endpoint "Login Page" "GET" "/login"

# Dashboard pages
echo -e "\n${CYAN}=== Pages Dashboard ===${NC}"
test_endpoint "Accueil" "GET" "/" "200|302"
test_endpoint "Dashboard" "GET" "/dashboard"
test_endpoint "Chat IA" "GET" "/chat"
test_endpoint "Mes taches" "GET" "/mes-taches"

# Document pages
echo -e "\n${CYAN}=== Pages Documents ===${NC}"
test_endpoint "Liste documents" "GET" "/documents"
test_endpoint "Upload" "GET" "/documents/upload"

# Admin pages
echo -e "\n${CYAN}=== Pages Admin ===${NC}"
test_endpoint "Admin index" "GET" "/admin"
test_endpoint "Parametres" "GET" "/admin/settings"
test_endpoint "Utilisateurs" "GET" "/admin/users"
test_endpoint "Roles" "GET" "/admin/roles"
test_endpoint "Groupes" "GET" "/admin/user-groups"
test_endpoint "Correspondants" "GET" "/admin/correspondents"
test_endpoint "Tags" "GET" "/admin/tags"
test_endpoint "Types documents" "GET" "/admin/document-types"
test_endpoint "Champs perso" "GET" "/admin/custom-fields"
test_endpoint "Chemins stockage" "GET" "/admin/storage-paths"
test_endpoint "Workflows" "GET" "/admin/workflows"
test_endpoint "Webhooks" "GET" "/admin/webhooks"
test_endpoint "Logs audit" "GET" "/admin/audit-logs"
test_endpoint "Export/Import" "GET" "/admin/export-import"
test_endpoint "Comptes email" "GET" "/admin/mail-accounts"
test_endpoint "Taches planifiees" "GET" "/admin/scheduled-tasks"
test_endpoint "Consume folder" "GET" "/admin/consume"
test_endpoint "Classification" "GET" "/admin/classification-fields"
test_endpoint "Indexation" "GET" "/admin/indexing"

# API Documents
echo -e "\n${CYAN}=== API Documents ===${NC}"
test_endpoint "GET documents" "GET" "/api/documents"
test_endpoint "GET document 1" "GET" "/api/documents/1" "" "200|404"

# API Tags
echo -e "\n${CYAN}=== API Tags ===${NC}"
test_endpoint "GET tags" "GET" "/api/tags"
test_endpoint "POST tag" "POST" "/api/tags" '{"name":"Test curl","color":"#ff0000"}'

# API Correspondents
echo -e "\n${CYAN}=== API Correspondants ===${NC}"
test_endpoint "GET correspondents" "GET" "/api/correspondents"
test_endpoint "Search correspondents" "GET" "/api/correspondents/search?q=test"

# API Folders
echo -e "\n${CYAN}=== API Dossiers ===${NC}"
test_endpoint "GET tree" "GET" "/api/folders/tree"
test_endpoint "GET tree-html" "GET" "/api/folders/tree-html"
test_endpoint "GET children" "GET" "/api/folders/children"
test_endpoint "GET documents" "GET" "/api/folders/documents"
test_endpoint "GET crawl-status" "GET" "/api/folders/crawl-status"
test_endpoint "GET indexing-status" "GET" "/api/folders/indexing-status"

# API Search
echo -e "\n${CYAN}=== API Recherche ===${NC}"
test_endpoint "Quick search" "GET" "/api/search/quick?q=test"
test_endpoint "NL Search" "POST" "/api/search/ask" '{"question":"Combien de documents?"}'
test_endpoint "Search AND" "POST" "/api/search/ask" '{"question":"document AND test","scope":"all"}'
test_endpoint "Search OR" "POST" "/api/search/ask" '{"question":"facture OR devis","scope":"all"}'
test_endpoint "Search phrase" "POST" "/api/search/ask" '{"question":"\"contrat de bail\"","scope":"content"}'
test_endpoint "Search wildcard" "POST" "/api/search/ask" '{"question":"fact*","scope":"name"}'
test_endpoint "Search date range" "POST" "/api/search/ask" '{"question":"facture","date_from":"2024-01-01","date_to":"2024-12-31"}'

# API Workflow
echo -e "\n${CYAN}=== API Workflows ===${NC}"
test_endpoint "GET workflows" "GET" "/api/workflows"
test_endpoint "GET node-catalog" "GET" "/api/workflow/node-catalog"
test_endpoint "GET options" "GET" "/api/workflow/options"

# API Validation
echo -e "\n${CYAN}=== API Validation ===${NC}"
test_endpoint "GET pending" "GET" "/api/validation/pending"
test_endpoint "GET statistics" "GET" "/api/validation/statistics"
test_endpoint "GET roles" "GET" "/api/roles"

# API Notifications
echo -e "\n${CYAN}=== API Notifications ===${NC}"
test_endpoint "GET all" "GET" "/api/notifications"
test_endpoint "GET unread" "GET" "/api/notifications/unread"
test_endpoint "GET count" "GET" "/api/notifications/count"

# API Chat
echo -e "\n${CYAN}=== API Chat ===${NC}"
test_endpoint "GET conversations" "GET" "/api/chat/conversations"
test_endpoint "POST conversation" "POST" "/api/chat/conversations"

# API Tasks
echo -e "\n${CYAN}=== API Taches ===${NC}"
test_endpoint "GET tasks" "GET" "/api/tasks"
test_endpoint "GET counts" "GET" "/api/tasks/counts"
test_endpoint "GET summary" "GET" "/api/tasks/summary"

# API Email Ingestion
echo -e "\n${CYAN}=== API Email Ingestion ===${NC}"
test_endpoint "GET logs" "GET" "/api/email-ingestion/logs"

# API Other
echo -e "\n${CYAN}=== Autres APIs ===${NC}"
test_endpoint "Document types" "GET" "/api/document-types"
test_endpoint "Classification fields" "GET" "/api/classification-fields"
test_endpoint "User groups" "GET" "/api/user-groups"
test_endpoint "Notes" "GET" "/api/notes"
test_endpoint "OnlyOffice status" "GET" "/api/onlyoffice/status"

# Summary
cat >> "$REPORT" << EOF

---

## Resume

- **Total tests:** $TOTAL
- **Reussis:** $PASSED
- **Echecs:** $FAILED
- **Taux de reussite:** $(echo "scale=1; $PASSED * 100 / $TOTAL" | bc)%
EOF

echo -e "\n${CYAN}========================================${NC}"
echo -e "${CYAN}   Resume${NC}"
echo -e "${CYAN}========================================${NC}"
echo -e "Total: $TOTAL tests"
echo -e "${GREEN}Reussis: $PASSED${NC}"
echo -e "${RED}Echecs: $FAILED${NC}"
echo -e "Rapport: $REPORT"

# Cleanup
rm -f $COOKIE_FILE
