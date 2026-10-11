<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Classement\Habitudes;
use Ged\Classement\InfomaniakClient;
use Ged\Config;
use Ged\Ingestion\EditeurPdf;
use Ged\Ingestion\Fabrique;
use PDO;
use Throwable;

/**
 * Données de l'écran « Application » : UNIQUEMENT la configuration et l'état réels de l'installation.
 *
 * L'écran ne doit jamais afficher une valeur de maquette : le dossier et l'adresse d'ingestion disent
 * « configuré » ou « non configuré » (le chemin du dossier d'ingestion n'est jamais montré), l'agent
 * d'ingestion est la DERNIÈRE tâche réelle de la zone de travail de l'instance et son horodatage, la racine
 * et le modèle IA viennent de la configuration, l'état des connecteurs vient des appairages Jonctions
 * réellement posés. Une information absente le dit ; rien n'est inventé.
 */
final class Application
{
    /** Noms applicatifs sous lesquels un appairage K-Time peut vivre (aucun à ce jour : « non relié »). */
    private const K_TIME = ['k-time', 'ktime', 'k_time', 'k time', 'ktime'];

    /** Nom applicatif de ClearMyMails (jetons d'appareil créés par le secteur Jonctions). */
    private const CLEARMYMAILS = ['cmm', 'clearmymails', 'clear-my-mails'];

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
            // Base muette : l'écran nomme « aucune tâche » / « non configuré », jamais un 500.
        }

        return new self($config, $pdo);
    }

    /** @return array<string, mixed> */
    public function donnees(): array
    {
        return [
            'ingestion' => $this->ingestion(),
            'edition_pdf' => EditeurPdf::depuisConfig($this->config)->diagnostic(),
            'stockage' => $this->stockage(),
            'ia' => $this->ia(),
            'connecteurs' => [
                'ktime' => $this->connecteur(self::K_TIME),
                'cmm' => $this->connecteur(self::CLEARMYMAILS),
            ],
            'reconstruction' => $this->reconstruction(),
        ];
    }

    /** @return array{id:int,etat:string,total:int,traite:int,erreur:?string,absents:int,ecart:int,absents_noms:list<string>}|null */
    private function reconstruction(): ?array
    {
        if ($this->pdo === null) return null;
        try { return (new \Ged\Socle\ReconstructionIndex($this->config))->etat(); } catch (Throwable) { return null; }
    }

    /** @return array<string, mixed> */
    private function ingestion(): array
    {
        $dossierConfigure = trim($this->config->get('INGESTION_DOSSIER')) !== '';
        $hote = trim($this->config->get('INGESTION_IMAP_HOTE'));
        $utilisateur = trim($this->config->get('INGESTION_IMAP_UTILISATEUR'));
        $adresse = null;
        if ($hote !== '') {
            $adresse = $utilisateur !== '' ? $utilisateur . '@' . $hote : $hote;
        }

        return [
            'dossier_configure' => $dossierConfigure,
            'adresse_configuree' => $adresse !== null,
            'adresse' => $adresse,
            'derniere_tache' => $this->derniereTache(),
        ];
    }

    /**
     * La dernière tâche d'ingestion de CETTE installation : dans la zone de travail qu'elle a configurée
     * (la base peut être partagée entre plusieurs fonds de test), la plus récente en `maj_le`.
     *
     * @return array{nom:string, source:string, etat:string, le:string, affichage:string, il_y_a:string}|null
     */
    private function derniereTache(): ?array
    {
        if ($this->pdo === null) {
            return null;
        }
        $zone = rtrim(str_replace('\\', '/', Fabrique::zoneTravail($this->config)), '/');
        try {
            $lignes = $this->pdo->query('SELECT nom_origine, source, etat, maj_le, chemin FROM ingestion_travaux'
                . ' ORDER BY maj_le DESC, id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;   // base muette : l'écran dit « aucune tâche », jamais un 500
        }

        foreach ($lignes as $ligne) {
            $chemin = str_replace('\\', '/', (string) ($ligne['chemin'] ?? ''));
            if ($zone !== '' && $chemin !== $zone && !str_starts_with($chemin, $zone . '/')) {
                continue;
            }
            $le = (string) ($ligne['maj_le'] ?? '');

            return [
                'nom' => (string) ($ligne['nom_origine'] ?? ''),
                'source' => (string) ($ligne['source'] ?? ''),
                'etat' => (string) ($ligne['etat'] ?? ''),
                'le' => $le,
                'affichage' => $le !== '' ? date('d.m.Y H:i', (int) strtotime($le)) : '',
                'il_y_a' => $this->ilYA($le),
            ];
        }

        return null;
    }

    private function ilYA(string $le): string
    {
        $temps = strtotime($le);
        if ($temps === false) {
            return '';
        }
        $secondes = max(0, time() - $temps);
        if ($secondes < 90) {
            return 'à l’instant';
        }
        if ($secondes < 3600) {
            return 'il y a ' . intdiv($secondes, 60) . ' min';
        }
        if ($secondes < 86400) {
            return 'il y a ' . intdiv($secondes, 3600) . ' h';
        }

        return 'il y a ' . intdiv($secondes, 86400) . ' j';
    }

    /** @return array<string, string> */
    private function stockage(): array
    {
        return [
            'racine' => $this->config->get('STORAGE_ROOT', 'storage/documents'),
            // Règle du fonds (voir Ged\Socle\DepotFichiers::cheminRangement) : aucune invention, le vrai rangement.
            'rangement' => 'année / type / émetteur',
            'sans_type' => 'année / À classer',
        ];
    }

    /** @return array<string, mixed> */
    private function ia(): array
    {
        $ia = new InfomaniakClient($this->config);
        $habitude = $this->pdo !== null ? (new Habitudes($this->pdo))->seuil() : null;

        return [
            'actif' => $ia->disponible(),
            'fournisseur' => 'Infomaniak AI Tools (hébergé en Suisse)',
            'modele' => $ia->modele(),
            'delai' => (int) $this->config->get('INFOMANIAK_AI_TIMEOUT_CLASSIFY', '20'),
            'seuil' => (int) round(max(0.0, min(1.0, (float) $this->config->get('CLASSEMENT_SEUIL', '0.8'))) * 100),
            'habitude' => $habitude,
        ];
    }

    /**
     * État réel d'un connecteur : au moins un jeton d'appareil ou d'application ACTIF (non révoqué) portant
     * l'un de ses noms applicatifs. Un connecteur jamais appairé est « non relié », sans invention.
     *
     * @param list<string> $applications
     * @return array{relie:bool, appareils:int, applications:list<string>, derniere:?string}
     */
    private function connecteur(array $applications): array
    {
        $vide = ['relie' => false, 'appareils' => 0, 'applications' => [], 'derniere' => null];
        if ($this->pdo === null) {
            return $vide;
        }
        $noms = array_values(array_unique(array_filter(array_map(
            static fn (string $a): string => strtolower(trim($a)), $applications))));
        if ($noms === []) {
            return $vide;
        }
        $marqueurs = implode(',', array_fill(0, count($noms), '?'));
        try {
            $requete = $this->pdo->prepare('SELECT application, COUNT(*) n, MAX(derniere_utilisation) derniere'
                . " FROM jonctions_jetons WHERE revoque_le IS NULL AND nature IN ('appareil', 'application')"
                . " AND LOWER(application) IN ($marqueurs) GROUP BY application");
            $requete->execute($noms);
            $lignes = $requete->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $vide;
        }

        $total = 0;
        $derniere = null;
        $trouvees = [];
        foreach ($lignes as $ligne) {
            $total += (int) $ligne['n'];
            $trouvees[] = (string) $ligne['application'];
            if ($ligne['derniere'] !== null && ($derniere === null || (string) $ligne['derniere'] > $derniere)) {
                $derniere = (string) $ligne['derniere'];
            }
        }

        return ['relie' => $total > 0, 'appareils' => $total, 'applications' => $trouvees, 'derniere' => $derniere];
    }
}
