<?php

declare(strict_types=1);

namespace Ged\Recherche;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Adaptateur de l'écran /recherche : traduit la query string en `Requete`, appelle le moteur et met le
 * résultat en forme pour le gabarit existant (mêmes classes et mêmes data-testid : le système visuel ne
 * change pas). Quand la base ne répond pas, l'erreur est transmise nommée — jamais une liste vide muette.
 */
final class Ecran
{
    public function __construct(
        private readonly Moteur $moteur,
        private readonly ?PDO $pdo = null,
    ) {
    }

    /** Construit l'écran réel depuis la racine du projet (la config vient de l'environnement). */
    public static function depuisRacine(?string $racine = null, ?int $compte = null, bool $administrateur = false): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));
        $pdo = Base::pdo($config);

        return new self(new Service($pdo, $compte, $administrateur), $pdo);
    }

    /**
     * @param array<string, mixed> $parametres (query string de /recherche)
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf = ''): array
    {
        $requete = Requete::depuisParametres($parametres);
        $resultat = $this->moteur->rechercher($requete);

        $resultats = [];
        foreach ($resultat->trouvailles as $trouvaille) {
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
            'resultats' => $resultats,
            'total' => $resultat->total,
            'duree' => number_format($resultat->dureeMs / 1000, 2, ',', ' '),
            'mode' => $resultat->mode,
            'erreur' => $resultat->erreur === null ? null : [
                'etape' => $resultat->erreur->etape,
                'cause' => $resultat->erreur->cause,
                'reference' => $resultat->erreur->reference,
            ],
            'filtres' => $filtres,
            'filtres_actifs' => array_filter($filtres, static fn (?string $v): bool => $v !== null) !== [],
            'types_filtre' => $this->typesFiltre(),
            'csrf' => $csrf,
        ];
    }

    /** @return list<string> types présents dans l'index, pour le filtre ; vide si la base ne répond pas */
    private function typesFiltre(): array
    {
        if ($this->pdo === null) {
            return [];
        }
        try {
            $types = $this->pdo->query("SELECT DISTINCT type FROM recherche_textes WHERE type IS NOT NULL AND type <> ''"
                . ' ORDER BY type')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return [];
        }

        return array_map('strval', $types);
    }
}
