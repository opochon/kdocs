<?php

declare(strict_types=1);

namespace Ged\Ingestion\Imap;

/** Ce dont l'adresse d'ingestion a besoin d'une boîte aux lettres. Rien d'autre. */
interface Boite
{
    /** Identifiant stable de la boîte (utilisateur@serveur), pour l'idempotence. */
    public function nom(): string;

    /** UID des messages non lus de la boîte de réception. @return list<int> */
    public function nonLus(): array;

    /** Message brut (RFC 822), lu sans le marquer comme lu. */
    public function lire(int $uid): string;

    /** Marque le message comme lu et le range dans $dossier (créé si besoin). Jamais d'effacement. */
    public function classer(int $uid, string $dossier): void;

    public function fermer(): void;
}
