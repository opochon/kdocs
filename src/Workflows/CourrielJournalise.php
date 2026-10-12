<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Adaptateur de courriel par DÉFAUT (sans secret, sans réseau) : il journalise la demande d'envoi. Le
 * canal réel est porté par le secteur Jonctions ; tant qu'il n'est pas branché ici, l'action est tracée
 * et le document n'est jamais bloqué.
 */
final class CourrielJournalise implements PortCourriel
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function envoyer(array $destinataires, string $sujet, string $corps, bool $inclureDocument, array $document): void
    {
        $this->journal->ajouter([
            'document_id' => (int) ($document['id'] ?? 0),
            'genre' => 'courriel',
            'auteur' => 'workflow',
            'horodatage' => new \DateTimeImmutable(),
            'details' => [
                'destinataires' => $destinataires,
                'sujet' => $sujet,
                'corps' => $corps,
                'inclure_document' => $inclureDocument,
            ],
        ]);
    }
}
