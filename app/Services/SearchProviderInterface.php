<?php
/**
 * K-DOCS - Interface SearchProvider
 * Abstraction pour les différents moteurs de recherche
 * 
 * Providers implémentés:
 * - MySQLFullTextProvider (par défaut, toujours disponible)
 * - QdrantVectorProvider (optionnel, si Qdrant configuré)
 */

namespace KDocs\Services;

use KDocs\Search\SearchResult;

interface SearchProviderInterface
{
    /**
     * Effectue une recherche
     *
     * @param string $query Texte de recherche
     * @param array $filters Filtres optionnels (correspondent_id, document_type_id, date_from, date_to, tags, etc.)
     * @param int $limit Nombre maximum de résultats
     * @param int $offset Décalage pour pagination
     * @return array ['documents' => [], 'total' => int, 'search_time' => float]
     */
    public function search(string $query, array $filters = [], int $limit = 25, int $offset = 0): array;

    /**
     * Vérifie si le provider est disponible et fonctionnel
     */
    public function isAvailable(): bool;

    /**
     * Retourne le nom du provider
     */
    public function getName(): string;

    /**
     * Retourne les capacités du provider
     * @return array ['fulltext' => bool, 'semantic' => bool, 'fuzzy' => bool, 'boolean' => bool]
     */
    public function getCapabilities(): array;

    /**
     * Suggestions d'autocomplétion
     */
    public function suggest(string $partial, int $limit = 10): array;
}
