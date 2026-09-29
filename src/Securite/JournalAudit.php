<?php

declare(strict_types=1);

namespace Ged\Securite;

use RuntimeException;

/** Journal NDJSON écrit exclusivement en ajout : aucun code de modification ou de purge n'est exposé. */
final class JournalAudit
{
    public function __construct(private readonly string $fichier)
    {
    }

    /** @param array<string, scalar|null> $contexte */
    public function ajouter(string $action, ?string $utilisateur, array $contexte = [], ?int $maintenant = null): void
    {
        $dossier = dirname($this->fichier);
        if (!is_dir($dossier) && !mkdir($dossier, 0700, true) && !is_dir($dossier)) {
            throw new RuntimeException('Impossible de créer le journal d’audit.');
        }
        $entree = ['horodatage' => gmdate('c', $maintenant ?? time()), 'action' => $action, 'utilisateur' => $utilisateur, 'contexte' => $contexte];
        if (file_put_contents($this->fichier, json_encode($entree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’ajouter l’entrée d’audit.');
        }
    }

    /** Enveloppe dédiée pour que toute future administration des droits soit auditée sans ambiguïté. */
    public function changementDroit(string $auteur, string $cible, string $permission, bool $accordee, ?int $maintenant = null): void
    {
        $this->ajouter('changement_droit', $auteur, [
            'cible' => $cible,
            'permission' => $permission,
            'accordee' => $accordee,
        ], $maintenant);
    }
}
