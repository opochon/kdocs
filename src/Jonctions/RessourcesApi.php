<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Courrier\Traites;
use Ged\Securite\Authentification;
use Ged\Securite\DroitsDossiers;
use Ged\Securite\Totp;
use Ged\Socle\Champs;
use Ged\Socle\Collaboration;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Etiquettes;
use Ged\Socle\Nomenclature;
use Ged\Socle\Preferences;
use Ged\Socle\Registre;
use Ged\Socle\Taches;
use Ged\Socle\Vues;
use PDO;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Throwable;

/**
 * Verbes REST des ressources de l'API de GEDv2 (lot ged-parite-api-3), même porte que `DocumentsApi` et
 * `PariteApi` : jeton `Authorization: Bearer …`, hors session, portées `documents_lecture` /
 * `documents_ecriture`. Aucune seconde API parallèle : chaque verbe réutilise le service du modèle GEDv2
 * (registre du fonds, nomenclature, ACL, collaboration, tâches, préférences) et son mode de droits.
 *
 *   Classement   : GET/POST/PUT/PATCH /api/v1/etiquettes[/{id}]   (Socle\Etiquettes)
 *                  GET/POST/PUT/PATCH /api/v1/types[/{id}]        (Socle\Nomenclature)
 *                  GET/POST/PUT/PATCH /api/v1/champs[/{id}]       (Socle\Champs)
 *                  GET/POST/PUT/PATCH /api/v1/vues[/{id}]         (Socle\Vues)
 *   Administration : GET/POST/PUT/PATCH /api/v1/comptes[/{id}]     (table `comptes`)
 *                    GET/POST/PUT/PATCH /api/v1/groupes[/{id}]     (table `securite_groupes`)
 *   Partage        : POST /api/v1/partages, DELETE /api/v1/partages/{id} (Socle\Collaboration)
 *                    GET /api/v1/documents/{id}/partages
 *   Compte         : GET/PATCH /api/v1/profil, POST /api/v1/jetons, GET/POST /api/v1/preferences
 *   Corbeille      : GET/POST /api/v1/corbeille                    (restauration seule : zéro suppression)
 *   Suivi          : GET /api/v1/taches[/{id}], GET /api/v1/courriers/traites/{id}
 *
 * Toute erreur est un JSON `{"erreur":{"etape","cause","reference"}}` : une erreur se nomme, jamais un 500 muet.
 */
final class RessourcesApi
{
    private readonly PDO $pdo;

    /** Natures GEDv2 par type de donnée Paperless (CustomField.FieldDataType). */
    private const NATURES_PAR_TYPE = [
        'string' => 'texte', 'text' => 'texte', 'integer' => 'nombre', 'float' => 'nombre',
        'monetary' => 'montant', 'date' => 'date', 'boolean' => 'booleen', 'url' => 'url',
        'select' => 'liste', 'documentlink' => 'lien',
    ];

    /** @var array{compte:int, identifiant:string, application:string, administrateur:bool}|null */
    private ?array $session = null;

    public function __construct(
        private readonly Config $config,
        private readonly ApiJeton $apiJeton,
    ) {
        $this->pdo = Base::pdo($config);
    }

    // -----------------------------------------------------------------------
    // Classement : étiquettes
    // -----------------------------------------------------------------------

    /** @param array<string, string> $args */
    public function etiquetteLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/etiquettes/$id",
            fn (): array => $this->etiquetteOuRefus($id, "GET /api/v1/etiquettes/$id"));
    }

    public function etiquetteCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/etiquettes';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            try {
                return $this->etiquetteOuRefus((new Etiquettes($this->config))->ajouter(
                    $this->texteRequis($corps, ['name', 'nom'], 'name', $etape),
                    $this->texteOptionnel($corps, ['color', 'couleur'], Etiquettes::COULEUR_DEFAUT),
                    $this->booleen($corps, ['is_inbox_tag', 'boite_reception']),
                )['id'], $etape);
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }
        }, 201);
    }

    /** @param array<string, string> $args */
    public function etiquetteRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireEtiquette($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function etiquetteModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireEtiquette($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireEtiquette(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/etiquettes/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->etiquetteOuRefus($id, $etape);
            $service = new Etiquettes($this->config);
            try {
                $nom = $complet ? $this->texteRequis($corps, ['name', 'nom'], 'name', $etape)
                    : $this->texteOptionnel($corps, ['name', 'nom'], '');
                if ($nom !== '') {
                    $service->renommer($id, $nom);
                }
                $couleur = $this->texteOptionnel($corps, ['color', 'couleur'], '');
                if ($couleur !== '') {
                    $service->definirCouleur($id, $couleur);
                }
                if (array_key_exists('is_inbox_tag', $corps) || array_key_exists('boite_reception', $corps)) {
                    $service->definirBoiteReception($id, $this->booleen($corps, ['is_inbox_tag', 'boite_reception']));
                }
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->etiquetteOuRefus($id, $etape);
        });
    }

    /** @return array{id:int, name:string, color:string, is_inbox_tag:bool} @throws ErreurApi */
    private function etiquetteOuRefus(int $id, string $etape): array
    {
        $etiquette = (new Etiquettes($this->config))->lire($id);
        if ($etiquette === null) {
            throw new ErreurApi(404, "étiquette introuvable : $id", $etape);
        }

        return ['id' => $etiquette['id'], 'name' => $etiquette['nom'], 'color' => $etiquette['couleur'],
            'is_inbox_tag' => $etiquette['boite_reception']];
    }

    // -----------------------------------------------------------------------
    // Classement : types de documents
    // -----------------------------------------------------------------------

    /** @param array<string, string> $args */
    public function typeLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/types/$id",
            fn (): array => $this->typeOuRefus($id, "GET /api/v1/types/$id"));
    }

    public function typeCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/types';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $nomenclature = new Nomenclature($this->config);
            $nomenclature->assurer();
            try {
                $id = $nomenclature->ajouter($this->texteRequis($corps, ['name', 'nom'], 'name', $etape));
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->typeOuRefus($id, $etape);
        }, 201);
    }

    /** @param array<string, string> $args */
    public function typeRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireType($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function typeModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireType($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireType(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/types/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->typeOuRefus($id, $etape);
            $nomenclature = new Nomenclature($this->config);
            try {
                $nom = $complet ? $this->texteRequis($corps, ['name', 'nom'], 'name', $etape)
                    : $this->texteOptionnel($corps, ['name', 'nom'], '');
                if ($nom !== '') {
                    $nomenclature->renommer($id, $nom);
                }
                if (array_key_exists('active', $corps) || array_key_exists('actif', $corps)) {
                    $nomenclature->definirActif($id, $this->booleen($corps, ['active', 'actif']));
                }
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->typeOuRefus($id, $etape);
        });
    }

    /** @return array{id:int, name:string, active:bool} @throws ErreurApi */
    private function typeOuRefus(int $id, string $etape): array
    {
        $nomenclature = new Nomenclature($this->config);
        $nomenclature->assurer();
        foreach ($nomenclature->lister(false) as $type) {
            if ($type['id'] === $id) {
                return ['id' => $type['id'], 'name' => $type['nom'], 'active' => $type['actif']];
            }
        }
        throw new ErreurApi(404, "type introuvable : $id", $etape);
    }

    // -----------------------------------------------------------------------
    // Classement : champs personnalisés
    // -----------------------------------------------------------------------

    /** @param array<string, string> $args */
    public function champLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/champs/$id",
            fn (): array => $this->champOuRefus($id, "GET /api/v1/champs/$id"));
    }

    public function champCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/champs';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $nomenclature = new Nomenclature($this->config);
            $nomenclature->assurer();
            $typeId = $this->entier($corps, ['type_id', 'type'], 0);
            if ($typeId < 1) {
                $types = $nomenclature->lister();
                $typeId = (int) ($types[0]['id'] ?? 1);
            }
            $nature = $this->texteOptionnel($corps, ['nature'], '');
            if ($nature === '') {
                $nature = self::NATURES_PAR_TYPE[$this->texteOptionnel($corps, ['data_type'], 'string')] ?? 'texte';
            }
            try {
                return $this->champOuRefus((new Champs($this->config))->ajouter(
                    $typeId, $this->texteRequis($corps, ['name', 'nom'], 'name', $etape), $nature,
                    $this->optionsListe($corps),
                )['id'], $etape);
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }
        }, 201);
    }

    /** @param array<string, string> $args */
    public function champRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireChamp($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function champModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireChamp($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireChamp(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/champs/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->champOuRefus($id, $etape);
            $nom = $complet ? $this->texteRequis($corps, ['name', 'nom'], 'name', $etape)
                : $this->texteOptionnel($corps, ['name', 'nom'], '');
            $options = array_key_exists('select_options', $corps) ? $this->optionsListe($corps) : null;
            try {
                (new Champs($this->config))->modifier($id, $nom === '' ? null : $nom, $options);
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->champOuRefus($id, $etape);
        });
    }

    /**
     * @param array<string, mixed> $corps
     * @return list<string>
     */
    private function optionsListe(array $corps): array
    {
        $options = [];
        foreach (is_array($corps['select_options'] ?? null) ? $corps['select_options'] : [] as $option) {
            if (is_array($option)) {
                $libelle = $option['label'] ?? '';
                $options[] = (string) (is_scalar($libelle) ? $libelle : '');
            } elseif (is_scalar($option)) {
                $options[] = (string) $option;
            }
        }

        return array_values(array_filter($options, static fn (string $o): bool => $o !== ''));
    }

    /** @return array{id:int, name:string, cle:string, nature:string, select_options:list<string>} @throws ErreurApi */
    private function champOuRefus(int $id, string $etape): array
    {
        foreach ((new Champs($this->config))->tous() as $champ) {
            if ($champ['id'] === $id) {
                return ['id' => $champ['id'], 'name' => $champ['nom'], 'cle' => $champ['cle'],
                    'nature' => $champ['nature'], 'select_options' => $champ['options']];
            }
        }
        throw new ErreurApi(404, "champ introuvable : $id", $etape);
    }

    // -----------------------------------------------------------------------
    // Classement : vues enregistrées
    // -----------------------------------------------------------------------

    /** @param array<string, string> $args */
    public function vueLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/vues/$id",
            fn (): array => $this->vueOuRefus($id, "GET /api/v1/vues/$id"));
    }

    public function vueCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/vues';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $this->refuserFiltresPaperless($corps, $etape);
            try {
                $id = (new Vues($this->config))->enregistrer(
                    $this->texteRequis($corps, ['name', 'nom'], 'name', $etape), $corps, $this->auteur(),
                );
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->vueOuRefus($id, $etape);
        }, 201);
    }

    /** @param array<string, string> $args */
    public function vueRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireVue($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function vueModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireVue($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireVue(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/vues/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->refuserFiltresPaperless($corps, $etape);
            $this->vueOuRefus($id, $etape);
            if ($complet) {
                $this->remplacerVue($id, $corps, $etape);
            } else {
                try {
                    (new Vues($this->config))->modifierReglages($id, $corps, $this->auteur());
                } catch (\RuntimeException $e) {
                    throw new ErreurApi(400, $e->getMessage(), $etape, $e);
                }
            }

            return $this->vueOuRefus($id, $etape);
        });
    }

    /**
     * Remplacement complet d'une vue (PUT) : mêmes validateurs que `Socle\Vues` (filtres compilés,
     * réglages bornés), réécriture dans le même registre du fonds (`.ged/vues.json`). Aucun moteur parallèle.
     *
     * @param array<string, mixed> $corps
     * @throws ErreurApi
     */
    private function remplacerVue(int $id, array $corps, string $etape): void
    {
        $vues = new Vues($this->config);
        $existante = $vues->lire($id);
        if ($existante === null) {
            throw new ErreurApi(404, "vue introuvable : $id", $etape);
        }
        $nom = $this->texteOptionnel($corps, ['name', 'nom'], '');
        if ($nom === '') {
            $nom = (string) $existante['nom'];
        }
        try {
            $filtres = $vues->compiler($corps);
            $reglages = $vues->compilerReglages($corps);
        } catch (\RuntimeException $e) {
            throw new ErreurApi(400, $e->getMessage(), $etape, $e);
        }
        $auteur = $this->auteur();
        (new Registre($this->config))->modifier('vues', static function (array $etat) use ($id, $nom, $filtres, $reglages, $auteur): array {
            foreach ($etat as &$vue) {
                /** @var array<string, mixed> $vue */
                $vue = (array) $vue;
                if ((int) ($vue['id'] ?? 0) === $id) {
                    $vue['nom'] = $nom;
                    $vue['filtres'] = $filtres;
                    $vue['reglages'] = $reglages;
                    $vue['auteur'] = $auteur;
                }
            }

            return $etat;
        });
    }

    /** @return array<string, mixed> @throws ErreurApi */
    private function vueOuRefus(int $id, string $etape): array
    {
        foreach ((new Vues($this->config))->lister() as $vue) {
            if ($vue['id'] === $id) {
                return ['id' => $vue['id'], 'name' => $vue['nom'], 'filtres' => $vue['filtres'],
                    'reglages' => $vue['reglages'], 'filter_rules' => []];
            }
        }

        throw new ErreurApi(404, "vue introuvable : $id", $etape);
    }

    // -----------------------------------------------------------------------
    // Administration : comptes et groupes
    // -----------------------------------------------------------------------

    public function comptesLister(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/comptes', function (): array {
            $requete = $this->pdo->query('SELECT id, identifiant, role FROM comptes ORDER BY identifiant');

            return ['count' => $this->compterLignes('comptes'),
                'results' => array_map(fn (array $c): array => $this->compteResume($c),
                    $requete === false ? [] : $requete->fetchAll(PDO::FETCH_ASSOC))];
        }, true);
    }

    /** @param array<string, string> $args */
    public function compteLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/comptes/$id",
            fn (): array => $this->compteOuRefus($id, "GET /api/v1/comptes/$id"), true);
    }

    public function compteCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/comptes';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $identifiant = mb_strtolower($this->texteRequis($corps, ['username', 'identifiant', 'email'], 'username', $etape));
            if (filter_var($identifiant, FILTER_VALIDATE_EMAIL) === false) {
                throw new ErreurApi(400, "identifiant invalide : « $identifiant » (adresse attendue)", $etape);
            }
            $motDePasse = $this->texteRequis($corps, ['password', 'mot_de_passe'], 'password', $etape);
            if (strlen($motDePasse) < 12) {
                throw new ErreurApi(400, 'mot de passe : 12 caractères au moins', $etape);
            }
            $role = $this->roleDepuis($corps);
            $this->exigerRoleActif($role, $etape);
            $requete = $this->pdo->prepare('SELECT 1 FROM comptes WHERE identifiant = ?');
            $requete->execute([$identifiant]);
            if ($requete->fetchColumn() !== false) {
                throw new ErreurApi(400, "compte : « $identifiant » existe déjà", $etape);
            }
            $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
                ->execute([$identifiant, password_hash($motDePasse, PASSWORD_DEFAULT), $role]);

            return $this->compteOuRefus((int) $this->pdo->lastInsertId(), $etape);
        }, 201, true);
    }

    /** @param array<string, string> $args */
    public function compteRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireCompte($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function compteModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireCompte($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireCompte(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/comptes/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->compteOuRefus($id, $etape);
            $jeux = [];
            $valeurs = [];
            if (array_key_exists('password', $corps) || array_key_exists('mot_de_passe', $corps)) {
                $motDePasse = $this->texteRequis($corps, ['password', 'mot_de_passe'], 'password', $etape);
                if (strlen($motDePasse) < 12) {
                    throw new ErreurApi(400, 'mot de passe : 12 caractères au moins', $etape);
                }
                $jeux[] = 'empreinte = ?';
                $valeurs[] = password_hash($motDePasse, PASSWORD_DEFAULT);
            }
            if ($complet || array_key_exists('is_superuser', $corps) || array_key_exists('is_staff', $corps) || array_key_exists('role', $corps)) {
                $role = $this->roleDepuis($corps);
                $this->exigerRoleActif($role, $etape);
                $jeux[] = 'role = ?';
                $valeurs[] = $role;
            }
            if ($jeux !== []) {
                $valeurs[] = $id;
                $this->pdo->prepare('UPDATE comptes SET ' . implode(', ', $jeux) . ' WHERE id = ?')->execute($valeurs);
            }

            return $this->compteOuRefus($id, $etape);
        }, 200, true);
    }

    /** @return array<string, mixed> @throws ErreurApi */
    private function compteOuRefus(int $id, string $etape): array
    {
        $requete = $this->pdo->prepare('SELECT id, identifiant, role FROM comptes WHERE id = ?');
        $requete->execute([$id]);
        $compte = $requete->fetch(PDO::FETCH_ASSOC);
        if ($compte === false) {
            throw new ErreurApi(404, "compte introuvable : $id", $etape);
        }

        return $this->compteResume($compte);
    }

    /**
     * @param array<string, mixed> $compte
     * @return array<string, mixed>
     */
    private function compteResume(array $compte): array
    {
        $role = (string) $compte['role'];

        return ['id' => (int) $compte['id'], 'username' => (string) $compte['identifiant'],
            'identifiant' => (string) $compte['identifiant'], 'role' => $role,
            'is_superuser' => $role === 'administrateur', 'is_staff' => $role === 'administrateur'];
    }

    public function groupesLister(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/groupes', function (): array {
            $requete = $this->pdo->query('SELECT id, nom, role_code FROM securite_groupes ORDER BY nom');

            return ['count' => $this->compterLignes('securite_groupes'),
                'results' => array_map(fn (array $g): array => $this->groupeResume($g),
                    $requete === false ? [] : $requete->fetchAll(PDO::FETCH_ASSOC))];
        }, true);
    }

    /** @param array<string, string> $args */
    public function groupeLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/groupes/$id",
            fn (): array => $this->groupeOuRefus($id, "GET /api/v1/groupes/$id"), true);
    }

    public function groupeCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/groupes';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $nom = $this->texteRequis($corps, ['name', 'nom'], 'name', $etape);
            $role = $this->roleDepuis($corps);
            $this->exigerRoleActif($role, $etape);
            $requete = $this->pdo->prepare('SELECT 1 FROM securite_groupes WHERE nom = ?');
            $requete->execute([$nom]);
            if ($requete->fetchColumn() !== false) {
                throw new ErreurApi(400, "groupe : « $nom » existe déjà", $etape);
            }
            $this->pdo->prepare('INSERT INTO securite_groupes (nom, role_code) VALUES (?, ?)')->execute([$nom, $role]);

            return $this->groupeOuRefus((int) $this->pdo->lastInsertId(), $etape);
        }, 201, true);
    }

    /** @param array<string, string> $args */
    public function groupeRemplacer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireGroupe($requete, $reponse, (int) ($args['id'] ?? 0), true);
    }

    /** @param array<string, string> $args */
    public function groupeModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        return $this->ecrireGroupe($requete, $reponse, (int) ($args['id'] ?? 0), false);
    }

    private function ecrireGroupe(Requete $requete, Reponse $reponse, int $id, bool $complet): Reponse
    {
        $etape = ($complet ? 'PUT' : 'PATCH') . " /api/v1/groupes/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape, $complet): array {
            $this->groupeOuRefus($id, $etape);
            $nom = $complet ? $this->texteRequis($corps, ['name', 'nom'], 'name', $etape)
                : $this->texteOptionnel($corps, ['name', 'nom'], '');
            $role = '';
            if ($complet || array_key_exists('role', $corps) || array_key_exists('is_superuser', $corps)) {
                $role = $this->roleDepuis($corps);
                $this->exigerRoleActif($role, $etape);
            }
            $jeux = [];
            $valeurs = [];
            if ($nom !== '') {
                $jeux[] = 'nom = ?';
                $valeurs[] = $nom;
            }
            if ($role !== '') {
                $jeux[] = 'role_code = ?';
                $valeurs[] = $role;
            }
            if ($jeux !== []) {
                $valeurs[] = $id;
                $this->pdo->prepare('UPDATE securite_groupes SET ' . implode(', ', $jeux) . ' WHERE id = ?')->execute($valeurs);
            }

            return $this->groupeOuRefus($id, $etape);
        }, 200, true);
    }

    /** @return array<string, mixed> @throws ErreurApi */
    private function groupeOuRefus(int $id, string $etape): array
    {
        $requete = $this->pdo->prepare('SELECT id, nom, role_code FROM securite_groupes WHERE id = ?');
        $requete->execute([$id]);
        $groupe = $requete->fetch(PDO::FETCH_ASSOC);
        if ($groupe === false) {
            throw new ErreurApi(404, "groupe introuvable : $id", $etape);
        }

        return $this->groupeResume($groupe);
    }

    /**
     * @param array<string, mixed> $groupe
     * @return array<string, mixed>
     */
    private function groupeResume(array $groupe): array
    {
        return ['id' => (int) $groupe['id'], 'name' => (string) $groupe['nom'], 'nom' => (string) $groupe['nom'],
            'role' => (string) $groupe['role_code']];
    }

    // -----------------------------------------------------------------------
    // Partage : liens externes
    // -----------------------------------------------------------------------

    public function partageCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/partages';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $document = $this->entier($corps, ['document', 'document_id'], 0);
            if ($document < 1) {
                throw new ErreurApi(400, 'document requis', $etape);
            }
            $expiration = $this->texteOptionnel($corps, ['expiration'], '');
            if ($expiration === '') {
                $expiration = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
            } elseif (str_contains($expiration, 'T')) {
                $expiration = substr($expiration, 0, 10);
            }
            $version = $this->entier($corps, ['file_version'], 0);
            try {
                $resultat = (new Collaboration($this->config))->partagerExterne(
                    $document, $this->auteur(), $expiration, $version > 0 ? $version : null,
                );
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            // Le jeton n'est rendu qu'ICI, à sa création, et n'est jamais journalisé.
            return ['id' => $resultat['id'], 'document' => $document, 'slug' => $resultat['jeton'],
                'expiration' => $expiration, 'file_version' => $version > 0 ? $version : null];
        }, 201);
    }

    /** @param array<string, string> $args */
    public function partageLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);

        return $this->lireRessource($requete, $reponse, "GET /api/v1/partages/$id",
            fn (): array => $this->partageOuRefus($id, "GET /api/v1/partages/$id"));
    }

    /** @param array<string, string> $args */
    public function partageSupprimer(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = "DELETE /api/v1/partages/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function () use ($id, $etape): array {
            $this->partageOuRefus($id, $etape);
            try {
                (new Collaboration($this->config))->revoquerPartageExterne($id, $this->auteur());
            } catch (\RuntimeException $e) {
                throw new ErreurApi(409, $e->getMessage(), $etape, $e);
            }

            return ['id' => $id, 'revoque' => true];
        }, 204);
    }

    /**
     * `ShareLinkViewSet.update` / `partial_update` (PUT/PATCH) : modifie l'échéance d'un lien de partage
     * sans le révoquer. GEDv2 ne supprime rien : la mise à jour est tracée, la valeur reste bornée (date
     * future obligatoire). L'identifiant et le document d'origine ne changent jamais.
     *
     * @param array<string, string> $args
     */
    public function partageModifier(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = "PATCH /api/v1/partages/$id";

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($id, $etape): array {
            $this->partageOuRefus($id, $etape);
            $expiration = $this->texteOptionnel($corps, ['expiration', 'expire_le'], '');
            if ($expiration !== '') {
                $date = null;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiration) === 1) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $expiration) ?: null;
                } else {
                    try {
                        $date = new \DateTimeImmutable($expiration);
                    } catch (\Exception) {
                        $date = null;
                    }
                }
                if ($date === null || $date <= new \DateTimeImmutable('today')) {
                    throw new ErreurApi(400, 'expiration future obligatoire (AAAA-MM-JJ)', $etape);
                }
                $this->pdo->prepare("UPDATE document_collaboration SET expire_le = ? WHERE id = ? AND type = 'partage' AND type_partage = 'externe'")
                    ->execute([$date->format('Y-m-d H:i:s'), $id]);
            }
            $version = $this->entier($corps, ['file_version', 'version'], 0);
            if ($version > 0) {
                $this->pdo->prepare("UPDATE document_collaboration SET version = ? WHERE id = ? AND type = 'partage' AND type_partage = 'externe'")
                    ->execute([$version, $id]);
            }

            return $this->partageOuRefus($id, $etape);
        });
    }

    /** @param array<string, mixed> $args */
    public function partagesDocument(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $document = (int) ($args['id'] ?? 0);
        $etape = "GET /api/v1/documents/$document/partages";

        return $this->lireRessource($requete, $reponse, $etape, function (array $session) use ($document, $etape): array {
            (new Catalogue($this->config, $session['compte'], $session['administrateur']))->fiche($document, $etape);
            $partages = [];
            foreach ((new Collaboration($this->config))->partages($document) as $partage) {
                $partages[] = ['id' => (int) $partage['id'], 'document' => $document,
                    'type' => (string) $partage['type'], 'cible' => (string) $partage['cible'],
                    'version' => is_numeric($partage['version'] ?? null) ? (int) $partage['version'] : null,
                    'expiration' => $partage['expire_le'] ?? null, 'revoque' => ($partage['revoque_le'] ?? null) !== null];
            }

            return $partages;
        });
    }

    /** @return array<string, mixed> @throws ErreurApi */
    private function partageOuRefus(int $id, string $etape): array
    {
        $requete = $this->pdo->prepare("SELECT p.id, p.document_id, p.version, p.expire_le, p.revoque_le, d.titre"
            . " FROM document_collaboration p INNER JOIN documents d ON d.id = p.document_id"
            . " WHERE p.id = ? AND p.type = 'partage' AND p.type_partage = 'externe'");
        $requete->execute([$id]);
        $partage = $requete->fetch(PDO::FETCH_ASSOC);
        if ($partage === false) {
            throw new ErreurApi(404, "partage introuvable : $id", $etape);
        }

        return ['id' => (int) $partage['id'], 'document' => (int) $partage['document_id'],
            'titre' => (string) $partage['titre'],
            'version' => is_numeric($partage['version'] ?? null) ? (int) $partage['version'] : null,
            'expiration' => $partage['expire_le'] ?? null, 'revoque' => ($partage['revoque_le'] ?? null) !== null];
    }

    // -----------------------------------------------------------------------
    // Corbeille (TrashView) : lecture et restauration ; GEDv2 ne purge jamais.
    // -----------------------------------------------------------------------

    public function corbeilleLire(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/corbeille', function (array $session): array {
            $where = ['d.supprime_le IS NOT NULL'];
            $params = [];
            if (!$session['administrateur']) {
                [$acl, $aclParams] = DroitsDossiers::predicatLecture('d', $session['compte']);
                $where[] = $acl;
                $params = $aclParams;
            }
            $requete = $this->pdo->prepare('SELECT d.id, d.titre, d.chemin_relatif, d.supprime_le, d.supprime_par'
                . ' FROM documents d WHERE ' . implode(' AND ', $where) . ' ORDER BY d.supprime_le DESC, d.id DESC LIMIT 200');
            $requete->execute($params);
            $documents = [];
            foreach ($requete->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
                $documents[] = ['id' => (int) $ligne['id'], 'titre' => (string) $ligne['titre'],
                    'chemin' => (string) $ligne['chemin_relatif'], 'supprime_le' => (string) $ligne['supprime_le'],
                    'supprime_par' => (string) $ligne['supprime_par']];
            }

            return ['count' => count($documents), 'results' => $documents];
        }, true);
    }

    public function corbeilleAction(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/corbeille';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $action = $this->texteRequis($corps, ['action'], 'action', $etape);
            $ids = $this->listeIdentifiants($corps['documents'] ?? $corps['ids'] ?? []);
            if ($action === 'empty') {
                // Règle produit « zéro suppression » : la corbeille de GEDv2 ne purge jamais.
                throw new ErreurApi(409, 'la purge définitive est interdite : GEDv2 ne supprime aucune donnée utilisateur', $etape);
            }
            if ($action !== 'restore') {
                throw new ErreurApi(400, "action inconnue : « $action » (attendu : restore)", $etape);
            }
            $session = $this->session ?? throw new ErreurApi(403, 'session administrative indisponible', $etape);
            $depot = new DepotFichiers($this->config);
            $restaures = [];
            foreach ($ids as $id) {
                $cause = $this->documentEnCorbeille($id, $session);
                if ($cause !== 200) {
                    throw new ErreurApi($cause, "document $id : " . ($cause === 404 ? 'introuvable' : ($cause === 400 ? 'n’est pas à la corbeille' : 'droit modifier insuffisant')), $etape);
                }
                $depot->restaurer($id);
                $restaures[] = $id;
            }

            return ['action' => 'restore', 'documents' => $restaures];
        }, 200, true);
    }

    /** @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session */
    private function documentEnCorbeille(int $id, array $session): int
    {
        $requete = $this->pdo->prepare('SELECT supprime_le FROM documents WHERE id = ?');
        $requete->execute([$id]);
        $supprime = $requete->fetchColumn();
        if ($supprime === false) {
            return 404;
        }
        if ($supprime === null) {
            return 400;
        }
        if (!$session['administrateur']) {
            $droits = new DroitsDossiers($this->pdo);
            $dossier = $droits->dossierPourDocument($id);
            if ($dossier === null || !$droits->autorise($session['compte'], $dossier, 'modifier')) {
                return 403;
            }
        }

        return 200;
    }

    // -----------------------------------------------------------------------
    // Suivi : tâches et courriers traités
    // -----------------------------------------------------------------------

    public function tachesLister(Requete $requete, Reponse $reponse): Reponse
    {
        // Filtres PaperlessTaskFilterSet (lot api-4) : task_id, status, task_name, type, acknowledged.
        // Ils s'appliquent au suivi réel (`Socle\Taches`, complété par `ingestion_travaux`) sans file
        // parallèle ; un filtre inconnu est ignoré, jamais une erreur.
        $filtres = $requete->getQueryParams();

        return $this->lireRessource($requete, $reponse, 'GET /api/v1/taches', function () use ($filtres): array {
            $service = new Taches($this->config);
            $taches = $service->lister(500);
            if ($taches === []) {
                $taches = $service->depuisTravaux($this->pdo, 500);
            }
            $taches = $this->filtrerTaches($taches, $filtres);

            return ['count' => count($taches), 'results' => array_slice($taches, 0, 50)];
        }, true);
    }

    /**
     * @param list<array<string, mixed>> $taches
     * @param array<string, mixed> $filtres
     * @return list<array<string, mixed>>
     */
    private function filtrerTaches(array $taches, array $filtres): array
    {
        $gardees = [];
        foreach ($taches as $tache) {
            if (isset($filtres['task_id']) && (string) ($tache['task_id'] ?? '') !== (string) $filtres['task_id']) {
                continue;
            }
            if (isset($filtres['status']) && (string) ($tache['status'] ?? '') !== mb_strtoupper((string) $filtres['status'])) {
                continue;
            }
            if (isset($filtres['task_name']) && mb_strtoupper((string) ($tache['task_name'] ?? '')) !== mb_strtoupper((string) $filtres['task_name'])) {
                continue;
            }
            if (isset($filtres['type']) && mb_strtoupper((string) ($tache['type'] ?? '')) !== mb_strtoupper((string) $filtres['type'])) {
                continue;
            }
            if (isset($filtres['acknowledged'])) {
                $voulu = filter_var($filtres['acknowledged'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($voulu !== null && ($tache['acknowledged'] ?? false) !== $voulu) {
                    continue;
                }
            }
            $gardees[] = $tache;
        }

        return $gardees;
    }

    /**
     * `TasksViewSet.acknowledge` (Paperless) : acquitte les tâches listées et rend le nombre traité.
     * Réservé à l'administrateur (la liste `/api/v1/taches` l'est aussi dans GEDv2).
     */
    public function tachesAccuser(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/taches/acknowledge';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $ids = $this->listeIdentifiants($corps['tasks'] ?? $corps['ids'] ?? []);
            if ($ids === []) {
                throw new ErreurApi(400, 'aucune tâche à acquitter (clé « tasks » attendue)', $etape);
            }
            $service = new Taches($this->config);
            $resultat = 0;
            foreach ($ids as $id) {
                try {
                    $service->accuser($id, true);
                } catch (\RuntimeException $e) {
                    throw new ErreurApi(404, "tâche introuvable : $id", $etape, $e);
                }
                ++$resultat;
            }

            return ['result' => $resultat];
        }, 200, true);
    }

    /**
     * `TasksViewSet.run` (Paperless) : programme une tâche de maintenance connue. GEDv2 n'a aucun démon
     * Celery : la tâche est enregistrée dans le suivi partagé (`Socle\Taches`), reprise par un job court.
     * Réservé à l'administrateur (comme `superuser` côté Paperless).
     */
    public function tachesExecuter(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/taches/run';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $nom = mb_strtoupper($this->texteRequis($corps, ['task_name', 'nom'], 'task_name', $etape));
            try {
                $tache = (new Taches($this->config))->ouvrir($nom, Taches::TYPES[0], 'maintenance:' . $nom, $this->auteur());
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return ['result' => 'tâche enregistrée', 'task_id' => $tache['task_id'], 'task_name' => $tache['task_name']];
        }, 200, true);
    }

    /** @param array<string, string> $args */
    public function tacheLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = "GET /api/v1/taches/$id";

        return $this->lireRessource($requete, $reponse, $etape, function () use ($id, $etape): array {
            $service = new Taches($this->config);
            $tache = $service->etat($id);
            if ($tache === null) {
                foreach ($service->depuisTravaux($this->pdo, 500) as $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $id) {
                        $tache = $candidate;
                        break;
                    }
                }
            }
            if ($tache === null) {
                throw new ErreurApi(404, "tâche introuvable : $id", $etape);
            }

            return $tache;
        }, true);
    }

    /** @param array<string, string> $args */
    public function courrierTraiteLire(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $id = (int) ($args['id'] ?? 0);
        $etape = "GET /api/v1/courriers/traites/$id";

        return $this->lireRessource($requete, $reponse, $etape, function () use ($id, $etape): array {
            foreach ((new Traites($this->pdo))->journal(500) as $ligne) {
                if ((int) ($ligne['id'] ?? 0) === $id) {
                    return $ligne;
                }
            }

            throw new ErreurApi(404, "courrier traité introuvable : $id", $etape);
        }, true);
    }

    // -----------------------------------------------------------------------
    // Compte : profil, jetons, préférences d'interface
    // -----------------------------------------------------------------------

    public function profilLire(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/profil',
            fn (array $session): array => $this->profilEtat($session));
    }

    public function profilModifier(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'PATCH /api/v1/profil';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $session = $this->session;
            if ($session === null) {
                throw new ErreurApi(403, 'session indisponible', $etape);
            }
            $adresse = $this->texteOptionnel($corps, ['email', 'adresse'], '');
            if ($adresse !== '') {
                if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ErreurApi(400, "adresse invalide : « $adresse »", $etape);
                }
                (new Profil($this->pdo, Mailer::depuisConfig($this->config)))
                    ->definirAdresse($session['compte'], $session['identifiant'], $adresse);
            }

            return $this->profilEtat($session);
        });
    }

    /** @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session
     *  @return array<string, mixed> */
    private function profilEtat(array $session): array
    {
        $etat = (new Profil($this->pdo, Mailer::depuisConfig($this->config)))
            ->etat($session['compte'], $session['identifiant']);

        return ['id' => $session['compte'], 'username' => $session['identifiant']] + $etat;
    }

    public function jetonCreer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/jetons';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $session = $this->session;
            if ($session === null) {
                throw new ErreurApi(403, 'session indisponible', $etape);
            }
            $application = $this->texteOptionnel($corps, ['application', 'name'], 'api');
            $portee = $this->texteOptionnel($corps, ['portee', 'scope'], 'documents_lecture');
            if (!in_array($portee, ['documents_lecture', 'documents_ecriture'], true)) {
                throw new ErreurApi(400, "portée invalide : « $portee »", $etape);
            }
            // Valeur rendue UNE SEULE FOIS, jamais relue ni journalisée.
            return ['token' => (new Jetons($this->pdo))->creerPourCompte($application, $portee, $session['compte'], $session['identifiant']),
                'portee' => $portee, 'application' => $application];
        });
    }

    public function preferencesLire(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/preferences',
            fn (array $session): array => $this->preferencesEtat($session));
    }

    public function preferencesEnregistrer(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/preferences';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $session = $this->session;
            if ($session === null) {
                throw new ErreurApi(403, 'session indisponible', $etape);
            }
            $reglages = $corps['reglages'] ?? $corps['settings'] ?? null;
            if (!is_array($reglages)) {
                throw new ErreurApi(400, 'reglages : dictionnaire attendu', $etape);
            }
            try {
                (new Preferences($this->config))->enregistrer($session['identifiant'], $reglages);
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }

            return $this->preferencesEtat($session);
        });
    }

    /** @param array{compte:int, identifiant:string, application:string, administrateur:bool} $session
     *  @return array<string, mixed> */
    private function preferencesEtat(array $session): array
    {
        return ['user' => ['id' => $session['compte'], 'username' => $session['identifiant']],
            'settings' => (new Preferences($this->config))->lire($session['identifiant'])];
    }

    // -----------------------------------------------------------------------
    // Double authentification TOTP (TOTPView de Paperless, django-otp/allauth)
    // -----------------------------------------------------------------------

    /** `TOTPView.get` : enrôlement — un secret NEUF est rendu UNE fois, jamais relu une fois actif. */
    public function totpLire(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/profil/totp', function (array $session): array {
            $totp = new Totp($this->pdo);
            $etat = $totp->etat($session['compte']);
            if ($etat !== null && $etat['actif']) {
                return ['active' => true, 'secret' => null, 'otpauth_uri' => null, 'qr_svg' => null];
            }
            $secret = $totp->genererSecret();
            $uri = $totp->uri($secret, $session['identifiant']);

            return ['active' => false, 'secret' => $secret, 'otpauth_uri' => $uri, 'qr_svg' => $totp->svg($uri)];
        });
    }

    /** `TOTPView.post` : active après vérification du code ; les codes de secours ne sont rendus qu'ici. */
    public function totpActiver(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/profil/totp';

        return $this->ecrireRessource($requete, $reponse, $etape, function (array $corps) use ($etape): array {
            $session = $this->session;
            if ($session === null) {
                throw new ErreurApi(403, 'session indisponible', $etape);
            }
            $secret = strtoupper($this->texteRequis($corps, ['secret'], 'secret', $etape));
            $code = $this->texteRequis($corps, ['code'], 'code', $etape);
            $totp = new Totp($this->pdo);
            try {
                $valide = $totp->verifier($secret, $code);
            } catch (\RuntimeException $e) {
                throw new ErreurApi(400, $e->getMessage(), $etape, $e);
            }
            if (!$valide) {
                throw new ErreurApi(400, 'code de vérification invalide', $etape);
            }

            return ['active' => true, 'recovery_codes' => $totp->activer($session['compte'], $secret)];
        });
    }

    /** `TOTPView.delete` : désactive ; une seconde désactivation se nomme 404 (comme Paperless). */
    public function totpDesactiver(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'DELETE /api/v1/profil/totp';

        return $this->ecrireRessource($requete, $reponse, $etape, function () use ($etape): array {
            $session = $this->session;
            if ($session === null) {
                throw new ErreurApi(403, 'session indisponible', $etape);
            }
            if (!(new Totp($this->pdo))->desactiver($session['compte'])) {
                throw new ErreurApi(404, 'aucune double authentification active', $etape);
            }

            return ['active' => false];
        });
    }

    /** `UserViewSet.deactivate_totp` : un administrateur retire le TOTP d'un compte ; 404 si absent. */
    /** @param array<string, string> $args */
    public function compteDesactiverTotp(Requete $requete, Reponse $reponse, array $args): Reponse
    {
        $cible = (int) ($args['id'] ?? 0);
        $etape = "POST /api/v1/comptes/$cible/deactivate_totp";

        return $this->ecrireRessource($requete, $reponse, $etape, function () use ($cible, $etape): array {
            $this->compteOuRefus($cible, $etape);
            if (!(new Totp($this->pdo))->desactiver($cible)) {
                throw new ErreurApi(404, 'aucune double authentification active', $etape);
            }

            return ['id' => $cible, 'totp' => 'desactive'];
        }, 200, true);
    }

    // -----------------------------------------------------------------------
    // Jeton d'API par identifiants (PaperlessObtainAuthTokenView / GenerateAuthTokenView)
    // -----------------------------------------------------------------------

    /**
     * `POST /api/v1/token` : échange identifiant + mot de passe contre un jeton d'API, avec le code MFA
     * quand la double authentification est active. La valeur du jeton n'est rendue qu'ICI, jamais relue.
     * Route hors jeton (c'est elle qui le délivre) : aucune session, aucun cookie.
     */
    public function jetonObtenir(Requete $requete, Reponse $reponse): Reponse
    {
        $etape = 'POST /api/v1/token';
        try {
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $identifiant = trim((string) ($corps['username'] ?? $corps['identifiant'] ?? ''));
            $motDePasse = (string) ($corps['password'] ?? $corps['mot_de_passe'] ?? '');
            $utilisateur = (new Authentification($this->config, $this->pdo))->verifier($identifiant, $motDePasse);
            if ($utilisateur === null) {
                throw new ErreurApi(400, 'identifiant ou mot de passe incorrect', $etape);
            }
            if ($utilisateur['id'] === null) {
                throw new ErreurApi(403, 'le compte n’est lié à aucun identifiant : jeton impossible', $etape);
            }
            $totp = new Totp($this->pdo);
            $etat = $totp->etat($utilisateur['id']);
            if ($etat !== null && $etat['actif']) {
                $code = trim((string) ($corps['code'] ?? ''));
                if ($code === '') {
                    throw new ErreurApi(400, 'code MFA requis', $etape);
                }
                if (!$totp->verifier($etat['secret'], $code)) {
                    throw new ErreurApi(400, 'code MFA invalide', $etape);
                }
            }
            // Valeur rendue UNE SEULE FOIS, jamais journalisée.
            $jeton = (new Jetons($this->pdo))->creerPourCompte('api-token', 'documents_lecture',
                $utilisateur['id'], $utilisateur['identifiant']);

            return $this->json($reponse, 200, ['token' => $jeton, 'username' => $utilisateur['identifiant']]);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'le jeton n’a pas pu être créé');
        }
    }

    // -----------------------------------------------------------------------
    // État système (`SystemStatusView` de Paperless) et version distante (`RemoteVersionView`)
    // -----------------------------------------------------------------------

    /**
     * `GET /api/v1/statut` : état système réel de la GED (base, stockage, suivi des tâches), réservé à
     * l'administrateur comme `SystemStatusView`. Aucun démon n'est sondé : le suivi des tâches est celui
     * de la file `ingestion_travaux` + du registre local, sans Redis ni Celery.
     */
    public function statutSysteme(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/statut', function (): array {
            $racineStockage = $this->config->chemin('STORAGE_ROOT', 'storage/documents');
            $total = @disk_total_space($racineStockage);
            $libre = @disk_free_space($racineStockage);
            $bilan = (new Taches($this->config))->bilan();

            return [
                'app' => 'gedv2',
                'version' => $this->versionGed(),
                'server_os' => PHP_OS_FAMILY,
                'install_type' => 'mutualise',
                'storage' => [
                    'total' => $total === false ? null : (int) $total,
                    'available' => $libre === false ? null : (int) $libre,
                ],
                'database' => [
                    'type' => 'mariadb',
                    'base' => $this->config->get('DB_NAME', 'gedv2'),
                    'statut' => 'OK',
                    'serveur' => (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                ],
                'tasks' => $bilan + ['file' => 'ingestion_travaux', 'mode' => 'sans démon (job court, reprenable)'],
            ];
        }, true);
    }

    /**
     * `GET /api/v1/version` : version installée. GEDv2 tourne en hébergement mutualisé et ne contacte
     * aucun service distant : la vérification de mise à jour est rendue explicitement désactivée (cause
     * nommée), jamais simulée par un appel réseau.
     */
    public function versionDistante(Requete $requete, Reponse $reponse): Reponse
    {
        return $this->lireRessource($requete, $reponse, 'GET /api/v1/version', function (): array {
            return [
                'version' => $this->versionGed(),
                'update_available' => false,
                'remote_checked' => false,
                'cause' => 'aucun service distant contacté : GEDv2 rend la version installée, la veille est à la charge de l’exploitant',
            ];
        });
    }

    private function versionGed(): string
    {
        return $this->config->get('GED_VERSION', '2.20.5-ged');
    }

    // -----------------------------------------------------------------------
    // Porte commune des routes (jeton lié à un compte) et enveloppes JSON.
    // -----------------------------------------------------------------------

    /**
     * @param callable(array{compte:int, identifiant:string, application:string, administrateur:bool}):mixed $lecture
     */
    private function lireRessource(Requete $requete, Reponse $reponse, string $etape, callable $lecture, bool $administration = false): Reponse
    {
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, false);
            $this->session = $session;
            if ($administration && !$session['administrateur']) {
                throw new ErreurApi(403, 'droit administrateur requis', $etape);
            }

            return $this->json($reponse, 200, $lecture($session));
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'la ressource n’a pas pu être lue');
        }
    }

    /**
     * @param callable(array<string, mixed>):mixed $ecriture
     */
    private function ecrireRessource(Requete $requete, Reponse $reponse, string $etape, callable $ecriture, int $succes = 200, bool $administration = false): Reponse
    {
        try {
            $session = $this->apiJeton->authentifier($requete, $etape, true);
            $this->session = $session;
            if ($administration && !$session['administrateur']) {
                throw new ErreurApi(403, 'droit administrateur requis', $etape);
            }
            $corps = is_array($requete->getParsedBody()) ? $requete->getParsedBody() : [];
            $resultat = $ecriture($corps);
            if ($succes === 204) {
                return $reponse->withStatus(204);
            }

            return $this->json($reponse, $succes, $resultat);
        } catch (ErreurApi $e) {
            return $this->erreur($reponse, $e);
        } catch (Throwable $e) {
            return $this->echec($reponse, $etape, $e, 'l’écriture n’a pas abouti');
        }
    }

    /** @throws ErreurApi */
    private function exigerRoleActif(string $role, string $etape): void
    {
        $requete = $this->pdo->prepare('SELECT 1 FROM securite_roles WHERE code = ? AND actif = 1');
        $requete->execute([$role]);
        if ($requete->fetchColumn() === false) {
            throw new ErreurApi(400, "rôle inconnu ou inactif : « $role »", $etape);
        }
    }

    /** @param array<string, mixed> $corps */
    private function roleDepuis(array $corps): string
    {
        $role = $this->texteOptionnel($corps, ['role'], '');
        if ($role !== '') {
            return $role;
        }
        if ((array_key_exists('is_superuser', $corps) && (bool) $corps['is_superuser'])
            || (array_key_exists('is_staff', $corps) && (bool) $corps['is_staff'])) {
            return 'administrateur';
        }

        return 'utilisateur';
    }

    /**
     * @param array<string, mixed> $corps
     * @throws ErreurApi
     */
    private function refuserFiltresPaperless(array $corps, string $etape): void
    {
        if (array_key_exists('filter_rules', $corps)) {
            throw new ErreurApi(400, 'filter_rules : les règles de filtre Paperless n’ont pas de correspondance GEDv2 (utiliser les clés filtres GEDv2)', $etape);
        }
    }

    /**
     * @param array<string, mixed> $corps
     * @param list<string> $cles
     * @throws ErreurApi
     */
    private function texteRequis(array $corps, array $cles, string $nom, string $etape): string
    {
        foreach ($cles as $cle) {
            if (array_key_exists($cle, $corps) && is_scalar($corps[$cle])) {
                $valeur = trim((string) $corps[$cle]);
                if ($valeur !== '') {
                    return $valeur;
                }
            }
        }

        throw new ErreurApi(400, "champ requis : « $nom »", $etape);
    }

    /**
     * @param array<string, mixed> $corps
     * @param list<string> $cles
     */
    private function texteOptionnel(array $corps, array $cles, string $defaut): string
    {
        foreach ($cles as $cle) {
            if (array_key_exists($cle, $corps) && is_scalar($corps[$cle])) {
                return trim((string) $corps[$cle]);
            }
        }

        return $defaut;
    }

    /**
     * @param array<string, mixed> $corps
     * @param list<string> $cles
     */
    private function entier(array $corps, array $cles, int $defaut): int
    {
        foreach ($cles as $cle) {
            if (array_key_exists($cle, $corps) && is_numeric($corps[$cle])) {
                return (int) $corps[$cle];
            }
        }

        return $defaut;
    }

    /**
     * @param array<string, mixed> $corps
     * @param list<string> $cles
     */
    private function booleen(array $corps, array $cles): bool
    {
        foreach ($cles as $cle) {
            if (array_key_exists($cle, $corps)) {
                return (bool) $corps[$cle];
            }
        }

        return false;
    }

    /** @return list<int> */
    private function listeIdentifiants(mixed $brut): array
    {
        $ids = [];
        foreach (is_array($brut) ? $brut : explode(',', (string) $brut) as $element) {
            $element = trim((string) $element);
            if ($element !== '' && is_numeric($element) && (int) $element > 0) {
                $ids[] = (int) $element;
            }
        }

        return $ids;
    }

    private function compterLignes(string $table): int
    {
        $autorisees = ['comptes', 'securite_groupes'];
        if (!in_array($table, $autorisees, true)) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function auteur(): string
    {
        return $this->session === null ? 'api' : $this->session['identifiant'];
    }

    private function echec(Reponse $reponse, string $etape, Throwable $e, string $cause): Reponse
    {
        error_log(sprintf('[GEDv2 parite-api-3] %s : %s (%s:%d)', $etape, $e->getMessage(), $e->getFile(), $e->getLine()));

        return $this->erreur($reponse, new ErreurApi(500, $cause, $etape, $e));
    }

    /** @param mixed $donnees */
    private function json(Reponse $reponse, int $code, mixed $donnees): Reponse
    {
        $reponse->getBody()->write((string) json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $reponse->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($code);
    }

    private function erreur(Reponse $reponse, ErreurApi $erreur): Reponse
    {
        $reference = substr(hash('sha256', $erreur->etape . $erreur->cause . microtime()), 0, 10);

        return $this->json($reponse, $erreur->statut, [
            'erreur' => ['etape' => $erreur->etape, 'cause' => $erreur->cause, 'reference' => $reference],
        ]);
    }
}
