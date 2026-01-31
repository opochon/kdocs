<?php
/**
 * K-Docs - Interface WebhookService
 * Contrat pour les services de webhooks
 */

namespace KDocs\Contracts;

interface WebhookServiceInterface
{
    /**
     * Déclenche les webhooks pour un événement
     *
     * @param string $event Nom de l'événement (ex: 'document.created')
     * @param array $data Données à envoyer
     * @return void
     */
    public function trigger(string $event, array $data): void;
}
