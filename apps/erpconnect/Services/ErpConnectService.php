<?php

declare(strict_types=1);

namespace KDocs\Apps\Erpconnect\Services;

use KDocs\Core\Database;
use KDocs\Services\Ingest\CmdV4CapabilityProbe;
use KDocs\Services\Ingest\CmdV4Client;
use PDO;

/**
 * Orchestration du flux ERP Connect :
 *   analyse document GED → proposition K-Time → introduction → statut validation.
 *
 * PDO et KTimeClient sont injectables pour tests hermétiques (SQLite en mémoire).
 *
 * Spec : K-TIME/docs/SPEC-GED-INTEGRATION.md
 */
class ErpConnectService
{
    private PDO $db;
    private KTimeClient $ktime;

    public function __construct(?PDO $db = null, ?KTimeClient $ktime = null)
    {
        $this->db    = $db    ?? Database::getInstance();
        $this->ktime = $ktime ?? new KTimeClient();
    }

    // =========================================================================
    // API publique
    // =========================================================================

    /**
     * Récupère les données facture du document GED.
     *
     * Priorité : BDD (invoice_line_items existants) → CMD v4 si joignable → vide.
     *
     * @return array{
     *     supplier_name: string|null,
     *     supplier_iban: string|null,
     *     invoice_number: string|null,
     *     invoice_date: string|null,
     *     due_date: string|null,
     *     total_ttc: float|null,
     *     lines: list<array<string,mixed>>,
     *     source: 'db'|'cmdv4'|'empty'
     * }
     */
    public function analyzeDocument(int $documentId): array
    {
        $doc   = $this->fetchDocument($documentId);
        $lines = $this->fetchLines($documentId);

        if ($lines !== []) {
            return $this->buildDocumentResult($doc, $lines, 'db');
        }

        // Tentative CMD v4 (ne modifie aucune classe core, fallback si indisponible)
        $cmdLines = $this->tryExtractViaCmdV4($documentId, $doc);
        if ($cmdLines !== null) {
            return $this->buildDocumentResult($doc, $cmdLines, 'cmdv4');
        }

        return $this->buildDocumentResult($doc, [], 'empty');
    }

    /**
     * Construit la proposition de ventilation pour le panneau utilisateur.
     *
     * Appelle analyzeDocument puis les APIs K-Time : lookup fournisseur, ventilation,
     * dédup facture. Si K-Time est indisponible, ktime_available = false (jamais d'exception
     * propagée au contrôleur depuis cette méthode).
     *
     * @return array{
     *     document_id: int,
     *     supplier: array{known: bool, match: array<string,mixed>|null},
     *     invoice_exists: array<string,mixed>|null,
     *     lines: list<array<string,mixed>>,
     *     ktime_available: bool,
     *     supplier_name: string|null,
     *     supplier_iban: string|null,
     *     invoice_number: string|null,
     *     invoice_date: string|null,
     *     due_date: string|null,
     *     total_ttc: float|null,
     *     source: string
     * }
     */
    public function buildProposal(int $documentId): array
    {
        $analysis = $this->analyzeDocument($documentId);

        $result = [
            'document_id'    => $documentId,
            'supplier'       => ['known' => false, 'match' => null],
            'invoice_exists' => null,
            'lines'          => [],
            'ktime_available' => false,
            'supplier_name'  => $analysis['supplier_name'],
            'supplier_iban'  => $analysis['supplier_iban'],
            'invoice_number' => $analysis['invoice_number'],
            'invoice_date'   => $analysis['invoice_date'],
            'due_date'       => $analysis['due_date'],
            'total_ttc'      => $analysis['total_ttc'],
            'source'         => $analysis['source'],
        ];

        // Health check K-Time (health() ne lève jamais d'exception)
        $health = $this->ktime->health();
        if (!($health['ok'] ?? false)) {
            $result['lines'] = $this->linesWithoutVentilation($analysis['lines']);
            return $result;
        }

        $result['ktime_available'] = true;

        // Lookup fournisseur + ventilation + dédup
        $ventilationMap = [];

        try {
            $criteria = array_filter([
                'iban' => $analysis['supplier_iban'] ?? null,
                'q'    => $analysis['supplier_name'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');

            if ($criteria !== []) {
                $lookup  = $this->ktime->lookupSupplier($criteria);
                $matches = $lookup['matches'] ?? [];

                if (!empty($matches)) {
                    $best       = $matches[0];
                    $supplierId = (int) ($best['id'] ?? 0);
                    $result['supplier'] = ['known' => true, 'match' => $best];

                    // Ventilation habituelle du fournisseur (§3.3)
                    $ventResp = $this->ktime->ventilation($supplierId);
                    foreach ($ventResp['articles'] ?? [] as $art) {
                        $ref = (string) ($art['supplier_ref'] ?? $art['code'] ?? '');
                        if ($ref !== '') {
                            $ventilationMap[$ref] = $art['ventilation'] ?? 'non_introduit';
                        }
                        if (!empty($art['product_id'])) {
                            $ventilationMap['pid:' . $art['product_id']] = $art['ventilation'] ?? 'non_introduit';
                        }
                    }

                    // Déduplication (§3.4)
                    if (!empty($analysis['invoice_number'])) {
                        $result['invoice_exists'] = $this->ktime->invoiceExists(
                            $supplierId,
                            (string) $analysis['invoice_number'],
                            (float) ($analysis['total_ttc'] ?? 0.0)
                        );
                    }
                }
            }
        } catch (KTimeUnavailableException) {
            // K-Time tombé entre le health et le lookup : on dégrade gracieusement
            $result['ktime_available'] = false;
            $result['lines'] = $this->linesWithoutVentilation($analysis['lines']);
            return $result;
        }

        $result['lines'] = $this->ventilateLines($analysis['lines'], $ventilationMap);

        return $result;
    }

    /**
     * Introduit la facture dans K-Time et persiste le lien erp_links.
     *
     * Idempotent : un second appel met à jour le lien existant (même external_ref).
     * Lève KTimeUnavailableException si K-Time est injoignable (le contrôleur retourne 503).
     *
     * @param array<string, mixed> $userChoices
     * @return array<string, mixed>  Réponse K-Time + champ duplicate
     * @throws KTimeUnavailableException
     */
    public function submitToKTime(int $documentId, array $userChoices): array
    {
        $analysis = $this->analyzeDocument($documentId);

        // Résolution fournisseur : id K-Time si connu, sinon name + iban
        $supplierId = isset($userChoices['supplier_id']) ? (int) $userChoices['supplier_id'] : null;
        $supplier   = $supplierId !== null
            ? ['id' => $supplierId]
            : [
                'name' => (string) ($analysis['supplier_name'] ?? ''),
                'iban' => (string) ($analysis['supplier_iban'] ?? ''),
              ];

        // Totaux
        $totalTtc = (float) ($analysis['total_ttc'] ?? 0.0);
        $totalHt  = isset($userChoices['total_ht']) ? (float) $userChoices['total_ht'] : $totalTtc;
        $totalTva = round($totalTtc - $totalHt, 2);

        // Lignes avec les choix utilisateur
        $lineChoices = $userChoices['lines'] ?? [];
        $lines = array_map(static function (array $line) use ($lineChoices): array {
            $lineId = (string) ($line['id'] ?? '');
            $choice = is_array($lineChoices[$lineId] ?? null) ? $lineChoices[$lineId] : [];

            return [
                'description'           => (string) ($line['description'] ?? ''),
                'qty'                   => (float) ($line['quantity'] ?? 1),
                'unit_price'            => (float) ($line['unit_price'] ?? 0.0),
                'tva_rate'              => (float) ($line['tax_rate'] ?? 0.0),
                'supplier_article_code' => $line['code'] ?? null,
                'action'                => $choice['action'] ?? null,
            ];
        }, $analysis['lines']);

        // Payload spec §3.5
        $payload = [
            'external_ref'  => 'ged:doc:' . $documentId,
            'supplier'      => $supplier,
            'supplier_ref'  => (string) ($analysis['invoice_number'] ?? ''),
            'invoice_date'  => (string) ($analysis['invoice_date'] ?? ''),
            'due_date'      => $analysis['due_date'] ?? null,
            'total_ht'      => $totalHt,
            'total_tva'     => $totalTva,
            'total_ttc'     => $totalTtc,
            'currency'      => (string) ($userChoices['currency'] ?? 'CHF'),
            'lines'         => $lines,
            'note'          => 'Scan GED ' . date('Y-m-d'),
        ];

        $response = $this->ktime->createReceivedInvoice($payload);

        $this->upsertErpLink($documentId, $response, $payload);

        return $response;
    }

    /**
     * Rafraîchit le statut de validation depuis K-Time et met à jour erp_links.
     *
     * Retourne le statut enrichi (bon_pour_accord, validated_by_name, validated_at).
     * Lève KTimeUnavailableException si K-Time est injoignable.
     *
     * @return array<string, mixed>
     * @throws KTimeUnavailableException
     */
    public function refreshStatus(int $documentId): array
    {
        $link = $this->findErpLink($documentId);
        if ($link === null) {
            return ['error' => 'Aucun lien ERP pour ce document', 'document_id' => $documentId];
        }

        $externalId = (int) $link['external_id'];
        $invoice    = $this->ktime->getReceivedInvoice($externalId);

        $validationStatus = $invoice['validation_status'] ?? null;
        $validatedByName  = $invoice['validated_by']['name'] ?? null;
        $validatedAt      = $invoice['validated_at'] ?? null;
        $status           = $invoice['status'] ?? (string) ($link['status'] ?? 'draft');

        $this->db->prepare(
            'UPDATE erp_links
             SET status = ?, validation_status = ?, validated_by_name = ?, validated_at = ?, updated_at = ?
             WHERE document_id = ? AND connector = ?'
        )->execute([
            $status,
            $validationStatus,
            $validatedByName,
            $validatedAt,
            date('Y-m-d H:i:s'),
            $documentId,
            'ktime',
        ]);

        return array_merge($invoice, [
            'document_id'       => $documentId,
            'bon_pour_accord'   => $validationStatus === 'validated',
            'validated_by_name' => $validatedByName,
            'validated_at'      => $validatedAt,
        ]);
    }

    // =========================================================================
    // Méthodes privées
    // =========================================================================

    /** @return array<string,mixed>|null */
    private function fetchDocument(int $documentId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function fetchLines(int $documentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM invoice_line_items WHERE document_id = ? ORDER BY line_number'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Tente d'extraire les lignes via CMD v4 (réutilisation sans modification des classes core).
     * Retourne null si CMD v4 est désactivé, non joignable, ou si l'extraction échoue.
     *
     * Le chemin CMD v4 live n'est pas testé unitairement (CMD_V4_ENABLED=false en test).
     *
     * @param array<string,mixed>|null $doc
     * @return list<array<string,mixed>>|null
     */
    private function tryExtractViaCmdV4(int $documentId, ?array $doc): ?array
    {
        try {
            $probe = new CmdV4CapabilityProbe();
            if (!$probe->invoiceEnrichmentEnabled()) {
                return null;
            }

            $filePath = $this->resolveDocumentPath($doc);
            if ($filePath === null) {
                return null;
            }

            $client = new CmdV4Client();
            $job    = $client->analyzeFile($filePath);
            if ($job === null || empty($job['job_id'])) {
                return null;
            }

            $done = $client->waitForJob($job['job_id']);
            if ($done === null || ($done['status'] ?? '') !== 'done') {
                return null;
            }

            $slug   = (string) ($job['slug'] ?? '');
            $fields = $client->getDocumentFields($slug, 1);
            if ($fields === null) {
                return null;
            }

            // Mapping minimal : lignes CMD v4 → format invoice_line_items
            $rawLines   = $fields['fields']['lines'] ?? $fields['fields']['lignes'] ?? [];
            if (!is_array($rawLines) || $rawLines === []) {
                return null;
            }

            $result     = [];
            $lineNumber = 1;
            foreach ($rawLines as $l) {
                if (!is_array($l)) {
                    continue;
                }
                $description = trim((string) ($l['description'] ?? $l['libelle'] ?? ''));
                if ($description === '') {
                    continue;
                }
                $result[] = [
                    'id'          => null,
                    'document_id' => $documentId,
                    'line_number' => $lineNumber++,
                    'code'        => $l['article_code'] ?? $l['code'] ?? null,
                    'description' => $description,
                    'quantity'    => is_numeric($l['quantity']   ?? $l['quantite']    ?? null)
                        ? (float) ($l['quantity'] ?? $l['quantite']) : null,
                    'unit_price'  => is_numeric($l['unit_price'] ?? $l['prix_unitaire'] ?? null)
                        ? (float) ($l['unit_price'] ?? $l['prix_unitaire']) : null,
                    'tax_rate'    => is_numeric($l['tax_rate']   ?? $l['taux_tva']    ?? null)
                        ? (float) ($l['tax_rate'] ?? $l['taux_tva']) : null,
                    'line_total'  => is_numeric($l['line_total'] ?? $l['montant_ligne'] ?? null)
                        ? (float) ($l['line_total'] ?? $l['montant_ligne']) : null,
                ];
            }

            return $result !== [] ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed>|null $doc */
    private function resolveDocumentPath(?array $doc): ?string
    {
        if (!is_array($doc)) {
            return null;
        }
        foreach (['file_path', 'path', 'storage_path', 'filename'] as $col) {
            if (!empty($doc[$col]) && is_file((string) $doc[$col])) {
                return (string) $doc[$col];
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed>|null $doc
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    private function buildDocumentResult(?array $doc, array $lines, string $source): array
    {
        $docId  = is_array($doc) ? (int) ($doc['id'] ?? 0) : 0;
        $header = $this->fetchHeader($docId);

        return [
            'supplier_name'  => $header['supplier']       ?? null,
            'supplier_iban'  => $header['supplier_iban']  ?? null,
            'invoice_number' => $header['invoice_number'] ?? null,
            'invoice_date'   => $header['invoice_date']   ?? null,
            'due_date'       => $header['due_date']       ?? null,
            'total_ttc'      => isset($header['total_ttc']) ? (float) $header['total_ttc'] : null,
            'lines'          => $lines,
            'source'         => $source,
        ];
    }

    /**
     * Récupère l'en-tête facture depuis invoice_extraction_results (CMD v4 / IA).
     *
     * @return array<string,mixed>
     */
    private function fetchHeader(int $documentId): array
    {
        if ($documentId <= 0) {
            return [];
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT parsed_data FROM invoice_extraction_results
                 WHERE document_id = ? AND success = 1
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$documentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false && !empty($row['parsed_data'])) {
                $data = json_decode((string) $row['parsed_data'], true);
                return is_array($data) ? $data : [];
            }
        } catch (\Throwable) {
            // Table absente (SQLite minimal en test) : normal, on dégrade
        }
        return [];
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function linesWithoutVentilation(array $lines): array
    {
        return array_map(static fn (array $line): array => array_merge($line, [
            'ventilation' => 'non_introduit',
            'options'     => ['stock', 'fiche_travail', 'article_recu'],
        ]), $lines);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,string> $ventilationMap
     * @return list<array<string,mixed>>
     */
    private function ventilateLines(array $lines, array $ventilationMap): array
    {
        return array_map(static function (array $line) use ($ventilationMap): array {
            $code = (string) ($line['code'] ?? '');
            $vent = $ventilationMap[$code] ?? 'non_introduit';

            return array_merge($line, [
                'ventilation' => $vent,
                'options'     => $vent === 'non_introduit'
                    ? ['stock', 'fiche_travail', 'article_recu']
                    : [],
            ]);
        }, $lines);
    }

    /** @return array<string,mixed>|null */
    private function findErpLink(int $documentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM erp_links WHERE document_id = ? AND connector = ? LIMIT 1'
        );
        $stmt->execute([$documentId, 'ktime']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Insère ou met à jour le lien erp_links (idempotent sur document_id + connector).
     *
     * @param array<string,mixed> $response Réponse K-Time {id, status, ...}
     * @param array<string,mixed> $payload  Payload envoyé
     */
    private function upsertErpLink(int $documentId, array $response, array $payload): void
    {
        $externalId  = (int) ($response['id'] ?? 0);
        $externalRef = 'ged:doc:' . $documentId;
        $status      = (string) ($response['status'] ?? 'draft');
        $now         = date('Y-m-d H:i:s');

        $existing = $this->findErpLink($documentId);

        if ($existing !== null) {
            $this->db->prepare(
                'UPDATE erp_links
                 SET external_id = ?, external_ref = ?, status = ?, payload_json = ?, updated_at = ?
                 WHERE document_id = ? AND connector = ?'
            )->execute([
                $externalId,
                $externalRef,
                $status,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $now,
                $documentId,
                'ktime',
            ]);
        } else {
            $this->db->prepare(
                'INSERT INTO erp_links
                     (document_id, connector, external_id, external_ref, status,
                      validation_status, payload_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $documentId,
                'ktime',
                $externalId,
                $externalRef,
                $status,
                null,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $now,
                $now,
            ]);
        }
    }
}
