<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Permissions\Moteur as MoteurPermissions;
use Ged\Permissions\Regles;
use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete as RequeteRecherche;
use Ged\Recherche\Service as ServiceRecherche;
use Ged\Securite\DroitsDossiers;
use Ged\Socle\DepotFichiers;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Fichiers;
use Ged\Socle\Nomenclature;
use Ged\Socle\Registre;
use Ged\Tests\Permissions\SupportPermissions;
use Ged\Workflows\Circuits;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files de la famille « permissions dynamiques » (lot
 * ged-mfiles-permissions-1) contre les vrais services GEDv2 `Ged\Permissions\Moteur` /
 * `Ged\Permissions\Regles`, la liste `Ged\Socle\EcranBibliotheque` et le moteur `Ged\Recherche`,
 * plus la machine à états `Ged\Workflows\Circuits` pour l'axe « droits selon l'état ».
 *
 * Les vecteurs sont dérivés de la DOCUMENTATION PUBLIQUE M-Files (automatic permissions / dynamic
 * permissions, resource REST `workflowstate`), jamais de son code. Chaque vecteur décrit un scénario
 * et sa sortie mesurée sur un fonds jetable ; la mesure est écrite pour le juge M-Files.
 */
final class MfilesPermissionsVecteursTest extends TestCase
{
    use SupportPermissions;

    private MoteurPermissions $moteur;
    private Regles $regles;
    private DepotFichiers $depot;
    private Nomenclature $nomenclature;

    protected function setUp(): void
    {
        $this->setUpPermissions();
        $this->services();
    }

    protected function tearDown(): void
    {
        $this->tearDownPermissions();
    }

    public function testRejoueLesVecteursDesRegles(): void
    {
        $mesure = $this->rejouer('mfiles-permissions-regles');
        Vecteurs::ecrireMesure('mfiles-permissions-regles', $mesure);

        self::assertSame(11, $mesure['total']);
        self::assertSame(11, $mesure['passes'], 'vecteurs de règles en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDeLaVisibilite(): void
    {
        $mesure = $this->rejouer('mfiles-permissions-visibilite');
        Vecteurs::ecrireMesure('mfiles-permissions-visibilite', $mesure);

        self::assertSame(4, $mesure['total']);
        self::assertSame(4, $mesure['passes'], 'vecteurs de visibilité en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDesEtats(): void
    {
        $mesure = $this->rejouer('mfiles-permissions-etats');
        Vecteurs::ecrireMesure('mfiles-permissions-etats', $mesure);

        self::assertSame(4, $mesure['total']);
        self::assertSame(4, $mesure['passes'], 'vecteurs d’états en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    private function services(): void
    {
        Registre::oublier();
        $this->regles = new Regles($this->config);
        $this->moteur = new MoteurPermissions($this->config, $this->pdo);
        $this->depot = new DepotFichiers($this->config);
        $this->nomenclature = new Nomenclature($this->config);
        $this->nomenclature->assurer();
    }

    private function fondsNeuf(): void
    {
        $this->reinitialiserPermissions();
        $this->services();
    }

    /** @return array{categorie:string, total:int, passes:int, ecarts:list<array<string,mixed>>} */
    private function rejouer(string $fichier): array
    {
        $donnees = Vecteurs::charger($fichier);
        $passes = 0;
        $ecarts = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $this->fondsNeuf();
            $id = (string) $vecteur['id'];
            $attendu = (array) $vecteur['sortie'];
            $obtenu = $this->executer((array) $vecteur['entree']);
            if ($obtenu === $attendu) {
                ++$passes;
            } else {
                $ecarts[] = ['id' => $id, 'attendu' => $attendu, 'obtenu' => $obtenu];
            }
        }

        return ['categorie' => (string) $donnees['categorie'], 'total' => count($donnees['vecteurs']),
            'passes' => $passes, 'ecarts' => $ecarts];
    }

    /**
     * @param array<string,mixed> $entree
     * @return array<string,mixed>
     */
    private function executer(array $entree): array
    {
        return match ((string) $entree['scenario']) {
            'decision' => $this->scenarioDecision($entree),
            'refus-regle' => $this->scenarioRefusRegle($entree),
            'portable' => $this->scenarioPortable($entree),
            'desactivation' => $this->scenarioDesactivation($entree),
            'visibilite-liste' => $this->scenarioVisibiliteListe($entree),
            'visibilite-recherche' => $this->scenarioVisibiliteRecherche($entree),
            'etat-effectif' => $this->scenarioEtatEffectif($entree),
            'transition' => $this->scenarioTransition($entree),
            'catalogue' => $this->scenarioCatalogue($entree),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioDecision(array $entree): array
    {
        foreach ((array) $entree['regles'] as $regle) {
            $this->regles->enregistrer((array) $regle, 'test@gedv2.test');
        }
        $decision = $this->moteur->decision((array) $entree['doc'], (array) $entree['contexte']);

        return ['voir' => (bool) $decision['voir'], 'modifier' => (bool) $decision['modifier'],
            'appliquees' => array_values((array) $decision['appliquees'])];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRefusRegle(array $entree): array
    {
        try {
            $this->regles->enregistrer((array) $entree['regle'], 'test@gedv2.test');
        } catch (\RuntimeException $e) {
            return ['refus' => true, 'nomme' => str_contains($e->getMessage(), 'cible') ? 'cible' : $e->getMessage()];
        }

        return ['refus' => false, 'nomme' => ''];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioPortable(array $entree): array
    {
        $id = $this->regles->enregistrer((array) $entree['regle'], 'test@gedv2.test');
        $present = is_file($this->racine . '/fonds/.ged/permissions.json');
        Registre::oublier();
        $relue = (new Regles($this->config))->parId($id);

        return ['fichier_present' => $present, 'relue' => $relue !== null, 'nom' => (string) ($relue['nom'] ?? '')];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioDesactivation(array $entree): array
    {
        $id = $this->regles->enregistrer((array) $entree['regle'], 'test@gedv2.test');
        $this->regles->desactiver($id);

        return [
            'total' => count($this->regles->toutes()),
            'actives' => count($this->regles->actives()),
            'actif' => (bool) ($this->regles->parId($id)['actif'] ?? true),
            'relisible' => $this->regles->parId($id) !== null,
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVisibiliteListe(array $entree): array
    {
        $noms = $this->preparerLecteurs($entree);
        foreach ((array) $entree['documents'] as $doc) {
            $this->deposerEtAutoriser((string) $doc['titre'], (string) $doc['classe'], $noms['tous']);
        }
        $this->poserRegle((array) $entree['regle'], $noms);

        $compte = $noms[(string) $entree['lecteur']];
        $ecran = new EcranBibliotheque($this->config, $compte, (bool) ($entree['administrateur'] ?? false));
        $donnees = $ecran->donnees([], 'csrf');
        $titres = array_map(static fn (array $d): string => (string) $d['titre'], $donnees['docs']);
        sort($titres);

        return ['titres' => array_values($titres), 'total' => (int) $donnees['totalFiltre']];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVisibiliteRecherche(array $entree): array
    {
        $noms = $this->preparerLecteurs($entree);
        foreach ((array) $entree['documents'] as $doc) {
            $this->deposerEtAutoriser((string) $doc['titre'], (string) $doc['classe'], $noms['tous'], (string) ($doc['texte'] ?? ''));
        }
        (IndexPleinTexte::depuis($this->config, $this->pdo))->synchroniser(null);
        $this->poserRegle((array) $entree['regle'], $noms);

        $compte = $noms[(string) $entree['lecteur']];
        $resultat = (new ServiceRecherche($this->pdo, $compte, (bool) ($entree['administrateur'] ?? false), $this->config))
            ->rechercher(RequeteRecherche::depuisParametres(['q' => (string) $entree['q']]));
        $titres = array_map(static fn ($t): string => $t->titre, $resultat->trouvailles);
        sort($titres);

        return ['titres' => array_values($titres), 'total' => $resultat->total];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioEtatEffectif(array $entree): array
    {
        $circuits = new Circuits($this->pdo);
        $circuitId = $circuits->creer((array) $entree['circuit']);
        $circuits->demarrer($circuitId, (int) $entree['document_id'], (string) $entree['etat'], null);

        return ['etat' => $this->moteur->etat((int) $entree['document_id'])];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioTransition(array $entree): array
    {
        $circuits = new Circuits($this->pdo);
        $circuitId = $circuits->creer((array) $entree['circuit']);
        $circuits->demarrer($circuitId, (int) $entree['document_id'], (string) $entree['depart'], null);

        try {
            $resultat = $this->moteur->transitionner((int) $entree['document_id'], (string) $entree['vers'],
                'test@gedv2.test', (array) $entree['permissions']);
        } catch (\RuntimeException $e) {
            return ['refus' => true, 'nomme' => str_contains($e->getMessage(), 'transition refusée') ? 'transition refusée' : $e->getMessage(),
                'etat_apres' => $this->moteur->etat((int) $entree['document_id'])];
        }

        return ['refus' => false, 'vers_etat' => (string) $resultat['vers_etat'],
            'etat_apres' => $this->moteur->etat((int) $entree['document_id'])];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioCatalogue(array $entree): array
    {
        $circuits = new Circuits($this->pdo);
        $id = $circuits->creer((array) $entree['circuit']);
        $circuit = $circuits->parId($id) ?? [];
        $etats = $this->moteur->etats($id);
        $transitions = $this->moteur->transitions($id);

        return [
            'circuits' => count($this->moteur->circuits()),
            'nom' => (string) ($circuit['nom'] ?? ''),
            'etats' => array_values(array_map(static fn (array $e): string => (string) $e['nom'], $etats)),
            'transitions' => array_values(array_map(static fn (array $t): array => [
                'de' => (string) $t['de'], 'vers' => (string) $t['vers'], 'permission' => (string) ($t['permission'] ?? ''),
            ], $transitions)),
        ];
    }

    /**
     * Crée les groupes et comptes décrits par le vecteur, rend une table nom → identifiant
     * (les comptes y figurent par leur identifiant). Un groupe « tous » reçoit chaque compte.
     *
     * @param array<string,mixed> $entree
     * @return array<string,int>
     */
    private function preparerLecteurs(array $entree): array
    {
        $noms = [];
        foreach ((array) $entree['groupes'] as $groupe) {
            $groupe = (array) $groupe;
            $noms[(string) $groupe['nom']] = $this->creerGroupe((string) $groupe['nom'], (string) $groupe['role']);
        }
        $tous = $this->creerGroupe('tous', 'utilisateur');
        $noms['tous'] = $tous;
        foreach ((array) $entree['comptes'] as $compte) {
            $compte = (array) $compte;
            $id = $this->creerCompte((string) $compte['identifiant'], (string) $compte['role']);
            $noms[(string) $compte['identifiant']] = $id;
            $this->ajouterAuGroupe($id, $tous);
            foreach ((array) ($compte['groupes'] ?? []) as $nomGroupe) {
                $this->ajouterAuGroupe($id, $noms[(string) $nomGroupe]);
            }
        }

        return $noms;
    }

    /** @param array<string,mixed> $regle @param array<string,int> $noms */
    private function poserRegle(array $regle, array $noms): void
    {
        $cibles = [];
        foreach ((array) ($regle['cibles'] ?? []) as $cible) {
            $cible = (array) $cible;
            $valeur = (string) $cible['valeur'];
            $cibles[] = ['type' => (string) $cible['type'],
                'valeur' => (string) $cible['type'] === 'groupe' ? ($noms[$valeur] ?? $valeur) : $valeur];
        }
        $regle['cibles'] = $cibles;
        $this->regles->enregistrer($regle, 'test@gedv2.test');
    }

    /** Dépose un document dans le fonds et accorde la lecture au groupe « tous » sur son dossier ACL. */
    private function deposerEtAutoriser(string $titre, string $classe, int $groupeTous, string $texte = ''): void
    {
        $nom = Fichiers::slug($titre) . '-' . bin2hex(random_bytes(3)) . '.txt';
        $id = $this->depot->deposer($this->pieceADeposer($nom, 'contenu ' . $titre . ' ' . $texte), [
            'titre' => $titre, 'type' => $classe, 'date' => '2026-09-01', 'texte' => $texte,
        ]);
        $chemin = (string) $this->depot->fiche($id)['chemin_relatif'];
        $droits = new DroitsDossiers($this->pdo);
        $dossier = $droits->dossierPourChemin($chemin) ?? $droits->assurerDossierPourChemin($chemin);
        if ($dossier !== null) {
            $droits->poserRegle($dossier, $groupeTous, 'lire', true);
        }
    }
}
