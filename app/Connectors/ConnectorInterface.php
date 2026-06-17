<?php

namespace KDocs\Connectors;

/**
 * Contrat minimal pour les connecteurs ERP / cloud GEDv1.
 */
interface ConnectorInterface
{
    public function connect(): bool;

    public function disconnect(): void;

    public function isConnected(): bool;

    /** @return array{success: bool, message: string} */
    public function testConnection(): array;
}
