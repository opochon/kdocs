<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use Ged\Config;
use Ged\Socle\DepotFichiers;
use PDO;

/**
 * Fabrique du modele courrier : assemble les magasins (dossier `var/courrier`), le coffre, le journal et,
 * surtout, le PORT de boîte. En test (`APP_ENV=test` ou `COURRIER_IMAP_FAUX=1`), le port est un FAUX en
 * mémoire alimenté par `var/courrier/boite-fausse.json` : aucun réseau, aucun vrai serveur.
 */
final class Fabrique
{
    public static function dossierDonnees(Config $config): string
    {
        return $config->chemin('COURRIER_DONNEES', 'var/courrier');
    }

    public static function coffre(Config $config): Coffre
    {
        return Coffre::depuisConfig($config, self::dossierDonnees($config));
    }

    public static function comptes(Config $config): Comptes
    {
        return new Comptes(self::coffre($config), self::dossierDonnees($config));
    }

    public static function regles(Config $config): Regles
    {
        return new Regles(self::dossierDonnees($config));
    }

    public static function traites(PDO $pdo): Traites
    {
        return new Traites($pdo);
    }

    public static function moteur(Config $config, PDO $pdo, ?\DateTimeImmutable $maintenant = null): Moteur
    {
        $zone = rtrim(str_replace('\\', '/', $config->chemin('INGESTION_TRAVAIL', 'var/ingestion')), '/') . '/courrier';

        return new Moteur(new DepotFichiers($config), new Traites($pdo), $zone, $maintenant ?? new \DateTimeImmutable());
    }

    /** Port injectable : faux en mémoire en test, adaptateur IMAP sinon. */
    public static function portPour(Config $config, Compte $compte): PortBoite
    {
        if ($config->get('APP_ENV') === 'test' || $config->get('COURRIER_IMAP_FAUX') === '1') {
            $fichier = self::dossierDonnees($config) . '/boite-fausse.json';

            return BoiteFausse::depuisFichier($fichier, $compte->nom);
        }

        return new BoiteImap($compte, self::coffre($config));
    }
}
