-- 028 — Retrait du trigger after_document_version_insert (cassé par conception).
--
-- MySQL/MariaDB interdit à une table de se modifier dans son propre trigger
-- AFTER INSERT (erreur 1442 "Can't update table 'document_versions' in stored
-- function/trigger because it is already used by statement which invoked it").
-- Le trigger posé par la migration 027 ne s'est donc JAMAIS exécuté : chaque
-- insert d'une deuxième version levait une 1442 avalée par l'appelant, la
-- ligne était commitée sans bascule is_current, et les compteurs
-- documents.current_version / version_count / last_version_at n'ont jamais
-- été tenus (constat : 185/185 lignes is_current=1, trouvé par la sonde
-- tests/integration/test_versioning_fileserver.php le 2026-08-25).
--
-- La bascule vit désormais dans KDocs\Models\DocumentVersion::create().
-- Le trigger before_document_version_insert (numérotation) reste VALIDE :
-- un SELECT dans un trigger BEFORE est autorisé et il fonctionne.
--
-- Réconciliation portée par la même migration : une seule version courante
-- par document (la plus récente), les compteurs documents recalculés.

DROP TRIGGER IF EXISTS after_document_version_insert;

UPDATE document_versions v
JOIN (
    SELECT document_id, MAX(id) AS dernier_id
    FROM document_versions
    GROUP BY document_id
) m ON m.document_id = v.document_id
SET v.is_current = (v.id = m.dernier_id);

UPDATE documents d
LEFT JOIN (
    SELECT document_id, COUNT(*) AS n, MAX(version_number) AS derniere
    FROM document_versions
    GROUP BY document_id
) s ON s.document_id = d.id
SET d.version_count = COALESCE(s.n, 0),
    d.current_version = COALESCE(s.derniere, 0),
    d.last_version_at = COALESCE(d.last_version_at, NOW());
