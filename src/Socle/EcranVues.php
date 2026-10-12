<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;

/**
 * Écran « Vues enregistrées » (V2 modèle, `SavedView`) : créer et nommer une vue, choisir les champs
 * affichés, le mode (table, petites ou grandes cartes), la taille de page, le tri, et décider si la vue
 * figure au tableau de bord ou dans le menu latéral. Les vues vivent dans le fonds (`.ged/vues.json`).
 */
final class EcranVues
{
    private readonly Vues $vues;

    public function __construct(Config $config)
    {
        $this->vues = new Vues($config);
    }

    /** @return array<string, mixed> */
    public function donnees(string $csrf): array
    {
        return [
            'ecran' => 'vues',
            'csrf' => $csrf,
            'vues' => $this->vues->lister(),
            'affichages' => Vues::AFFICHAGES,
            'modes' => Vues::MODES,
            'tris' => Vues::TRIS,
        ];
    }
}
