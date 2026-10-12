<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;

/**
 * Écran « Suivi des traitements » (V2 modèle) : l'utilisateur voit l'avancement des traitements du fonds
 * — consommation, courrier, OCR — avec statut, horodatage, résultat et erreur, SANS Celery ni Redis.
 *
 * Le journal des tâches suivies vit dans le fonds (`.ged/taches.json`) ; la file réelle de consommation
 * (`ingestion_travaux`) est reflétée à côté, en lecture. Aucune écriture sur un GET.
 */
final class EcranTaches
{
    private readonly Config $config;
    private readonly Taches $taches;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->taches = new Taches($config);
    }

    /** @return array<string, mixed> */
    public function donnees(string $csrf): array
    {
        return [
            'ecran' => 'taches',
            'csrf' => $csrf,
            'bilan' => $this->taches->bilan(),
            'taches' => $this->taches->lister(50),
            'travaux' => $this->taches->depuisTravaux(Base::pdo($this->config), 20),
        ];
    }
}
