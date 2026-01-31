<?php
/**
 * K-Docs - Interface DocumentProcessor
 * Contrat pour le traitement de documents
 */

namespace KDocs\Contracts;

interface DocumentProcessorInterface
{
    /**
     * Traitement complet d'un document (OCR → Matching → Thumbnail → Workflows)
     *
     * @param int $documentId ID du document à traiter
     * @return array Résultats du traitement ['ocr' => bool, 'matching' => array, 'thumbnail' => bool, 'workflows' => array]
     * @throws \Exception Si document introuvable ou erreur critique
     */
    public function process(int $documentId): array;

    /**
     * Traite les documents en attente d'indexation
     *
     * @param int $limit Nombre maximum de documents à traiter
     * @return array Statistiques ['processed' => int, 'errors' => int]
     */
    public function processPendingDocuments(int $limit = 10): array;
}
