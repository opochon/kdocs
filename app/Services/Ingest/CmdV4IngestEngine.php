<?php

declare(strict_types=1);

namespace KDocs\Services\Ingest;

use KDocs\Services\Classification\IngestClassificationService;

/**

 * Moteur ingest CMD v4 — pipeline projet éphémère pour factures PDF.

 *

 * Parcours API v4 (cf. `clearmydocs-v3/cmdv4/docs/API.md` §4) :

 * create project → extract → synthesize → index → GET fields/{doc_id}.

 * Échec gracieux : le routeur retombe sur v3 ou natif.

 */

class CmdV4IngestEngine

{

    private CmdV4Client $client;

    private CmdV4ResultMapper $mapper;

    private IngestClassificationService $classification;

    public function __construct(

        ?CmdV4Client $client = null,

        ?CmdV4ResultMapper $mapper = null,

        ?IngestClassificationService $classification = null

    ) {

        $this->client = $client ?? new CmdV4Client();

        $this->mapper = $mapper ?? new CmdV4ResultMapper();

        $this->classification = $classification ?? new IngestClassificationService();

    }

    /**

     * @param array<string, mixed> $document

     *

     * @return array<string, mixed>

     */

    public function process(int $documentId, string $filePath, array $document): array

    {

        $result = [

            'engine' => 'cmd_v4',

            'extract_done' => false,

            'classification_skipped' => false,

            'classification_queued' => false,

            'invoice_enriched' => false,

            'annexe_indexed' => false,

            'sidecar_error' => null,

            'cmd_v4_project' => null,

        ];

        if (!$this->client->isEnabled() || !is_readable($filePath)) {

            $result['sidecar_error'] = 'v4_unavailable';

            return $result;

        }

        $sourceDir = dirname($filePath);

        if (!is_dir($sourceDir)) {

            $result['sidecar_error'] = 'source_dir_missing';

            return $result;

        }

        $project = $this->client->createProject(

            'GED Doc ' . $documentId,

            $sourceDir

        );

        if ($project === null) {

            $result['sidecar_error'] = 'project_create_failed';

            return $result;

        }

        $slug = $project['slug'];

        $result['cmd_v4_project'] = $slug;

        $keepStaging = filter_var(env('CMD_V4_KEEP_STAGING', false), FILTER_VALIDATE_BOOLEAN);

        try {

            if (!$this->runJobStep($slug, 'extract', fn () => $this->client->startExtract($slug))) {

                $result['sidecar_error'] = 'extract_failed';

                return $result;

            }

            $result['extract_done'] = true;

            if (!$this->runJobStep($slug, 'synthesize', fn () => $this->client->startSynthesize($slug))) {

                $result['sidecar_error'] = 'synthesize_failed';

                return $result;

            }

            if (!$this->runJobStep($slug, 'index', fn () => $this->client->startIndex($slug))) {

                $result['sidecar_error'] = 'index_failed';

                return $result;

            }

            $fieldsDoc = $this->client->getDocumentFields($slug, 1);

            if ($fieldsDoc === null) {

                $result['sidecar_error'] = 'fields_missing';

                return $result;

            }

            $mapped = $this->mapper->applyInvoiceFields($documentId, $fieldsDoc, $slug);

            $result['invoice_enriched'] = $mapped;

            $result['classification_skipped'] = $mapped;

            // Étape 6 — substrat annexe indexable (repli quand la facture n'est pas
            // mappée : les hints facture ne sont pas écrasés) + statut de fraîcheur.
            if (!$mapped) {
                $annexe = $this->client->getAnnexe($slug);
                if ($annexe !== null) {
                    $result['annexe_indexed'] = $this->mapper->applyAnnexeSubstrate($documentId, $slug, $annexe);
                }
            }

            $freshness = $this->client->getFreshness($slug);
            if ($freshness !== null) {
                $this->mapper->applyFreshnessStatus($documentId, $freshness);
            }

            if (!$mapped && filter_var(env('IA_UNIFIED_CLASSIFY_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {

                $result['classification_queued'] = $this->classification->queue($documentId);

            }

        } finally {

            if (!$keepStaging) {

                $this->client->deleteProject($slug);

                $result['cmd_v4_project'] = null;

            }

        }

        return $result;

    }

    /** @param callable(): ?array{job_id: string} $start */

    private function runJobStep(string $slug, string $step, callable $start): bool

    {

        $started = $start();

        if ($started === null || empty($started['job_id'])) {

            error_log("CmdV4IngestEngine: impossible de démarrer {$step} pour {$slug}");

            return false;

        }

        $job = $this->client->waitForJob($started['job_id']);

        if ($job === null) {

            error_log("CmdV4IngestEngine: timeout job {$step} pour {$slug}");

            return false;

        }

        if (($job['status'] ?? '') !== 'done') {

            $error = (string) ($job['error'] ?? 'job_error');

            error_log("CmdV4IngestEngine: échec job {$step} pour {$slug}: {$error}");

            return false;

        }

        return true;

    }

}
