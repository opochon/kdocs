<?php
/**
 * K-Docs - Interface ThumbnailGenerator
 * Contrat pour les services de génération de miniatures
 */

namespace KDocs\Contracts;

interface ThumbnailGeneratorInterface
{
    /**
     * Génère une miniature pour un document
     *
     * @param string $sourcePath Chemin vers le fichier source
     * @param int $documentId ID du document
     * @return string|null Nom du fichier miniature ou null si échec
     */
    public function generate(string $sourcePath, int $documentId): ?string;
}
