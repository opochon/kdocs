<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * FAUX port de courriel pour les tests : il enregistre ce qui aurait été envoyé et ne touche jamais au
 * réseau. Aucune dépendance SMTP, aucun secret.
 */
final class CourrielFaux implements PortCourriel
{
    /** @var list<array{destinataires:list<string>, sujet:string, corps:string, inclure_document:bool, document_id:int}> */
    public array $envoyes = [];

    public function envoyer(array $destinataires, string $sujet, string $corps, bool $inclureDocument, array $document): void
    {
        $this->envoyes[] = [
            'destinataires' => $destinataires,
            'sujet' => $sujet,
            'corps' => $corps,
            'inclure_document' => $inclureDocument,
            'document_id' => (int) ($document['id'] ?? 0),
        ];
    }
}
