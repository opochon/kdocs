<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Config;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIaNul;
use Ged\Classement\InfomaniakClient;
use Ged\Contrats\Classeur;
use Ged\Contrats\Depot;
use Ged\Ingestion\Imap\ConnexionDifferee;
use Ged\Ingestion\Imap\ImapBoite;
use PDO;

/**
 * Assemble le pilote d'ingestion depuis la configuration (.env). Deux portes d'entrée, pas plus :
 *
 *   INGESTION_DOSSIER=…                 dossier surveillé (scanner, synchronisation, glisser-déposer)
 *   INGESTION_IMAP_HOTE=…               adresse d'ingestion (on y envoie ou transfère ses courriels)
 *   INGESTION_IMAP_PORT=993  INGESTION_IMAP_UTILISATEUR=…  INGESTION_IMAP_MOT_DE_PASSE=…
 *   INGESTION_IMAP_EXPEDITEURS=moi@exemple.ch,@exemple.ch   (vide = tout expéditeur accepté)
 *   CMD4_PYTHON=…  CMD4_PRODUIT=…       moteur de découpe (ClearMyDocs v4)
 *
 * Gmail, Microsoft 365 et les règles de tri avancées passent par ClearMyMails, pas par ici.
 */
final class Fabrique
{
    /** @return list<Source> */
    public static function sources(Config $config, PDO $pdo): array
    {
        $travail = self::zoneTravail($config) . '/recus';
        $sources = [];
        if ($config->get('INGESTION_DOSSIER') !== '') {
            $sources[] = new SourceDossier($config->chemin('INGESTION_DOSSIER', ''), $travail, self::zoneTravail($config) . '/etat-dossier.json');
        }
        $hote = $config->get('INGESTION_IMAP_HOTE');
        if ($hote !== '') {
            $utilisateur = $config->get('INGESTION_IMAP_UTILISATEUR');
            $boite = new ConnexionDifferee(static fn () => new ImapBoite(
                $hote,
                (int) $config->get('INGESTION_IMAP_PORT', '993'),
                $utilisateur,
                $config->get('INGESTION_IMAP_MOT_DE_PASSE'),
                $config->get('INGESTION_IMAP_CHIFFREMENT', 'ssl'),
            ), strtolower($utilisateur . '@' . $hote));
            $expediteurs = array_values(array_filter(array_map('trim', explode(',', $config->get('INGESTION_IMAP_EXPEDITEURS')))));
            $sources[] = new SourceAdresse($boite, $pdo, $travail, $expediteurs);
        }

        // API d'entrée (secteur Jonctions) : reprise des fichiers déposés mais pas encore enregistrés après une panne.
        if (class_exists(\Ged\Jonctions\SourceApi::class)) {
            $sources[] = new \Ged\Jonctions\SourceApi(self::zoneTravail($config) . '/api', new Travaux($pdo), $pdo);
        }

        return $sources;
    }

    public static function pilote(Config $config, PDO $pdo, ?Depot $depot, ?Classeur $classeur = null): Pilote
    {
        $decoupeur = new Cmd4Decoupeur(
            $config->get('CMD4_PYTHON', 'python'),
            $config->get('CMD4_PRODUIT'),
            (int) $config->get('CMD4_DELAI_S', '900'),
            filter_var($config->get('CMD4_VISION_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        );

        return new Pilote(new Travaux($pdo), self::sources($config, $pdo), $decoupeur, $depot, $classeur, self::zoneTravail($config), $config);
    }

    /** Le classement de production utilise Infomaniak seulement quand ses clés l'autorisent. */
    public static function classeur(Config $config, PDO $pdo): Classeur
    {
        $ia = new InfomaniakClient($config);

        return new ClasseurGed($config, $pdo, $ia->disponible() ? $ia : new ClientIaNul());
    }

    public static function zoneTravail(Config $config): string
    {
        return $config->chemin('INGESTION_TRAVAIL', 'var/ingestion');
    }
}
