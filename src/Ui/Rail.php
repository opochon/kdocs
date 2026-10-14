<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Config;
use Ged\Socle\Fichiers;
use Ged\Socle\Vues;
use Throwable;

/**
 * Données du rail de navigation, calculées à UN SEUL endroit pour tous les écrans :
 *
 * - les entrées PRINCIPALES (les six écrans maîtres + « Partagés avec moi ») ;
 * - la section ADMINISTRATION, montrée seulement aux rôles qui ont la permission
 *   `application:administrer` (constat de découvrabilité du lot ged-personas : ces écrans n'avaient
 *   aucun lien visible) ;
 * - le compteur « À traiter » est la FILE RÉELLE du fonds (documents `a_controler` dont le fichier existe,
 *   le dossier fait foi) — jamais un compteur d'écran différent d'un autre ;
 * - les VUES listées sont les vues réellement ENREGISTRÉES du fonds (`.ged/vues.json`), avec leur slug ;
 *   la barre latérale ne connaît donc aucun lien figé.
 *
 * Toute panne (base muette, registre illisible) rend la forme vide : le rail reste lisible, jamais un 500.
 */
final class Rail
{
    /** @param list<string> $permissions permissions de la session courante ('' si inconnues) */
    public function __construct(
        private readonly Config $config,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
        private readonly array $permissions = [],
    ) {
    }

    /** @param list<string> $permissions */
    public static function depuisRacine(?string $racine = null, ?int $compte = null, bool $administrateur = false, array $permissions = []): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)), $compte, $administrateur, $permissions);
    }

    /**
     * @return array{
     *   compte_a_traiter:int,
     *   vues:list<array{id:int, nom:string, slug:string}>,
     *   principales:list<array{id:string, libelle:string, icone:string, url:string, compte:?int}>,
     *   sections:list<array{titre:string, entrees:list<array{id:string, libelle:string, icone:string, url:string}>}>
     * }
     */
    public function donnees(): array
    {
        $compte = $this->compteATraiter();

        return [
            'compte_a_traiter' => $compte,
            'vues' => $this->vues(),
            'principales' => $this->principales($compte),
            'sections' => $this->sections(),
        ];
    }

    /** Nombre de pièces réellement en attente de contrôle dans CE fonds, visibles par ce compte. */
    private function compteATraiter(): int
    {
        try {
            return ATraiter::depuisRacine($this->config->racine(), $this->compte, $this->administrateur)->compterAControler();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Entrées toujours présentes, dans l'ordre du plan. Les identifiants (`data-testid`) sont ceux des
     * parcours d'écran : la couture de menu ne change rien au contrat visuel.
     *
     * @return list<array{id:string, libelle:string, icone:string, url:string, compte:?int}>
     */
    private function principales(int $compte): array
    {
        return [
            // Lot ged-parite-ecrans-1 : le tableau de bord ouvre la journée (modèle Paperless Dashboard).
            ['id' => 'tableau-de-bord', 'libelle' => 'Tableau de bord', 'icone' => 'inbox', 'url' => '/tableau-de-bord', 'compte' => null],
            ['id' => 'a-traiter', 'libelle' => 'À traiter', 'icone' => 'inbox', 'url' => '/a-traiter', 'compte' => $compte],
            ['id' => 'bibliotheque', 'libelle' => 'Bibliothèque', 'icone' => 'biblio', 'url' => '/bibliotheque', 'compte' => null],
            ['id' => 'recherche', 'libelle' => 'Recherche', 'icone' => 'loupe', 'url' => '/recherche', 'compte' => null],
            ['id' => 'partages', 'libelle' => 'Partagés avec moi', 'icone' => 'partage', 'url' => '/partages-avec-moi', 'compte' => null],
            // Lot ged-mfiles-espace-1 : espace personnel (favoris et récemment consultés).
            ['id' => 'favoris', 'libelle' => 'Mon espace', 'icone' => 'favori', 'url' => '/favoris', 'compte' => null],
            ['id' => 'archives', 'libelle' => 'Archives', 'icone' => 'versions', 'url' => '/archives', 'compte' => null],
            ['id' => 'workflows', 'libelle' => 'Workflows', 'icone' => 'flux', 'url' => '/workflows', 'compte' => null],
        ];
    }

    /**
     * Sections réservées à un droit. Aujourd'hui : l'administration (types, comptes, groupes, rôles),
     * montrée seulement au rôle qui a `application:administrer` — jamais un lien mort pour les autres.
     *
     * @return list<array{titre:string, entrees:list<array{id:string, libelle:string, icone:string, url:string}>}>
     */
    private function sections(): array
    {
        $sections = [];
        if ($this->peut('application:administrer')) {
            $sections[] = ['titre' => 'Administration', 'entrees' => [
                ['id' => 'metadonnees', 'libelle' => 'Métadonnées', 'icone' => 'vue', 'url' => '/metadonnees'],
                // Lot ged-mfiles-oracle-1 : veille du serveur de fichiers (versions, écarts hors GED).
                ['id' => 'depot-fileserver', 'libelle' => 'Dépôt fichier', 'icone' => 'versions', 'url' => '/depot-fileserver'],
                ['id' => 'journaux', 'libelle' => 'Journaux', 'icone' => 'versions', 'url' => '/journaux'],
                ['id' => 'corbeille', 'libelle' => 'Corbeille', 'icone' => 'corbeille', 'url' => '/corbeille'],
                ['id' => 'comptes', 'libelle' => 'Comptes', 'icone' => 'appareil', 'url' => '/administration/comptes'],
                ['id' => 'groupes', 'libelle' => 'Groupes', 'icone' => 'partage', 'url' => '/administration/groupes'],
                ['id' => 'roles', 'libelle' => 'Rôles', 'icone' => 'reglages', 'url' => '/administration/roles'],
                // Lot ged-mfiles-permissions-1 : règles de permission dynamiques (classe / métadonnée / état).
                ['id' => 'permissions', 'libelle' => 'Permissions', 'icone' => 'reglages', 'url' => '/permissions'],
            ]];
        }

        return $sections;
    }

    private function peut(string $permission): bool
    {
        return $this->administrateur || in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }

    /** @return list<array{id:int, nom:string, slug:string}> */
    private function vues(): array
    {
        try {
            $vues = [];
            foreach ((new Vues($this->config))->lister() as $vue) {
                $vues[] = ['id' => $vue['id'], 'nom' => $vue['nom'], 'slug' => Fichiers::slug($vue['nom'])];
            }

            return $vues;
        } catch (Throwable) {
            return [];
        }
    }
}
