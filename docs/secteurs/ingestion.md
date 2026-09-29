# Secteur INGESTION — dossier, adresse, agent, découpe, lecture

Agent : Claude Code · Juge : GLM · Lot en cours : V2-02

## Mission
Deux entrées, légères : **un dossier d'ingestion** et **une adresse d'ingestion** (boîte IMAP relevée, pièces jointes
vers la même chaîne). ClearMyMails reste le pont lourd (Gmail, Microsoft 365…) — secteur Jonctions.
Chaîne : dépôt → découpe (`cmd4_ingest`, moteur partagé avec CMD v4, dépôt ClearMyDocs) → lecture → secteur Classement →
secteur Socle (rangement). Le dossier d'ingestion n'est jamais montré aux utilisateurs : seulement le bilan.

## Chemins
`src/Ingestion/`, `agent/` (agent d'ingestion Python, appel sortant), `tests/php/Ingestion/`, `tests/corpus/`, `tools/banc/`

## Lot V2-02 — porte
1. Lot `courrier-100p` (repris de GEDv1 avec sa vérité) : 53/53 exacts, 0 page perdue, 0 doublon.
2. Chaque nouveau lot du corpus (photos, 90°/180°, versos blancs, QR multipages, DE/IT/EN, pages mélangées, bureautique,
   300 pages, PDF protégé) a sa `verite.json` et passe la même porte.
3. Adresse d'ingestion : un courriel de test avec 3 pièces jointes → 3 documents, 0 perdu, 0 doublon au second relevé.
4. Tout en jobs courts avec point de reprise ; une tâche tuée reprend sans rien perdre.

## Reprendre de GEDv1
`app/Services/Ingest/Cmd4IngestClient.php` (stderr en fichier : l'interblocage du 27-09), `PDFSplitterService::compterPagesNatif`,
`tools/banc/rejouer-ingestion.php` et `mesurer-decoupe.py` (mesure contre vérité, pages blanches neutres),
`tests/corpus-banc/courrier-100p/`, la reprise des jobs abandonnés (`TaskService::processClassificationQueue`).
