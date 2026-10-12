<?php

declare(strict_types=1);

namespace Ged\Securite;

/**
 * Droit d'ACQUITTEMENT des tâches (équivalent GEDv2 de `AcknowledgeTasksPermissions` de Paperless) : qui a
 * le droit d'acquitter une tâche de la file `ingestion_travaux`. Paperless gouverne les tâches via Celery ;
 * GEDv2 les porte dans sa file d'ingestion (`Socle\Taches`), et l'acquittement est réservé à
 * l'administrateur (comme la liste des tâches et le rôle « staff » de Paperless). Aucun démon, aucun
 * broker : la file vit en base.
 */
final class PermissionsTaches
{
    /** Permission fine correspondante, pour un futur rôle non-administrateur. */
    public const ACQUITTER = 'taches:acquitter';

    /**
     * L'acquittement est ouvert à l'administrateur (permission `*`). Un rôle non-administrateur pourra
     * l'obtenir plus tard par la permission fine {@see self::ACQUITTER}, sans changer la porte d'appel.
     *
     * @param list<string> $permissions permissions du compte, `*` inclus
     */
    public static function peutAcquitter(bool $administrateur, array $permissions = []): bool
    {
        return $administrateur
            || in_array('*', $permissions, true)
            || in_array(self::ACQUITTER, $permissions, true);
    }
}
