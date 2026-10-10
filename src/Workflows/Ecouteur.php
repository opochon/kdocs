<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use PDO;

/**
 * Point d'accroche du Socle : « un document vient d'être déposé ou mis à jour ». Le Socle appelle
 * `recevoir()` APRÈS avoir rangé et indexé le document ; le moteur applique alors les règles du
 * déclencheur.
 *
 * Garantie absolue : `recevoir()` ne lève JAMAIS. Une règle en erreur est journalisée, le document
 * continue. C'est ce qui autorise le Socle à l'appeler sans envelopper l'appel dans un try/catch.
 */
final class Ecouteur
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly Journal $journal,
    ) {
    }

    public static function depuis(Config $config, ?PDO $pdo = null, ?Horloge $horloge = null): self
    {
        $pdo ??= Base::pdo($config);

        return new self(new Moteur($pdo, new DepotFichiers($config), $horloge), new Journal($pdo));
    }

    /**
     * Appelé par le Socle après un dépôt ou une mise à jour. Ne lève jamais.
     *
     * @param array<string, mixed> $fiche métadonnées du document (le `.meta.json` canonique)
     * @return array<string, mixed> compte rendu de l'exécution (toujours exploitable)
     */
    public function recevoir(string $evenement, int $idDocument, array $fiche, string $auteur = 'systeme', ?int $compte = null, bool $administrateur = false): array
    {
        $fiche['id'] = $idDocument;
        try {
            return $this->moteur->executer($evenement, $fiche, $auteur, $compte, $administrateur);
        } catch (\Throwable $e) {
            $this->journaliserErreur($idDocument, $evenement, $e->getMessage());

            return ['declencheur' => $evenement, 'document_id' => $idDocument, 'regles' => [], 'etapes' => [], 'ecritures' => 0,
                'erreurs' => [['regle_id' => null, 'regle' => '(moteur)', 'cause' => $e->getMessage()]]];
        }
    }

    /**
     * Raccourci statique pour un appelant qui n'a pas d'instance (le Socle). Ne lève jamais, même si la
     * configuration, la base ou le schéma sont indisponibles : l'ingestion ne doit jamais s'arrêter ici.
     *
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public static function prevenir(Config $config, string $evenement, int $idDocument, array $fiche, string $auteur = 'systeme', ?int $compte = null, bool $administrateur = false): array
    {
        try {
            return self::depuis($config)->recevoir($evenement, $idDocument, $fiche, $auteur, $compte, $administrateur);
        } catch (\Throwable $e) {
            return ['declencheur' => $evenement, 'document_id' => $idDocument, 'regles' => [], 'etapes' => [], 'ecritures' => 0,
                'erreurs' => [['regle_id' => null, 'regle' => '(branchement)', 'cause' => $e->getMessage()]]];
        }
    }

    private function journaliserErreur(int $idDocument, string $evenement, string $cause): void
    {
        try {
            $this->journal->ajouter(['document_id' => $idDocument, 'genre' => 'erreur', 'auteur' => 'moteur',
                'horodatage' => $this->moteur->horloge()->maintenant(),
                'details' => ['declencheur' => $evenement, 'cause' => $cause]]);
        } catch (\Throwable) {
            // La journalisation elle-même ne doit jamais remonter : le document continue.
        }
    }
}
