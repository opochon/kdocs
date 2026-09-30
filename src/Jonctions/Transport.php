<?php

declare(strict_types=1);

namespace Ged\Jonctions;

/**
 * Sortie des courriels du secteur Jonctions. Une seule abstraction : l'auto-configuration d'appairage et
 * la vérification d'adresse passent par là. En test, le transport « fichier » écrit le `.eml` dans `var/`
 * (jamais d'envoi réel) ; en production, le transport SMTP est configuré par `GED_SMTP_*`.
 */
interface Transport
{
    /**
     * @param list<array{nom:string, type:string, contenu:string}> $pieces
     * @param array<string, string> $entetes en-têtes additionnels (ex. X-GED-Appairage)
     *
     * @throws \RuntimeException si le message ne peut pas être préparé ou remis
     */
    public function envoyer(string $destinataire, string $objet, string $texte, array $pieces = [], array $entetes = []): void;
}
