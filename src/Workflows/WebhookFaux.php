<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * FAUX port de webhook pour les tests : il enregistre les appels et ne touche jamais au réseau.
 */
final class WebhookFaux implements PortWebhook
{
    /** @var list<array{url:string, entetes:array<string,string>, donnees:array<string,mixed>|string, json:bool, document_id:int}> */
    public array $appels = [];

    public function appeler(string $url, array $entetes, array|string $donnees, bool $json, bool $inclureDocument, array $document): void
    {
        $this->appels[] = [
            'url' => $url,
            'entetes' => $entetes,
            'donnees' => $donnees,
            'json' => $json,
            'document_id' => (int) ($document['id'] ?? 0),
        ];
    }
}
