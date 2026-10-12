<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Port d'appel de webhook du moteur de workflows : l'ADAPTATEUR est injectable ; l'URL et les en-têtes
 * viennent de la définition du workflow (aucun secret dans le code). En test, un FAUX est injecté : aucun
 * appel réseau.
 */
interface PortWebhook
{
    /**
     * @param array<string, string>       $entetes
     * @param array<string, mixed>|string $donnees
     * @param array<string, mixed>        $document
     */
    public function appeler(string $url, array $entetes, array|string $donnees, bool $json, bool $inclureDocument, array $document): void;
}
