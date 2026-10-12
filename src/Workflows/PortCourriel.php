<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Port d'envoi de courriel du moteur de workflows : l'ADAPTATEUR est injectable. Aucun secret ne vit dans
 * le code ; en production l'adaptateur par défaut journalise (le canal réel est celui des Jonctions), en
 * test on injecte un FAUX : aucun réseau n'est jamais touché par un test.
 */
interface PortCourriel
{
    /**
     * @param list<string>          $destinataires
     * @param array<string, mixed>  $document     fiche normalisée du document (pour la pièce jointe)
     */
    public function envoyer(array $destinataires, string $sujet, string $corps, bool $inclureDocument, array $document): void;
}
