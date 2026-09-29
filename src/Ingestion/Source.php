<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/** Une porte d'entrée de l'ingestion : dossier surveillé, adresse d'ingestion, dépôt à l'écran… */
interface Source
{
    /** Fichiers arrivés depuis le dernier relevé, déjà copiés dans la zone de travail. @return list<Entree> */
    public function relever(): array;
}
