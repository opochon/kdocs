<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Adaptateur de webhook par DÉFAUT : il journalise l'appel (URL, forme) sans réseau et sans secret. Un
 * vrai adaptateur HTTP pourra être injecté par les Jonctions, plus tard, sans changer le moteur.
 */
final class WebhookJournalise implements PortWebhook
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function appeler(string $url, array $entetes, array|string $donnees, bool $json, bool $inclureDocument, array $document): void
    {
        $this->journal->ajouter([
            'document_id' => (int) ($document['id'] ?? 0),
            'genre' => 'webhook',
            'auteur' => 'workflow',
            'horodatage' => new \DateTimeImmutable(),
            'details' => ['url' => $url, 'entetes' => $entetes, 'json' => $json, 'inclure_document' => $inclureDocument],
        ]);
    }
}
