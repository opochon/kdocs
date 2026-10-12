<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Taches;
use Ged\Socle\Vues;
use PDO;
use Throwable;

/**
 * Écran « Tableau de bord » (modèle Paperless `DashboardComponent`, lot ged-parite-ecrans-1).
 *
 * Paperless compose son tableau de bord de widgets (vues enregistrées, statistiques, dépôt) ;
 * GEDv2 montre ce que l'utilisateur doit TRAITER, dans l'ordre du quotidien :
 *   - les documents à classer (statut `a_controler` du fonds, le dossier fait foi) ;
 *   - les traitements en échec non acquittés ;
 *   - les courriers récents relevés par l'ingestion ;
 *   - les vues enregistrées épinglées au tableau de bord.
 *
 * Tout est en LECTURE : l'ouverture de l'écran n'écrit jamais, ni dans le fonds ni dans l'index.
 * Une panne (base muette, registre illisible) rend la section vide et NOMMÉE, jamais un 500.
 */
final class TableauDeBord
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
            // Base muette : l'écran nomme « 0 » et « aucune », jamais un 500.
        }

        return new self($config, $pdo);
    }

    /**
     * @return array{
     *   ecran:string,
     *   a_classer:array{total:int, apercu:list<array{id:int, titre:string, chemin:string}>},
     *   taches:array{total:int, apercu:list<array{id:int, fichier:string, resultat:string}>},
     *   courriers:list<array{sujet:string, statut:string, dossier:string, date:string}>,
     *   vues:list<array{id:int, nom:string}>
     * }
     */
    public function donnees(): array
    {
        return [
            'ecran' => 'tableau-de-bord',
            'a_classer' => $this->aClasser(),
            'taches' => $this->tachesEnEchec(),
            'courriers' => $this->courriersRecents(),
            'vues' => $this->vuesEpinglees(),
        ];
    }

    /** @return array{total:int, apercu:list<array{id:int, titre:string, chemin:string}>} */
    private function aClasser(): array
    {
        if ($this->pdo === null) {
            return ['total' => 0, 'apercu' => []];
        }
        try {
            $depot = new DepotFichiers($this->config);
            // Le tableau de bord montre les pièces ARRIVÉES RÉCEMMENT (l'ordre de la file d'À traiter
            // reste, lui, du plus ancien au plus récent) : on borne la lecture puis on prend la queue.
            $lignes = $depot->listerParStatut('a_controler', 200);
            $apercu = [];
            foreach (array_slice($lignes, -5) as $ligne) {
                $apercu[] = [
                    'id' => (int) ($ligne['id'] ?? 0),
                    'titre' => (string) ($ligne['titre'] ?? ''),
                    'chemin' => (string) ($ligne['chemin_relatif'] ?? ''),
                ];
            }

            return ['total' => $depot->compterParStatut('a_controler'), 'apercu' => $apercu];
        } catch (Throwable) {
            return ['total' => 0, 'apercu' => []];
        }
    }

    /** @return array{total:int, apercu:list<array{id:int, fichier:string, resultat:string}>} */
    private function tachesEnEchec(): array
    {
        try {
            $echecs = [];
            foreach ((new Taches($this->config))->lister(100) as $tache) {
                if (($tache['status'] ?? '') === Taches::FAILURE && ($tache['acknowledged'] ?? false) !== true) {
                    $echecs[] = [
                        'id' => (int) ($tache['id'] ?? 0),
                        'fichier' => (string) ($tache['task_file_name'] ?? ''),
                        'resultat' => (string) ($tache['result'] ?? ''),
                    ];
                }
            }

            return ['total' => count($echecs), 'apercu' => array_slice($echecs, 0, 5)];
        } catch (Throwable) {
            return ['total' => 0, 'apercu' => []];
        }
    }

    /** @return list<array{sujet:string, statut:string, dossier:string, date:string}> */
    private function courriersRecents(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $lignes = $this->pdo->query('SELECT sujet, statut, dossier, traite_le, recu_le FROM ingestion_messages'
                . ' ORDER BY COALESCE(traite_le, recu_le) DESC, id DESC LIMIT 5')->fetchAll();
        } catch (Throwable) {
            return [];   // table absente (première installation) : aucune invention
        }

        $courriers = [];
        foreach ($lignes as $ligne) {
            $date = (string) ($ligne['traite_le'] ?? $ligne['recu_le'] ?? '');
            $courriers[] = [
                'sujet' => (string) ($ligne['sujet'] ?? 'courrier sans objet'),
                'statut' => (string) ($ligne['statut'] ?? 'reçu'),
                'dossier' => (string) ($ligne['dossier'] ?? ''),
                'date' => $date !== '' ? date('d.m.Y H:i', (int) strtotime($date)) : '',
            ];
        }

        return $courriers;
    }

    /** @return list<array{id:int, nom:string}> */
    private function vuesEpinglees(): array
    {
        try {
            $vues = [];
            foreach ((new Vues($this->config))->tableauDeBord() as $vue) {
                $vues[] = ['id' => (int) $vue['id'], 'nom' => (string) $vue['nom']];
            }

            return $vues;
        } catch (Throwable) {
            return [];
        }
    }
}
