<?php

declare(strict_types=1);

namespace Ged\Edition;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use Throwable;

/**
 * Décide si le compte peut MODIFIER (donc ouvrir en édition) un document : même décision que la garde,
 * au dossier le plus précis. Sert à n'afficher « Ouvrir dans Word » qu'à qui a le droit.
 */
final class Droits
{
    /**
     * @param list<string> $permissions permissions de la session (le compte synthétique de test porte une permission globale)
     */
    public static function peutModifier(Config $config, ?int $compte, array $permissions, int $document): bool
    {
        if (in_array('*', $permissions, true)) {
            return true;
        }
        if (!in_array('documents:modifier', $permissions, true)) {
            return false;
        }
        // Compte synthétique des oracles : aucune ACL en base à résoudre, la permission globale décide.
        if ($compte === null) {
            return true;
        }
        try {
            $droits = new DroitsDossiers(Base::pdo($config));
            $dossier = $droits->dossierPourDocument($document);

            return $dossier !== null && $droits->autorise($compte, $dossier, 'modifier');
        } catch (Throwable $e) {
            // La base muette ne doit jamais ouvrir une porte : refus par défaut, tracé.
            error_log(sprintf('[GEDv2 edition] droit de modification illisible pour le document %d : %s', $document, $e->getMessage()));

            return false;
        }
    }
}
