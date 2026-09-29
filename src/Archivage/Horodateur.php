<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Un horodateur RFC 3161 : il reçoit l'empreinte BINAIRE (32 octets sha256) et rend la réponse TSA brute
 * (TimeStampResp DER). Injectable : TSA simulée en test (aucun réseau), TSA réelle en recette via TSA_URL.
 */
interface Horodateur
{
    public function horodater(string $empreinteBinaire): string;

    /** Libellé tracé dans le justificatif (URL de la TSA réelle, nom de la TSA simulée). */
    public function etiquette(): string;
}
