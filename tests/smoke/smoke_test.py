#!/usr/bin/env python3
"""
K-Docs Smoke Test V2 - Tests fonctionnels complets
Version améliorée avec tests CRUD, API, et workflows

Prérequis:
    pip install selenium webdriver-manager pillow requests

Usage:
    python smoke_test_v2.py
    python smoke_test_v2.py --base-url http://localhost/kdocs
    python smoke_test_v2.py --headless --full
"""

import os
import sys
import json
import time
import argparse
import requests
from datetime import datetime
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.common.exceptions import (
    TimeoutException, 
    NoSuchElementException,
    ElementClickInterceptedException,
)
from webdriver_manager.chrome import ChromeDriverManager


class KDocsSmokeTestV2:
    """Smoke test V2 avec tests fonctionnels complets"""
    
    def __init__(self, base_url="http://localhost/kdocs", headless=False, output_dir=None, full_test=False):
        self.base_url = base_url.rstrip('/')
        self.headless = headless
        self.full_test = full_test
        self.session_cookie = None
        
        if output_dir is None:
            output_dir = Path(__file__).parent / "smoke_test_results_v2"
        self.output_dir = Path(output_dir)
        self.output_dir.mkdir(exist_ok=True)
        
        self.driver = None
        self.results = {
            "timestamp": datetime.now().isoformat(),
            "base_url": self.base_url,
            "version": "2.0",
            "tests": {
                "pages": [],
                "api": [],
                "crud": [],
                "chat": [],
                "search": [],
                "performance": []
            },
            "summary": {
                "total": 0,
                "passed": 0,
                "failed": 0,
                "skipped": 0
            }
        }

    # =========================================
    # PAGES À TESTER
    # =========================================
    
    PAGES = [
        {"name": "Login", "path": "/login", "auth": False},
        {"name": "Dashboard", "path": "/", "auth": True},
        {"name": "Documents", "path": "/documents", "auth": True},
        {"name": "Document Detail", "path": "/documents/1", "auth": True, "optional": True},
        {"name": "Chat IA", "path": "/chat", "auth": True},
        {"name": "Mes Tâches", "path": "/mes-taches", "auth": True},
        {"name": "Indexation", "path": "/admin/indexing", "auth": True},
        {"name": "Fichiers à valider", "path": "/admin/consume", "auth": True},
        {"name": "Étiquettes", "path": "/admin/tags", "auth": True},
        {"name": "Correspondants", "path": "/admin/correspondents", "auth": True},
        {"name": "Types document", "path": "/admin/document-types", "auth": True},
        {"name": "Champs perso", "path": "/admin/custom-fields", "auth": True},
        {"name": "Chemins stockage", "path": "/admin/storage-paths", "auth": True},
        {"name": "Champs classif", "path": "/admin/classification-fields", "auth": True},
        {"name": "Workflows", "path": "/admin/workflows", "auth": True},
        {"name": "Webhooks", "path": "/admin/webhooks", "auth": True},
        {"name": "Audit Logs", "path": "/admin/audit-logs", "auth": True},
        {"name": "Export/Import", "path": "/admin/export-import", "auth": True},
        {"name": "Paramètres", "path": "/admin/settings", "auth": True},
        {"name": "Stats API", "path": "/admin/api-usage", "auth": True},
        {"name": "Utilisateurs", "path": "/admin/users", "auth": True},
        {"name": "Groupes", "path": "/admin/user-groups", "auth": True},
        {"name": "Health", "path": "/health", "auth": False},
    ]

    # =========================================
    # ENDPOINTS API À TESTER
    # =========================================
    
    API_ENDPOINTS = [
        {"name": "Health", "method": "GET", "path": "/health", "auth": False, "expect_json": True},
        {"name": "Documents List", "method": "GET", "path": "/api/documents", "auth": True, "expect_json": True},
        {"name": "Documents Count", "method": "GET", "path": "/api/documents?per_page=1", "auth": True, "expect_json": True},
        {"name": "Tags List", "method": "GET", "path": "/api/tags", "auth": True, "expect_json": True},
        {"name": "Correspondents", "method": "GET", "path": "/api/correspondents", "auth": True, "expect_json": True},
        {"name": "Document Types", "method": "GET", "path": "/api/document-types", "auth": True, "expect_json": True},
        {"name": "Search", "method": "GET", "path": "/api/search?q=test", "auth": True, "expect_json": True},
        {"name": "Chat Conversations", "method": "GET", "path": "/api/chat/conversations", "auth": True, "expect_json": True},
    ]

    # =========================================
    # SETUP / TEARDOWN
    # =========================================

    def setup_driver(self):
        options = Options()
        if self.headless:
            options.add_argument("--headless=new")
        options.add_argument("--window-size=1920,1080")
        options.add_argument("--disable-gpu")
        options.add_argument("--no-sandbox")
        options.set_capability("goog:loggingPrefs", {"browser": "ALL"})
        
        service = Service(ChromeDriverManager().install())
        self.driver = webdriver.Chrome(service=service, options=options)
        self.driver.implicitly_wait(5)
        print("✓ Chrome initialisé")

    def teardown_driver(self):
        if self.driver:
            self.driver.quit()

    # =========================================
    # AUTHENTIFICATION
    # =========================================

    def login(self, username="root", password=""):
        print(f"\n🔐 Connexion ({username})...")
        try:
            self.driver.get(f"{self.base_url}/login")
            time.sleep(1)
            
            self.driver.find_element(By.CSS_SELECTOR, "input[name='username']").send_keys(username)
            self.driver.find_element(By.CSS_SELECTOR, "input[name='password']").send_keys(password)
            self.driver.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
            
            time.sleep(2)
            
            if "/login" not in self.driver.current_url:
                # Récupérer le cookie de session pour les tests API
                cookies = self.driver.get_cookies()
                for cookie in cookies:
                    if 'session' in cookie['name'].lower() or 'kdocs' in cookie['name'].lower():
                        self.session_cookie = {cookie['name']: cookie['value']}
                
                print("✓ Connecté")
                return True
            
            print("✗ Échec connexion")
            return False
            
        except Exception as e:
            print(f"✗ Erreur: {e}")
            return False

    # =========================================
    # TESTS PAGES
    # =========================================

    def test_all_pages(self):
        print("\n" + "="*50)
        print("📄 TESTS PAGES")
        print("="*50)
        
        for page in self.PAGES:
            result = self._test_page(page)
            self.results["tests"]["pages"].append(result)
            self._update_summary(result["status"])

    def _test_page(self, page):
        result = {
            "name": page["name"],
            "path": page["path"],
            "status": "pending",
            "load_time_ms": 0,
            "errors": [],
            "screenshot": None
        }
        
        try:
            start = time.time()
            self.driver.get(f"{self.base_url}{page['path']}")
            
            WebDriverWait(self.driver, 10).until(
                lambda d: d.execute_script("return document.readyState") == "complete"
            )
            
            result["load_time_ms"] = int((time.time() - start) * 1000)
            time.sleep(0.5)
            
            # Vérifier erreurs
            errors = self._check_errors()
            result["errors"] = errors
            
            # Screenshot
            safe_name = page["path"].replace("/", "_").strip("_") or "home"
            result["screenshot"] = self._screenshot(f"page_{safe_name}")
            
            if errors and not page.get("optional"):
                result["status"] = "failed"
                print(f"  ✗ {page['name']}: {len(errors)} erreur(s)")
            else:
                result["status"] = "passed"
                print(f"  ✓ {page['name']} ({result['load_time_ms']}ms)")
                
        except TimeoutException:
            result["status"] = "failed" if not page.get("optional") else "skipped"
            result["errors"].append("Timeout 10s")
            print(f"  ⊘ {page['name']}: Timeout")
            
        except Exception as e:
            result["status"] = "failed" if not page.get("optional") else "skipped"
            result["errors"].append(str(e))
            print(f"  ✗ {page['name']}: {e}")
        
        return result

    # =========================================
    # TESTS API
    # =========================================

    def test_all_api(self):
        print("\n" + "="*50)
        print("🔌 TESTS API")
        print("="*50)
        
        for endpoint in self.API_ENDPOINTS:
            result = self._test_api(endpoint)
            self.results["tests"]["api"].append(result)
            self._update_summary(result["status"])

    def _test_api(self, endpoint):
        result = {
            "name": endpoint["name"],
            "path": endpoint["path"],
            "method": endpoint["method"],
            "status": "pending",
            "status_code": None,
            "response_time_ms": 0,
            "valid_json": False,
            "error": None
        }
        
        try:
            url = f"{self.base_url}{endpoint['path']}"
            cookies = self.session_cookie if endpoint.get("auth") else None
            
            start = time.time()
            
            if endpoint["method"] == "GET":
                resp = requests.get(url, cookies=cookies, timeout=10)
            elif endpoint["method"] == "POST":
                resp = requests.post(url, cookies=cookies, json={}, timeout=10)
            else:
                resp = requests.request(endpoint["method"], url, cookies=cookies, timeout=10)
            
            result["response_time_ms"] = int((time.time() - start) * 1000)
            result["status_code"] = resp.status_code
            
            if endpoint.get("expect_json"):
                try:
                    resp.json()
                    result["valid_json"] = True
                except:
                    result["valid_json"] = False
            
            if resp.status_code < 400 and (not endpoint.get("expect_json") or result["valid_json"]):
                result["status"] = "passed"
                print(f"  ✓ {endpoint['name']}: {resp.status_code} ({result['response_time_ms']}ms)")
            else:
                result["status"] = "failed"
                print(f"  ✗ {endpoint['name']}: {resp.status_code}")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ {endpoint['name']}: {e}")
        
        return result

    # =========================================
    # TESTS CRUD
    # =========================================

    def test_crud_operations(self):
        if not self.full_test:
            print("\n⊘ CRUD tests skipped (use --full)")
            return
            
        print("\n" + "="*50)
        print("📝 TESTS CRUD")
        print("="*50)
        
        # Test création tag
        result = self._test_crud_tag()
        self.results["tests"]["crud"].append(result)
        self._update_summary(result["status"])
        
        # Test création correspondant
        result = self._test_crud_correspondent()
        self.results["tests"]["crud"].append(result)
        self._update_summary(result["status"])

    def _test_crud_tag(self):
        result = {"name": "CRUD Tag", "status": "pending", "steps": []}
        
        try:
            # CREATE
            self.driver.get(f"{self.base_url}/admin/tags")
            time.sleep(1)
            
            # Chercher bouton créer
            create_btn = self.driver.find_element(By.XPATH, "//button[contains(text(), 'Créer') or contains(text(), 'Ajouter') or contains(text(), 'Nouveau')]")
            create_btn.click()
            time.sleep(1)
            
            # Remplir formulaire
            tag_name = f"Test_{datetime.now().strftime('%H%M%S')}"
            name_input = self.driver.find_element(By.CSS_SELECTOR, "input[name='name']")
            name_input.send_keys(tag_name)
            
            # Soumettre
            submit = self.driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
            submit.click()
            time.sleep(2)
            
            # Vérifier création
            if tag_name in self.driver.page_source:
                result["steps"].append({"create": "passed"})
                result["status"] = "passed"
                print(f"  ✓ CRUD Tag: Créé '{tag_name}'")
            else:
                result["steps"].append({"create": "failed"})
                result["status"] = "failed"
                print(f"  ✗ CRUD Tag: Création échouée")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ CRUD Tag: {e}")
        
        return result

    def _test_crud_correspondent(self):
        result = {"name": "CRUD Correspondent", "status": "pending", "steps": []}
        
        try:
            self.driver.get(f"{self.base_url}/admin/correspondents")
            time.sleep(1)
            
            create_btn = self.driver.find_element(By.XPATH, "//button[contains(text(), 'Créer') or contains(text(), 'Ajouter') or contains(text(), 'Nouveau')]")
            create_btn.click()
            time.sleep(1)
            
            corr_name = f"Test Corr {datetime.now().strftime('%H%M%S')}"
            name_input = self.driver.find_element(By.CSS_SELECTOR, "input[name='name']")
            name_input.send_keys(corr_name)
            
            submit = self.driver.find_element(By.CSS_SELECTOR, "button[type='submit']")
            submit.click()
            time.sleep(2)
            
            if corr_name in self.driver.page_source:
                result["status"] = "passed"
                print(f"  ✓ CRUD Correspondent: Créé '{corr_name}'")
            else:
                result["status"] = "failed"
                print(f"  ✗ CRUD Correspondent: Création échouée")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ CRUD Correspondent: {e}")
        
        return result

    # =========================================
    # TESTS CHAT IA
    # =========================================

    def test_chat_ia(self):
        print("\n" + "="*50)
        print("🤖 TESTS CHAT IA")
        print("="*50)
        
        queries = [
            {"query": "Combien de documents ai-je ?", "expect": "document"},
            {"query": "compte le mot juge", "expect": "fois|apparaît|occurrence"},
        ]
        
        for q in queries:
            result = self._test_chat_query(q)
            self.results["tests"]["chat"].append(result)
            self._update_summary(result["status"])

    def _test_chat_query(self, test):
        result = {
            "query": test["query"],
            "status": "pending",
            "response": None,
            "matched_expect": False
        }
        
        try:
            self.driver.get(f"{self.base_url}/chat")
            time.sleep(2)
            
            # Nouvelle conversation si nécessaire
            try:
                new_btn = self.driver.find_element(By.ID, "new-chat-btn")
                new_btn.click()
                time.sleep(1)
            except:
                pass
            
            # Saisir la question
            input_field = self.driver.find_element(By.CSS_SELECTOR, "#chat-input, input[type='text']")
            input_field.clear()
            input_field.send_keys(test["query"])
            input_field.send_keys(Keys.RETURN)
            
            # Attendre la réponse (max 30s pour l'IA)
            time.sleep(5)
            
            for _ in range(25):
                try:
                    loading = self.driver.find_element(By.ID, "loading-indicator")
                    if loading:
                        time.sleep(1)
                        continue
                except NoSuchElementException:
                    break
            
            time.sleep(2)
            
            # Récupérer la réponse
            messages = self.driver.find_elements(By.CSS_SELECTOR, ".bg-gray-100")
            if messages:
                response_text = messages[-1].text
                result["response"] = response_text[:200]
                
                import re
                if re.search(test["expect"], response_text, re.IGNORECASE):
                    result["matched_expect"] = True
                    result["status"] = "passed"
                    print(f"  ✓ Chat: '{test['query'][:30]}...' → OK")
                else:
                    result["status"] = "failed"
                    print(f"  ✗ Chat: '{test['query'][:30]}...' → Réponse inattendue")
            else:
                result["status"] = "failed"
                print(f"  ✗ Chat: Pas de réponse")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ Chat: {e}")
        
        return result

    # =========================================
    # TESTS RECHERCHE
    # =========================================

    def test_search(self):
        print("\n" + "="*50)
        print("🔍 TESTS RECHERCHE")
        print("="*50)
        
        searches = [
            {"query": "facture", "min_results": 0},
            {"query": "contrat", "min_results": 0},
        ]
        
        for s in searches:
            result = self._test_search(s)
            self.results["tests"]["search"].append(result)
            self._update_summary(result["status"])

    def _test_search(self, test):
        result = {
            "query": test["query"],
            "status": "pending",
            "results_count": 0
        }
        
        try:
            self.driver.get(f"{self.base_url}/documents?q={test['query']}")
            time.sleep(2)
            
            # Compter les résultats
            rows = self.driver.find_elements(By.CSS_SELECTOR, "table tbody tr, .document-card, .document-item")
            result["results_count"] = len(rows)
            
            if result["results_count"] >= test["min_results"]:
                result["status"] = "passed"
                print(f"  ✓ Search '{test['query']}': {result['results_count']} résultats")
            else:
                result["status"] = "failed"
                print(f"  ✗ Search '{test['query']}': {result['results_count']} < {test['min_results']} attendus")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ Search: {e}")
        
        return result

    # =========================================
    # TESTS PERFORMANCE
    # =========================================

    def test_performance(self):
        print("\n" + "="*50)
        print("⚡ TESTS PERFORMANCE")
        print("="*50)
        
        thresholds = [
            {"path": "/", "max_ms": 2000, "name": "Dashboard"},
            {"path": "/documents", "max_ms": 3000, "name": "Documents"},
            {"path": "/api/documents?per_page=50", "max_ms": 1000, "name": "API Documents"},
        ]
        
        for t in thresholds:
            result = self._test_perf(t)
            self.results["tests"]["performance"].append(result)
            self._update_summary(result["status"])

    def _test_perf(self, test):
        result = {
            "name": test["name"],
            "path": test["path"],
            "status": "pending",
            "load_time_ms": 0,
            "threshold_ms": test["max_ms"]
        }
        
        try:
            start = time.time()
            
            if test["path"].startswith("/api"):
                resp = requests.get(f"{self.base_url}{test['path']}", cookies=self.session_cookie, timeout=30)
                result["load_time_ms"] = int((time.time() - start) * 1000)
            else:
                self.driver.get(f"{self.base_url}{test['path']}")
                WebDriverWait(self.driver, 30).until(
                    lambda d: d.execute_script("return document.readyState") == "complete"
                )
                result["load_time_ms"] = int((time.time() - start) * 1000)
            
            if result["load_time_ms"] <= test["max_ms"]:
                result["status"] = "passed"
                print(f"  ✓ Perf {test['name']}: {result['load_time_ms']}ms (≤{test['max_ms']}ms)")
            else:
                result["status"] = "failed"
                print(f"  ✗ Perf {test['name']}: {result['load_time_ms']}ms (>{test['max_ms']}ms)")
                
        except Exception as e:
            result["status"] = "failed"
            result["error"] = str(e)
            print(f"  ✗ Perf {test['name']}: {e}")
        
        return result

    # =========================================
    # UTILITAIRES
    # =========================================

    def _check_errors(self):
        errors = []
        
        # Console errors
        try:
            for log in self.driver.get_log("browser"):
                if log["level"] in ["SEVERE", "ERROR"]:
                    errors.append(f"Console: {log['message'][:100]}")
        except:
            pass
        
        # Page errors
        try:
            source = self.driver.page_source.lower()
            for indicator in ["fatal error", "exception", "404 not found", "500 internal"]:
                if indicator in source:
                    errors.append(f"Page: {indicator}")
        except:
            pass
        
        return errors

    def _screenshot(self, name):
        filename = f"{name}_{datetime.now().strftime('%H%M%S')}.png"
        self.driver.save_screenshot(str(self.output_dir / filename))
        return filename

    def _update_summary(self, status):
        self.results["summary"]["total"] += 1
        if status == "passed":
            self.results["summary"]["passed"] += 1
        elif status == "failed":
            self.results["summary"]["failed"] += 1
        else:
            self.results["summary"]["skipped"] += 1

    # =========================================
    # EXECUTION
    # =========================================

    def run(self):
        print("=" * 60)
        print("K-DOCS SMOKE TEST V2")
        print(f"URL: {self.base_url}")
        print(f"Mode: {'Full' if self.full_test else 'Standard'}")
        print("=" * 60)
        
        try:
            self.setup_driver()
            
            if not self.login():
                print("⚠️ Connexion échouée")
            
            # Tests
            self.test_all_pages()
            self.test_all_api()
            self.test_chat_ia()
            self.test_search()
            self.test_performance()
            self.test_crud_operations()
            
        finally:
            self.teardown_driver()
        
        self._generate_report()
        return self.results

    def _generate_report(self):
        # JSON
        with open(self.output_dir / "report.json", "w") as f:
            json.dump(self.results, f, indent=2, ensure_ascii=False)
        
        # Markdown
        s = self.results["summary"]
        with open(self.output_dir / "report.md", "w") as f:
            f.write(f"# K-Docs Smoke Test V2\n\n")
            f.write(f"**Date**: {self.results['timestamp']}\n\n")
            f.write(f"## Résumé\n\n")
            f.write(f"| Total | Passed | Failed | Skipped |\n")
            f.write(f"|-------|--------|--------|--------|\n")
            f.write(f"| {s['total']} | ✅ {s['passed']} | ❌ {s['failed']} | ⊘ {s['skipped']} |\n\n")
            
            pct = (s['passed'] / s['total'] * 100) if s['total'] > 0 else 0
            f.write(f"**Score**: {pct:.1f}%\n\n")
            
            for category, tests in self.results["tests"].items():
                if tests:
                    f.write(f"## {category.upper()}\n\n")
                    for t in tests:
                        icon = "✅" if t.get("status") == "passed" else "❌" if t.get("status") == "failed" else "⊘"
                        name = t.get("name") or t.get("query") or t.get("path", "")
                        f.write(f"- {icon} {name}\n")
                    f.write("\n")
        
        print("\n" + "=" * 60)
        print(f"📊 RÉSULTAT: {s['passed']}/{s['total']} ({s['passed']/s['total']*100:.0f}%)")
        print(f"📁 Rapport: {self.output_dir}")
        print("=" * 60)


def main():
    parser = argparse.ArgumentParser(description="K-Docs Smoke Test V2")
    parser.add_argument("--base-url", default="http://localhost/kdocs")
    parser.add_argument("--headless", action="store_true")
    parser.add_argument("--full", action="store_true", help="Tests CRUD complets")
    parser.add_argument("--output", default=None)
    
    args = parser.parse_args()
    
    tester = KDocsSmokeTestV2(
        base_url=args.base_url,
        headless=args.headless,
        output_dir=args.output,
        full_test=args.full
    )
    
    results = tester.run()
    sys.exit(1 if results["summary"]["failed"] > 0 else 0)


if __name__ == "__main__":
    main()
