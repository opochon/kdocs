<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Core\Database;

use KDocs\Models\InvoiceLineItem;

/**

 * Mappe les champs gatés CMD v4 (schéma facture_fournisseur) vers le schéma GED.

 */

class CmdV4ResultMapper

{

    private \PDO $db;

    public function __construct(?\PDO $db = null)

    {

        $this->db = $db ?? Database::getInstance();

    }

    /**

     * @param array<string, mixed> $v4Doc Réponse GET /fields/{doc_id}

     *

     * @return bool true si au moins un champ facture a été persisté

     */

    public function applyInvoiceFields(int $documentId, array $v4Doc, ?string $projectSlug = null): bool

    {

        $fields = is_array($v4Doc['fields'] ?? null) ? $v4Doc['fields'] : [];

        if ($fields === []) {

            return false;

        }

        $schema = (string) ($v4Doc['schema'] ?? '');

        $invoiceInfo = [

            'invoice_number' => $this->fieldString($fields, 'numero'),

            'invoice_date' => $this->fieldString($fields, 'date'),

            'supplier' => $this->fieldString($fields, 'fournisseur'),

            'total_ht' => $this->fieldAmount($fields, 'montant_ht'),

            'total_ttc' => $this->fieldAmount($fields, 'montant_ttc'),

        ];

        $parsed = [

            'schema' => $schema,

            'key_complete' => (bool) ($v4Doc['key_complete'] ?? false),

            'doc_id' => $v4Doc['doc_id'] ?? null,

            'project_slug' => $projectSlug,

            'fields' => $fields,

            'invoice_info' => $invoiceInfo,

        ];

        $this->persistExtractionResult($documentId, $parsed);

        $this->persistClassificationHints($documentId, $parsed, $invoiceInfo);

        $this->applyLineItems($documentId, $fields);

        return $this->hasInvoiceData($invoiceInfo);

    }

    /** @param array<string, mixed> $fields */

    private function applyLineItems(int $documentId, array $fields): void

    {

        $lines = $fields['lines'] ?? $fields['lignes'] ?? null;

        if (!is_array($lines) || $lines === []) {

            return;

        }

        InvoiceLineItem::deleteForDocument($documentId);

        $lineNumber = 1;

        foreach ($lines as $line) {

            if (!is_array($line)) {

                continue;

            }

            $description = trim((string) ($line['description'] ?? $line['libelle'] ?? ''));

            if ($description === '') {

                continue;

            }

            InvoiceLineItem::create([

                'document_id' => $documentId,

                'line_number' => $lineNumber++,

                'quantity' => $this->nullableFloat($line['quantity'] ?? $line['quantite'] ?? null),

                'unit' => $this->nullableString($line['unit'] ?? $line['unite'] ?? null),

                'code' => $this->nullableString($line['code'] ?? null),

                'description' => $description,

                'unit_price' => $this->nullableFloat($line['unit_price'] ?? $line['prix_unitaire'] ?? null),

                'discount_percent' => $this->nullableFloat($line['discount_percent'] ?? null),

                'tax_rate' => $this->nullableFloat($line['tax_rate'] ?? $line['taux_tva'] ?? null),

                'tax_amount' => $this->nullableFloat($line['tax_amount'] ?? $line['montant_tva'] ?? null),

                'line_total' => $this->nullableFloat($line['line_total'] ?? $line['montant_ligne'] ?? null),

                'raw_text' => isset($line['raw_text']) ? (string) $line['raw_text'] : null,

            ]);

        }

    }

    /** @param array<string, mixed> $parsed */

    private function persistExtractionResult(int $documentId, array $parsed): void

    {

        $this->db->prepare(

            'INSERT INTO invoice_extraction_results

                (document_id, extraction_type, raw_response, parsed_data, model_used, success)

             VALUES (?, ?, ?, ?, ?, ?)'

        )->execute([

            $documentId,

            'header',

            json_encode($parsed, JSON_UNESCAPED_UNICODE),

            json_encode($parsed['invoice_info'] ?? [], JSON_UNESCAPED_UNICODE),

            'cmd_v4',

            1,

        ]);

    }

    /**

     * @param array<string, mixed> $parsed

     * @param array<string, mixed> $invoiceInfo

     */

    private function persistClassificationHints(int $documentId, array $parsed, array $invoiceInfo): void

    {

        $supplier = (string) ($invoiceInfo['supplier'] ?? '');

        $payload = [

            'method_used' => 'cmd_v4_invoice_schema',

            'cmd_v4' => $parsed,

            'final' => [

                'document_type_name' => 'facture',

                'tag_names' => $supplier !== '' ? ['facture', $supplier] : ['facture'],

                'confidence' => ($parsed['key_complete'] ?? false) ? 0.9 : 0.65,

                'external_ids' => [],

            ],

            'confidence' => ($parsed['key_complete'] ?? false) ? 0.9 : 0.65,

            'should_review' => !($parsed['key_complete'] ?? false),

            'pending_classification' => false,

            'classified_at' => date('c'),

        ];

        $this->db->prepare('UPDATE documents SET classification_suggestions = ? WHERE id = ?')

            ->execute([json_encode($payload, JSON_UNESCAPED_UNICODE), $documentId]);

    }

    /** @param array<string, mixed> $fields */

    private function fieldString(array $fields, string $key): ?string

    {

        if (!array_key_exists($key, $fields) || $fields[$key] === null) {

            return null;

        }

        $value = trim((string) $fields[$key]);

        return $value !== '' ? $value : null;

    }

    /** @param array<string, mixed> $fields */

    private function fieldAmount(array $fields, string $key): ?float

    {

        if (!array_key_exists($key, $fields) || $fields[$key] === null || $fields[$key] === '') {

            return null;

        }

        return is_numeric($fields[$key]) ? (float) $fields[$key] : null;

    }

    /** @param array<string, mixed> $invoiceInfo */

    private function hasInvoiceData(array $invoiceInfo): bool

    {

        foreach ($invoiceInfo as $value) {

            if ($value !== null && $value !== '') {

                return true;

            }

        }

        return false;

    }

    private function nullableString(mixed $value): ?string

    {

        if ($value === null) {

            return null;

        }

        $str = trim((string) $value);

        return $str !== '' ? $str : null;

    }

    private function nullableFloat(mixed $value): ?float

    {

        if ($value === null || $value === '') {

            return null;

        }

        return is_numeric($value) ? (float) $value : null;

    }

}
