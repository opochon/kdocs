<?php

declare(strict_types=1);

namespace Ged\Recherche;

use Ged\Base;
use Ged\Config;
use Ged\Securite\DroitsDossiers;
use PDO;

/**
 * Adaptateur de l'écran /recherche : traduit la query string en `Requete`, appelle le moteur et met le
 * résultat en forme pour le gabarit existant (mêmes classes et mêmes data-testid : le système visuel ne
 * change pas). Quand la base ne répond pas, l'erreur est transmise nommée — jamais une liste vide muette.
 *
 * `?semblable=<id>` bascule l'écran sur la liste des documents semblables à un document (parité Paperless
 * `more like this`), servie par `Semblables`. Aucune route nouvelle : la liste vit dans l'écran Recherche,
 * et les droits de lecture sont appliqués DANS la requête du moteur.
 */
final class Ecran
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly ?PDO $pdo = null,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
    ) {
    }

    /** Construit l'écran réel depuis la racine du projet (la config vient de l'environnement). */
    public static function depuisRacine(?string $racine = null, ?int $compte = null, bool $administrateur = false): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));
        $pdo = Base::pdo($config);

        return new self(new Service($pdo, $compte, $administrateur), $pdo, $compte, $administrateur);
    }

    /**
     * @param array<string, mixed> $parametres (query string de /recherche)
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf = ''): array
    {
        $semblable = (int) ($parametres['semblable'] ?? 0);
        if ($semblable > 0) {
            return $this->donneesSemblables($semblable, $csrf);
        }

        $requete = Requete::depuisParametres($parametres);
        $resultat = $this->moteur->rechercher($requete);

        $filtres = [
            'type' => $requete->type,
            'emetteur' => $requete->emetteur,
            'date_debut' => $requete->dateDebut,
            'date_fin' => $requete->dateFin,
            'montant_min' => $requete->montantMin === null ? null : (string) $requete->montantMin,
            'montant_max' => $requete->montantMax === null ? null : (string) $requete->montantMax,
        ];

        return [
            'q' => $requete->texte,
            'resultats' => $this->resultats($resultat->trouvailles),
            'total' => $resultat->total,
            'duree' => number_format($resultat->dureeMs / 1000, 2, ',', ' '),
            'mode' => $resultat->mode,
            'erreur' => $this->erreur($resultat->erreur),
            'filtres' => $filtres,
            'filtres_actifs' => array_filter($filtres, static fn (?string $v): bool => $v !== null) !== [],
            'types_filtre' => $this->typesFiltre(),
            'csrf' => $csrf,
            'semblable' => null,
            'semblables_actifs' => false,
        ];
    }

    /**
     * Liste des documents semblables à `$documentId`, dans le même gabarit de résultats que la recherche.
     * Un document inconnu ou hors droits donne un état vide nommé (`semblable` à null) ; une panne SQL
     * donne l'erreur nommée habituelle, jamais les deux confondus.
     *
     * @return array<string, mixed>
     */
    private function donneesSemblables(int $documentId, string $csrf): array
    {
        $base = [
            'q' => '',
            'resultats' => [],
            'total' => 0,
            'duree' => '0,00',
            'mode' => Semblables::MODE,
            'erreur' => null,
            'filtres' => ['type' => null, 'emetteur' => null, 'date_debut' => null, 'date_fin' => null,
                'montant_min' => null, 'montant_max' => null],
            'filtres_actifs' => false,
            'types_filtre' => [],
            'csrf' => $csrf,
            'semblable' => null,
            'semblables_actifs' => true,
        ];

        if ($this->pdo === null) {
            return ['erreur' => $this->erreurBaseIndisponible()] + $base;
        }

        try {
            $moteur = new Semblables($this->pdo, $this->compte, $this->administrateur);
            $source = $moteur->source($documentId);
            if ($source === null) {
                return $base;   // inconnu ou non lisible : une absence, pas une panne
            }
            $resultat = $moteur->pour($documentId, Semblables::LIMITE_DEFAUT);

            return [
                'semblable' => ['id' => $source['document_id'], 'titre' => $source['titre']],
                'resultats' => $this->resultats($resultat->trouvailles),
                'total' => $resultat->total,
                'duree' => number_format($resultat->dureeMs / 1000, 2, ',', ' '),
                'mode' => $resultat->mode,
                'erreur' => $this->erreur($resultat->erreur),
            ] + $base;
        } catch (\PDOException $erreur) {
            return ['erreur' => $this->erreur(ErreurSql::nommer($erreur, 'documents semblables'))] + $base;
        }
    }

    /**
     * Mise en forme commune des trouvailles pour le gabarit (recherche et semblables).
     *
     * @param list<Trouvaille> $trouvailles
     * @return list<array<string, mixed>>
     */
    private function resultats(array $trouvailles): array
    {
        $resultats = [];
        foreach ($trouvailles as $trouvaille) {
            $resultats[] = [
                'id' => $trouvaille->id,
                'titre' => $trouvaille->titre,
                'type' => $trouvaille->type,
                'emetteur' => $trouvaille->emetteur,
                'date' => $trouvaille->date,
                'montant' => $trouvaille->montant,
                'segments' => $trouvaille->titreSegments,
                'titre_segments' => $trouvaille->titreSegments,
                'extraits' => $trouvaille->extraits,
                'pertinence' => round($trouvaille->pertinence, 2),
            ];
        }

        return $resultats;
    }

    /** @return array{etape:string, cause:string, reference:string}|null */
    private function erreur(?ErreurSql $erreur): ?array
    {
        return $erreur === null ? null : [
            'etape' => $erreur->etape,
            'cause' => $erreur->cause,
            'reference' => $erreur->reference,
        ];
    }

    /** @return array{etape:string, cause:string, reference:string} */
    private function erreurBaseIndisponible(): array
    {
        return [
            'etape' => 'documents semblables',
            'cause' => 'base de données indisponible',
            'reference' => substr(hash('sha256', 'semblables:sans-base:' . microtime()), 0, 10),
        ];
    }

    /** @return list<string> types présents dans l'index LISIBLE par le compte, pour le filtre ; vide si erreur */
    private function typesFiltre(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $where = "t.type IS NOT NULL AND t.type <> ''";
            $params = [];
            $jointure = '';
            if ($this->compte !== null && !$this->administrateur) {
                // Le filtre Type ne révèle un type que si un document lisible le porte (V2-13, point B3).
                $jointure = ' INNER JOIN documents d ON d.id = t.document_id';
                [$predicat, $aclParams] = DroitsDossiers::predicatLecture('d', $this->compte);
                $where .= ' AND ' . $predicat;
                $params = $aclParams;
            }
            $requete = $this->pdo->prepare('SELECT DISTINCT t.type FROM recherche_textes t' . $jointure
                . ' WHERE ' . $where . ' ORDER BY t.type');
            $requete->execute($params);
            $types = $requete->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return [];
        }

        return array_map('strval', $types);
    }
}
