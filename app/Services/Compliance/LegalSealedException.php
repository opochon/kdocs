<?php
/**
 * Exception levée sur toute tentative d'écriture d'un document scellé (WORM, P2).
 */

namespace KDocs\Services\Compliance;

class LegalSealedException extends \RuntimeException
{
    public function __construct(int $documentId)
    {
        parent::__construct("Document {$documentId} scellé légalement (WORM) — modification interdite", 403);
    }
}
