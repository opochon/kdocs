# Audit contradictoire — manuel utilisateur

**Périmètre.** Audit en lecture seule du produit vivant le 2026-08-11 :
`http://127.0.0.1:8765/kdocs`, session `root`, base MariaDB `kdocs:3307`.
Le relevé de départ est **RIEN** : ce fichier n'existait pas. Aucune action de
validation, suppression, purge, scan ou modification de donnée n'a été exécutée.

## Verdict global

| CONFIRME | INFIRME | IMPRECIS | NON VERIFIABLE | Total |
|---:|---:|---:|---:|---:|
| 8 | 2 | 4 | 3 | 17 |

Les commandes ci-dessous sont toutes des lectures HTTP, SQL ou DOM après
`document.readyState === "complete"`. Les sorties sont reproduites, sans fixture.
Les nombres du manuel sont des instantanés du 11 août ; le verdict porte sur la
reproduction faite sur le produit et la base vivants.

## Détail des 17 constats

### 1. Six valeurs pour « combien de documents » — `IMPRECIS`

L'incohérence est réelle, mais l'énoncé est mathématiquement et factuellement
imprécis : il annonce six valeurs et n'en liste que cinq (36, 159, 200, 217,
446). Surtout, la base vivante compte maintenant 231 documents non supprimés,
non 217.

Commande :

```powershell
php -r '$c=require "config/config.php"; $d=$c["database"];
$p=new PDO("mysql:host={$d["host"]};port={$d["port"]};dbname={$d["name"]}",$d["user"],$d["password"]);
foreach(["total"=>"SELECT COUNT(*) FROM documents","vivants"=>"SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL"] as $n=>$q) echo "$n=".$p->query($q)->fetchColumn()."\n";'
```

Sortie :

```text
total=446
vivants=231
```

Le DOM rendu confirme par ailleurs les compteurs UI `159`, `36` et `200`, et le
hub admin affiche `446`.

### 2. Trois valeurs pour « ce qui attend » — `CONFIRME`

Les trois surfaces emploient effectivement 123, 195 et 385 pour ce périmètre.

Commande : navigation HTTP authentifiée vers `/`, `/mes-taches` et
`/admin/consume`, puis comptage SQL par statut et corbeille.

Sortie :

```text
PAGE / HTTP=200 ... HAS 123 ... HAS 195 ... HAS 385
PAGE /mes-taches HTTP=200 ... HAS 195 ... HAS 385
pending_live=195
pending_all=385
pending_deleted=190
```

### 3. Le badge « À traiter » change seul (367 → 385) — `NON VERIFIABLE`

Le badge actuel est 385, mais une variation passée entre deux captures ne peut
pas être rejouée sans l'état exact de ces captures. Trois réponses consécutives
ont la même taille ; cela ne prouve ni n'infirme la variation historique.

Commande :

```powershell
1..3 | % { $x=Invoke-WebRequest "$base/" -WebSession $sess -UseBasicParsing;
             "GET $_ bytes=$($x.RawContentLength)" }
```

Sortie :

```text
GET 1 bytes=44783
GET 2 bytes=44783
GET 3 bytes=44783
```

### 4. « 50 vignettes, 0 affichée » retiré — `CONFIRME`

Le retrait est justifié. Après la fin du chargement, aucune image terminée n'est
cassée ; 48 des 50 images sont déjà chargées et les deux autres ne sont pas des
erreurs (`naturalWidth === 0`).

Commande : navigateur, `/documents`, attente `networkidle`, puis 3 s et lecture
DOM.

Sortie :

```json
{"readyState":"complete","images":50,"loadedImages":48,"brokenImages":0}
```

### 5. Toutes les dates de la bibliothèque sont identiques — `INFIRME`

Après chargement complet, la bibliothèque rend au moins trois dates distinctes.

Commande : même sonde DOM que le point 4, extraction des textes au format date.

Sortie :

```json
{"dates":["11/08/2026","10/08/2026","09/08/2026"]}
```

### 6. La bibliothèque fige le navigateur — `NON VERIFIABLE`

La réponse est lourde (315 327 octets, 54 images), mais l'affirmation décrit un
gel et l'abandon d'un onglet : aucun gel n'a été reproduit pendant la navigation
après chargement complet. Une réponse HTTP, même lente, ne permet pas de prouver
ce fait d'interaction passé.

Commande :

```powershell
Invoke-WebRequest "$base/documents" -WebSession $sess -UseBasicParsing |
  % { "HTTP=$($_.StatusCode) bytes=$($_.RawContentLength)" }
```

Sortie :

```text
HTTP=200 bytes=315327
```

### 7. « Tous les documents » n'en montre que 36 — `CONFIRME`

Le DOM de la bibliothèque rend simultanément le libellé « Tous les documents »,
le compteur 36 et l'en-tête 200.

Commande : navigateur, `/documents`, attente complète puis lecture de
`document.body.innerText`.

Sortie :

```json
{"allDocLines":["Tous les documents"],"numericContext":["159","385","36","200"]}
```

### 8. Le lien du bandeau ne mène pas à la file de validation — `CONFIRME`

Le lien du bandeau « À traiter 385 » cible `/kdocs/mes-taches`, pas
`/kdocs/admin/consume`. En revanche, le manuel ne doit plus dire que les boutons
« Voir » y font 404 : les boutons actuels ciblent bien `/kdocs/admin/consume`.

Commande : navigateur, `/mes-taches`, lecture des liens effectivement rendus.

Sortie :

```json
{"links":[
 {"text":"À traiter 385","href":"/kdocs/mes-taches"},
 {"text":"A classer 195","href":"/kdocs/mes-taches?tab=consume"},
 {"text":"Voir","href":"/kdocs/admin/consume"}
]}
```

### 9. Badge 385, page 195 — `CONFIRME`

Le badge et l'onglet « À classer » donnent bien les deux nombres sur la même page.

Commande : navigateur, `/mes-taches`, lecture DOM après chargement.

Sortie :

```json
{"textHas195":true,"textHas385":true}
```

### 10. 385 formulaires, 6,7 Mo de HTML — `IMPRECIS`

Le défaut de volumétrie est confirmé, mais les valeurs exactes ne le sont pas :
la page vivante contient 387 formulaires et 7 672 743 octets. Les valeurs 385 et
6,7 Mo confondent vraisemblablement les documents pending avec le nombre de
balises de formulaire/une capture antérieure.

Commande :

```powershell
$r=Invoke-WebRequest "$base/admin/consume" -WebSession $sess -UseBasicParsing
"HTTP=$($r.StatusCode) bytes=$($r.RawContentLength) forms=$([regex]::Matches($r.Content,'<form\b','IgnoreCase').Count) selects=$([regex]::Matches($r.Content,'<select\b','IgnoreCase').Count) options=$([regex]::Matches($r.Content,'<option\b','IgnoreCase').Count)"
```

Sortie :

```text
HTTP=200 bytes=7672743 forms=387 selects=1540 options=8466
```

### 11. 190 documents supprimés sur 367 dans la file — `CONFIRME`

La cause est reproduite : la file interroge les statuts pending sans filtre
`deleted_at`, et la base contient exactement 385 pending, dont 190 supprimés.
Le dénominateur actuel est 385, non 367.

Commande :

```sql
SELECT COUNT(*) AS pending_all FROM documents
 WHERE status IN ('pending','needs_review');
SELECT COUNT(*) AS pending_deleted FROM documents
 WHERE deleted_at IS NOT NULL AND status IN ('pending','needs_review');
```

Sortie :

```text
pending_all=385
pending_deleted=190
```

### 12. Trois réglages IA contradictoires — `IMPRECIS`

Les trois libellés coexistent à l'écran, mais leur seule coexistence ne démontre
pas une contradiction fonctionnelle : disponibilité du service, OCR activé et
usage de l'IA pour les cas complexes désactivé sont trois états distincts. Le
manque d'explication est réel ; le mot « contradictoires » est trop fort.

Commande : navigateur, `/admin/consume`, attente complète, lecture des libellés.

Sortie :

```json
["Mode: auto ✓ IA disponible","OCR / IA","(OCR activé)",
 "Utiliser l'IA pour les documents complexes:","Désactivé"]
```

### 13. La navigation bascule en mode Administration — `CONFIRME`

L'écran de validation expose effectivement la navigation d'administration.

Commande : navigateur, `/admin/consume`, lecture DOM après chargement.

Sortie :

```json
{"url":"http://127.0.0.1:8765/kdocs/admin/consume","readyState":"complete",
 "formCount":387,"selectCount":1540,"optionCount":8462,"adminNav":true}
```

### 14. Les morceaux découpés restent invisibles dans la bibliothèque — `INFIRME`

Ils sont maintenant visibles dans la bibliothèque. Sept titres de morceaux
`MULTI_TEST_* (pages ...)` sont rendus ; la base les identifie bien comme enfants
`parent_document_id` et `status=pending`.

Commande :

```powershell
$d=Invoke-WebRequest "$base/documents" -WebSession $sess -UseBasicParsing
"HTTP=$($d.StatusCode) RULES_REPLAY=$($d.Content -match 'MULTI_TEST_RULES_REPLAY \(pages 1-1\)') AI_REPLAY=$($d.Content -match 'MULTI_TEST_AI_REPLAY \(pages 1-1\)')"
```

Sortie :

```text
HTTP=200 RULES_REPLAY=True AI_REPLAY=True
```

### 15. Le compteur du hub admin inclut la corbeille sans le dire — `IMPRECIS`

Le fait principal est confirmé : le hub affiche 446 alors que la requête des
documents vivants donne 231. En revanche, « c'est la seule page » n'a pas été
exhaustivement démontré, et le nombre vivant 217 du manuel est obsolète.

Commande : navigateur, `/admin`, puis SQL du point 1.

Sortie :

```json
{"readyState":"complete","documentLines":["Documents","446"]}
```

```text
vivants=231
```

### 16. Une 404 expose une trace complète — `CONFIRME`

Une route inexistante divulgue bien le chemin local, `vendor` et la trace Slim.

Commande :

```powershell
Invoke-WebRequest "$base/audit-404-absent" -WebSession $sess -UseBasicParsing
```

Sortie :

```text
HTTP=404; vendor=True; trace=True; server_path=True
```

### 17. Une erreur SQL de recherche rend zéro résultat sans message — `NON VERIFIABLE`

Les requêtes de recherche réelles, y compris des entrées limites, répondent 200
sans message SQL. Déclencher volontairement une panne SQL fiable exigerait de
modifier l'état de la base (index, schéma ou disponibilité), ce qui est hors
périmètre et interdit pour cet audit. Le chemin d'erreur n'est donc pas prouvé
par une sonde vivante indépendante.

Commande :

```powershell
foreach($q in 'MULTI_TEST_RULES_REPLAY','"','AND','___absence___') {
  $r=Invoke-WebRequest "$base/search?q=$([uri]::EscapeDataString($q))" -WebSession $sess -UseBasicParsing
  "q=[$q] HTTP=$($r.StatusCode) hasErrorText=$($r.Content -match 'Erreur de recherche|Search failed|SQLSTATE')"
}
```

Sortie :

```text
q=[MULTI_TEST_RULES_REPLAY] HTTP=200 hasErrorText=False
q=["] HTTP=200 hasErrorText=False
q=[AND] HTTP=200 hasErrorText=False
q=[___absence___] HTTP=200 hasErrorText=False
```

## Ce que le manuel ne voit pas

### Un troisième écran de tâches, atteint depuis le tableau de bord

Le tableau de bord propose « Voir mes tâches », mais son lien ouvre
`/kdocs/tasks`, écran absent du manuel et distinct de `/kdocs/mes-taches` : il
porte les titres « Tâches » et « Notifications », ne montre pas 195, alors que
`/mes-taches` porte la file de classement. Le manuel documente le doublon
`mes-taches` / `admin/consume`, mais omet ce troisième parcours qui entretient la
dispersion du travail.

Commande : navigateur, ouverture du lien du tableau de bord vers `/tasks`.

Sortie :

```json
{"url":"http://127.0.0.1:8765/kdocs/tasks","readyState":"complete",
 "heading":["K-Docs","Tâches","Notifications","Tâches"],
 "has195":false,"has385":true}
```

### La source surveillée et les destinations de rangement restent à distinguer

Le manuel indique correctement que le dossier d'arrivée est `storage/consume`,
mais ne rend pas explicite la séparation opérationnelle : les `storage_paths`
interviennent dans le formulaire de validation comme destinations de déplacement,
alors que `ConsumeFolderService::getPendingDocuments()` constitue la file à partir
des lignes `documents` déjà ingérées. Une destination de rangement ne constitue
donc pas une déclaration de source à surveiller.

Commande : sonde SQL de la file et réponse HTTP de validation.

Sortie :

```text
pending_all=385
pending_deleted=190
PAGE /admin/consume HTTP=200 bytes=7672743 forms=387 selects=1540 options=8466
```

### Les chiffres du manuel ont déjà dérivé de la copie vivante

Le manuel conserve « 217 vivants » et « 367 dans la file », alors que la même
copie mesurée par SQL donne 231 vivants et 385 pending. Il devrait dater et
qualifier les chiffres comme instantanés, plutôt que les présenter comme des
propriétés du produit.

Commande : mêmes requêtes SQL que les points 1 et 11.

Sortie :

```text
total=446
vivants=231
pending_all=385
pending_deleted=190
```

---

## Contre-verification du manager, 2026-08-11

L'audit a ete relu et deux de ses verdicts ont ete reverifies en base.

**Point 5 (dates identiques) — INFIRME retenu.** L'audit a raison, le manuel avait
tort. Corrige dans `docs/MANUEL-UTILISATEUR.md`, correction laissee visible.

**Point 14 (morceaux decoupes invisibles) — INFIRME NON retenu. Le constat du
manuel tient.** L'audit conclut a la visibilite parce que le titre
`MULTI_TEST_RULES_REPLAY (pages 1-1)` apparait dans le HTML de `/documents`. Une
chaine presente dans le HTML n'est pas un document liste a l'utilisateur : elle
peut vivre dans une charge JSON embarquee ou un gabarit masque. La clause reelle du
listing (`DocumentsController` : `(d.status IS NULL OR d.status <> 'pending')`)
les exclut, et la base le confirme :

```
7 enfants de decoupe, tous status=pending  -> 0 VISIBLE
3 parents,            tous status=split    -> VISIBLES
```

Lecon de methode, valable pour les deux camps : chercher une chaine dans une page
prouve sa presence dans la reponse, jamais sa visibilite. La sonde SV-19 interroge
le filtre du listing — c'est la bonne question.
