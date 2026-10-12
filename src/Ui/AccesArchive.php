<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Fonds;
use PDO;
use Throwable;

/**
 * Accès direct par NUMÉRO D'ARCHIVE (modèle Paperless `DocumentAsnComponent`, lot ged-parite-ecrans-1).
 *
 * Paperless : `/asn/:id` résout un numéro d'archive (ASN) vers le document et ouvre sa fiche. GEDv2 n'a
 * pas d'ASN de scellement (le scellement porte un jeton TSA) ; son INDEX numérote les documents de façon
 * stable, et la reconstruction le préserve (F12). Le même geste est donc rendu : saisir un numéro ouvre
 * le document. Un numéro inconnu est refusé NOMMÉMENT, jamais un 500 muet.
 *
 * Lecture seule : aucune écriture, ni index, ni fonds.
 */
final class AccesArchive
{
    public function __construct(private readonly Config $config, private readonly ?PDO $pdo)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));
        $pdo = null;
        try {
            $pdo = Base::pdo($config);
        } catch (Throwable) {
            // Base muette : la résolution retombera sur le dossier, ou nommera l'échec.
        }

        return new self($config, $pdo);
    }

    /**
     * Le document du numéro d'archive, ou null s'il n'existe pas (le dossier fait foi).
     *
     * @return array<string, mixed>|null
     */
    public function document(int $numero): ?array
    {
        if ($numero < 1) {
            return null;
        }
        try {
            return (new Fonds($this->config))->parId($numero, $this->pdo);
        } catch (Throwable) {
            return null;
        }
    }
}
