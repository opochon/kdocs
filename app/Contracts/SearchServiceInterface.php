<?php
/**
 * K-Docs - Interface SearchService
 * Contrat pour les services de recherche
 */

namespace KDocs\Contracts;

use KDocs\Search\SearchQuery;
use KDocs\Search\SearchResult;

interface SearchServiceInterface
{
    /**
     * Recherche simple par texte
     *
     * @param string $query Termes de recherche
     * @param int $limit Nombre max de résultats
     * @return SearchResult
     */
    public function search(string $query, int $limit = 25): SearchResult;

    /**
     * Recherche avancée avec filtres
     *
     * @param SearchQuery $query Objet de requête structuré
     * @return SearchResult
     */
    public function advancedSearch(SearchQuery $query): SearchResult;
}
