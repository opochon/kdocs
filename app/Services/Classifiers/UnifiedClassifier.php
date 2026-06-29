<?php



declare(strict_types=1);



namespace KDocs\Services\Classifiers;



use KDocs\Adapters\GedNativeClassifierAdapter;

use KDocs\Adapters\HtmleditorTaxonomyAdapter;

use KDocs\Adapters\InfomaniakClassifierAdapter;

use KDocs\Contracts\ClassifierInterface;

use KDocs\DTO\ClassificationResult;

use KDocs\Services\AIClassifierService;

use KDocs\Services\Classification\TaxonomySyncService;



/**

 * Façade plugin — agrège les adapters (HTMLEDITOR, Infomaniak AI, GED native).

 */

class UnifiedClassifier implements ClassifierInterface

{

    private AIClassifierService $native;



    /** @var list<ClassifierInterface> */

    private array $adapters = [];



    public function __construct(?AIClassifierService $native = null)

    {

        $this->native = $native ?? new AIClassifierService();

    }



    public static function createConfigured(): self

    {

        $classifier = new self();

        $classifier->registerAdapter(new HtmleditorTaxonomyAdapter());

        $classifier->registerAdapter(new InfomaniakClassifierAdapter());

        $classifier->registerAdapter(new GedNativeClassifierAdapter());



        return $classifier;

    }



    public function registerAdapter(ClassifierInterface $adapter): void

    {

        $this->adapters[] = $adapter;

    }



    public function getName(): string

    {

        return 'unified';

    }



    public function isAvailable(): bool

    {

        return true;

    }



    /**

     * Classification ingest avec taxonomie syncée et texte OCR.

     *

     * @param array<string, mixed> $documentMeta

     */

    public function classifyDocument(array $documentMeta, ?string $ocrText): ClassificationResult

    {

        $context = $this->buildContext($documentMeta, $ocrText);

        $arrayResult = $this->classify($context);



        return ClassificationResult::fromArray($arrayResult);

    }



    public function classify(array $context): array

    {

        $context = $this->enrichWithSyncedTaxonomy($context);

        $minConfidence = (float) env('IA_UNIFIED_MIN_CONFIDENCE', 0.75);

        $best = null;



        foreach ($this->adapters as $adapter) {

            if (!$adapter->isAvailable()) {

                continue;

            }



            $result = $adapter->classify($context);

            $confidence = (float) ($result['confidence'] ?? 0.0);

            $result['source'] = $adapter->getName();



            if ($best === null || $confidence > (float) ($best['confidence'] ?? 0.0)) {

                $best = $result;

            }



            if ($confidence >= $minConfidence) {

                return $this->normalizeResult($result, $adapter->getName());

            }

        }



        if ($best !== null && (float) ($best['confidence'] ?? 0.0) > 0.0) {

            return $this->normalizeResult($best, (string) ($best['source'] ?? 'adapter'));

        }



        return $this->fallbackNative($context);

    }



    public function syncTaxonomy(): array

    {

        $snapshots = [];

        foreach ($this->adapters as $adapter) {

            if ($adapter->isAvailable()) {

                $snapshots[$adapter->getName()] = $adapter->syncTaxonomy();

            }

        }



        return [

            'adapters' => $snapshots,

            'synced_at' => date('c'),

        ];

    }



    /** @param array<string, mixed> $documentMeta */

    /** @return array<string, mixed> */

    private function buildContext(array $documentMeta, ?string $ocrText): array

    {

        $text = $ocrText ?? '';

        if ($text === '') {

            $text = trim(

                ($documentMeta['title'] ?? '') . ' ' .

                ($documentMeta['original_filename'] ?? '') . ' ' .

                ($documentMeta['content'] ?? '') . ' ' .

                ($documentMeta['ocr_text'] ?? '')

            );

        }



        return [

            'document_id' => (int) ($documentMeta['id'] ?? $documentMeta['document_id'] ?? 0),

            'text' => $text,

            'mime_type' => $documentMeta['mime_type'] ?? null,

            'file_path' => $documentMeta['file_path'] ?? null,

            'project_key' => $documentMeta['project_key'] ?? null,

            'external_ids' => is_array($documentMeta['external_ids'] ?? null) ? $documentMeta['external_ids'] : [],

        ];

    }



    /** @param array<string, mixed> $context */

    /** @return array<string, mixed> */

    private function enrichWithSyncedTaxonomy(array $context): array

    {

        $sync = new TaxonomySyncService();

        $stored = $sync->getStored();

        if ($stored !== null && !empty($stored['taxonomy']) && is_array($stored['taxonomy'])) {

            $context['taxonomy'] = $stored['taxonomy'];

            $context['taxonomy_source'] = 'database';

        }



        return $context;

    }



    /** @param array<string, mixed> $context */

    /** @return array<string, mixed> */

    private function fallbackNative(array $context): array

    {

        $documentId = (int) ($context['document_id'] ?? 0);

        $suggestions = $documentId > 0

            ? ($this->native->classify($documentId) ?? [])

            : [];



        return $this->normalizeResult([

            'category' => $suggestions['document_type'] ?? null,

            'tags' => $suggestions['tags'] ?? [],

            'externalIds' => [],

            'suggestions' => $suggestions,

            'confidence' => (float) ($suggestions['confidence'] ?? 0.5),

            'raw' => $suggestions,

            'audit' => ['adapter' => 'AIClassifierService', 'document_id' => $documentId],

        ], 'ged-native-fallback');

    }



    /** @param array<string, mixed> $result */

    /** @return array<string, mixed> */

    private function normalizeResult(array $result, string $source): array

    {

        $suggestions = is_array($result['suggestions'] ?? null) ? $result['suggestions'] : [];



        return [

            'category' => $result['category'] ?? $suggestions['document_type_name'] ?? $suggestions['document_type'] ?? null,

            'tags' => array_values(array_map('strval', $result['tags'] ?? $suggestions['tag_names'] ?? $suggestions['matched_tags'] ?? [])),

            'externalIds' => is_array($result['externalIds'] ?? null) ? $result['externalIds'] : [],

            'suggestions' => $suggestions,

            'confidence' => (float) ($result['confidence'] ?? 0.0),

            'source' => $source,

            'raw' => is_array($result['raw'] ?? null) ? $result['raw'] : $result,

            'audit' => is_array($result['audit'] ?? null) ? $result['audit'] : ['source' => $source],

        ];

    }

}

