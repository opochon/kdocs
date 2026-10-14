<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Recherche\IndexPleinTexte;
use Ged\Recherche\Requete as RequeteRecherche;
use Ged\Recherche\Schema as SchemaRecherche;
use Ged\Recherche\Service as ServiceRecherche;
use Ged\Socle\Champs;
use Ged\Socle\DepotFichiers;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Fichiers;
use Ged\Socle\Fonds;
use Ged\Socle\Nomenclature;
use Ged\Socle\Registre;
use Ged\Socle\Vues;
use Ged\Tests\Socle\SupportVues;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files de la famille 3 (vues dynamiques : vues filtrees par
 * classe et metadonnees, vues enregistrees, vues recherchees) contre les vrais services GEDv2
 * `Ged\Socle\Vues`, `Ged\Socle\Fonds` et `Ged\Recherche`, l'ecran `Ged\Socle\EcranBibliotheque` et le
 * registre portable du fonds (`.ged/vues.json`). Chaque vecteur decrit un scenario et sa sortie
 * mesuree sur un fonds jetable ; la mesure est ecrite pour le juge M-Files.
 *
 * Ces vecteurs sont derives de la DOCUMENTATION PUBLIQUE M-Files (views / view contents / search view
 * contents), pas de son code. Un ecart fait echouer le rejeu.
 */
final class MfilesVuesVecteursTest extends TestCase
{
    use SupportVues;

    private Vues $vues;
    private DepotFichiers $depot;
    private Champs $champs;
    private Nomenclature $nomenclature;

    protected function setUp(): void
    {
        $this->setUpVues();
        $this->services();
    }

    protected function tearDown(): void
    {
        $this->tearDownVues();
    }

    public function testRejoueLesVecteursDesFiltresEtDuContenu(): void
    {
        $mesure = $this->rejouer('mfiles-vues-filtres');
        Vecteurs::ecrireMesure('mfiles-vues-filtres', $mesure);

        self::assertSame(6, $mesure['total']);
        self::assertSame(6, $mesure['passes'], 'vecteurs de vues en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDesVuesRecherchees(): void
    {
        $mesure = $this->rejouer('mfiles-vues-recherchees');
        Vecteurs::ecrireMesure('mfiles-vues-recherchees', $mesure);

        self::assertSame(3, $mesure['total']);
        self::assertSame(3, $mesure['passes'], 'vecteurs de vues recherchées en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    /** Un fonds NU (et sa base) pour chaque vecteur : deux scenarios ne partagent jamais un etat. */
    private function services(): void
    {
        Registre::oublier();
        $this->vues = new Vues($this->config);
        $this->depot = new DepotFichiers($this->config);
        $this->champs = new Champs($this->config);
        $this->nomenclature = new Nomenclature($this->config);
        $this->nomenclature->assurer();
    }

    private function fondsNeuf(): void
    {
        $this->tearDownVues();
        $this->setUpVues();
        $this->services();
    }

    /**
     * @return array{categorie:string, total:int, passes:int, ecarts:list<array<string,mixed>>}
     */
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
            'vue-classe' => $this->scenarioVueClasse($entree),
            'vue-metadonnee' => $this->scenarioVueMetadonnee($entree),
            'vue-classe-et-metadonnee' => $this->scenarioVueClasseEtMetadonnee($entree),
            'vue-refus-champ-inconnu' => $this->scenarioVueRefusChampInconnu($entree),
            'vue-enregistree-relecture' => $this->scenarioVueEnregistreeRelecture($entree),
            'vue-compte-sans-deplacement' => $this->scenarioVueCompteSansDeplacement($entree),
            'vue-recherche-criteres' => $this->scenarioVueRechercheCriteres($entree),
            'vue-recherche-contenu' => $this->scenarioVueRechercheContenu($entree),
            'vue-recherche-portable' => $this->scenarioVueRecherchePortable($entree),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueClasse(array $entree): array
    {
        foreach ((array) $entree['documents'] as $doc) {
            $doc = (array) $doc;
            $this->deposerDansFonds((string) $doc['titre'], (string) $doc['classe']);
        }
        $id = $this->vues->enregistrer('Vue ' . (string) $entree['classe'], ['type' => (string) $entree['classe']], 'alice@gedv2.test');
        $docs = (new Fonds($this->config))->tous($this->pdo);

        return ['classe' => (string) $entree['classe'], 'contenus' => $this->titresTries($this->vues->contenu($id, $docs)),
            'compte' => $this->vues->compter($id, $docs)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueMetadonnee(array $entree): array
    {
        $champ = (array) $entree['champ'];
        $cle = $this->ajouterChamp((string) $entree['classe'], (string) $champ['nom'], (string) $champ['nature']);
        foreach ((array) $entree['documents'] as $doc) {
            $doc = (array) $doc;
            $chemin = $this->deposerDansFonds((string) $doc['titre'], (string) $entree['classe']);
            $this->champs->poserValeur($chemin, $cle, (string) $doc['valeur']);
        }
        $id = $this->vues->enregistrer('Vue ' . (string) $champ['nom'], [
            'type' => (string) $entree['classe'], 'champ_cle' => $cle, 'champ_op' => 'egal',
            'champ_valeur' => (string) $entree['filtre'],
        ], 'alice@gedv2.test');
        $docs = (new Fonds($this->config))->tous($this->pdo);

        return ['contenus' => $this->titresTries($this->vues->contenu($id, $docs)), 'compte' => $this->vues->compter($id, $docs)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueClasseEtMetadonnee(array $entree): array
    {
        $champ = (array) $entree['champ'];
        $cle = $this->ajouterChamp((string) $entree['classe'], (string) $champ['nom'], (string) $champ['nature']);
        foreach ((array) $entree['documents'] as $doc) {
            $doc = (array) $doc;
            $chemin = $this->deposerDansFonds((string) $doc['titre'], (string) $doc['classe'], (string) $doc['date']);
            // Le champ n'existe que sur la classe visee : une piece d'une autre classe n'en porte pas.
            if ((string) $doc['classe'] === (string) $entree['classe']) {
                $this->champs->poserValeur($chemin, $cle, (string) $doc['valeur']);
            }
        }
        $id = $this->vues->enregistrer('Vue période et exercice', [
            'type' => (string) $entree['classe'], 'de' => (string) $entree['de'], 'a' => (string) $entree['a'],
            'champ_cle' => $cle, 'champ_op' => 'egal', 'champ_valeur' => (string) $entree['filtre'],
        ], 'alice@gedv2.test');
        $docs = (new Fonds($this->config))->tous($this->pdo);

        return ['contenus' => $this->titresTries($this->vues->contenu($id, $docs)), 'compte' => $this->vues->compter($id, $docs)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueRefusChampInconnu(array $entree): array
    {
        try {
            $this->vues->enregistrer('Vue champ inconnu', [
                'champ_cle' => (string) $entree['champ'], 'champ_op' => 'egal', 'champ_valeur' => '1',
            ], 'alice@gedv2.test');
        } catch (\RuntimeException $e) {
            return ['refus' => true, 'nomme' => str_contains($e->getMessage(), 'aucun champ') ? 'aucun champ' : ''];
        }

        return ['refus' => false, 'nomme' => ''];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueEnregistreeRelecture(array $entree): array
    {
        $id = $this->vues->enregistrer((string) $entree['nom'], [
            'type' => (string) $entree['type'], 'sort_field' => (string) $entree['tri'], 'sort_reverse' => (bool) $entree['tri_inverse'],
        ], 'alice@gedv2.test');
        $vue = $this->vues->lire($id);
        $slug = Fichiers::slug((string) $entree['nom']);

        return [
            'nom' => (string) $vue['nom'],
            'slug_trouve' => $this->vues->parSlug($slug) !== null,
            'type' => (string) $vue['filtres']['type'],
            'tri' => (string) $vue['reglages']['tri'],
            'tri_inverse' => (bool) $vue['reglages']['tri_inverse'],
            'fichier_present' => is_file($this->racine . '/fonds/.ged/vues.json'),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueCompteSansDeplacement(array $entree): array
    {
        $this->deposerDansFonds('Facture A', (string) $entree['classe']);
        $this->deposerDansFonds('Contrat B', 'Contrat');
        $this->deposerDansFonds('Facture C', (string) $entree['classe']);
        $avant = count((new Fonds($this->config))->tous($this->pdo));

        $id = $this->vues->enregistrer('Vue factures', ['type' => (string) $entree['classe']], 'alice@gedv2.test');
        $docs = (new Fonds($this->config))->tous($this->pdo);
        $compte = $this->vues->compter($id, $docs);

        return [
            'fichiers_avant' => $avant,
            'fichiers_apres' => count((new Fonds($this->config))->tous($this->pdo)),
            'compte' => $compte,
            'deplacements' => (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements')->fetchColumn(),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueRechercheCriteres(array $entree): array
    {
        $id = $this->vues->enregistrerRecherche((string) $entree['nom'], [
            'q' => (string) $entree['q'], 'type' => (string) $entree['classe'], 'emetteur' => (string) $entree['emetteur'],
            'date_debut' => (string) $entree['de'], 'date_fin' => (string) $entree['a'],
            'montant_min' => (string) $entree['montant_min'], 'montant_max' => (string) $entree['montant_max'],
        ], 'alice@gedv2.test');
        $vue = $this->vues->lire($id);
        $crit = $this->vues->criteresRecherche($vue);

        return [
            'q' => $crit['q'], 'classe' => $crit['type'], 'emetteur' => $crit['emetteur'],
            'debut' => $crit['date_debut'], 'fin' => $crit['date_fin'], 'min' => $crit['montant_min'], 'max' => $crit['montant_max'],
            'portable' => is_file($this->racine . '/fonds/.ged/vues.json'),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueRechercheContenu(array $entree): array
    {
        foreach ((array) $entree['documents'] as $doc) {
            $doc = (array) $doc;
            $nom = Fichiers::slug((string) $doc['titre']) . '.txt';
            $this->depot->deposer($this->pieceADeposer($nom, (string) $doc['texte']), [
                'titre' => (string) $doc['titre'], 'type' => (string) $doc['classe'], 'emetteur' => (string) $entree['emetteur'],
                'date' => '2026-10-02', 'texte' => (string) $doc['texte'], 'statut' => 'valide',
            ]);
        }
        SchemaRecherche::installer($this->pdo);
        // La table est partagee avec d'autres suites : on la vide pour que les identifiants reutilises
        // du fonds neuf ne rencontrent aucun reste d'indexation.
        $this->pdo->exec('DELETE FROM recherche_textes');
        (IndexPleinTexte::depuis($this->config, $this->pdo))->synchroniser(null);

        $id = $this->vues->enregistrerRecherche('Vue recherche', [
            'q' => (string) $entree['q'], 'type' => (string) $entree['classe'], 'emetteur' => (string) $entree['emetteur'],
        ], 'alice@gedv2.test');
        $vue = $this->vues->lire($id);
        $recherche = (new ServiceRecherche($this->pdo))->rechercher(RequeteRecherche::depuisParametres($this->vues->criteresRecherche($vue)));
        $bibliotheque = (new EcranBibliotheque($this->config))->donnees(['vue' => $id], 'csrf');

        $compteVolet = 0;
        foreach ($bibliotheque['vuesVolet'] as $volet) {
            if ((int) $volet['id'] === $id) {
                $compteVolet = (int) $volet['compte'];
            }
        }

        return [
            'contenus' => $this->titresTries($bibliotheque['docs']),
            'compte' => (int) $bibliotheque['totalFiltre'],
            'compte_volet' => $compteVolet,
            'meme_ordre' => array_column($bibliotheque['docs'], 'id') === array_column($recherche->trouvailles, 'id'),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioVueRecherchePortable(array $entree): array
    {
        $id = $this->vues->enregistrerRecherche((string) $entree['nom'], [
            'q' => (string) $entree['q'], 'type' => (string) $entree['classe'],
        ], 'alice@gedv2.test');
        Registre::oublier();
        $relue = (new Vues($this->config))->lire($id);

        return [
            'fichier_present' => is_file($this->racine . '/fonds/.ged/vues.json'),
            'relue_apres_oublier' => $relue !== null,
            'q_relu' => (string) ($relue['recherche']['q'] ?? ''),
        ];
    }

    /** Ajoute (ou retrouve) un champ type sur une classe ; rend sa cle stable. */
    private function ajouterChamp(string $classe, string $nom, string $nature): string
    {
        $cle = Fichiers::slug($nom);
        if ($this->champs->parCle($cle) === []) {
            $this->champs->ajouter($this->idDuType($classe), $nom, $nature);
        }

        return $cle;
    }

    /** Depose un document de cette classe dans le fonds ; rend son chemin relatif. */
    private function deposerDansFonds(string $titre, string $classe, string $date = '2026-09-01'): string
    {
        $nom = Fichiers::slug($titre) . '-' . bin2hex(random_bytes(3)) . '.pdf';
        $id = $this->depot->deposer($this->pieceADeposer($nom, 'contenu ' . $titre), [
            'titre' => $titre, 'type' => $classe, 'date' => $date,
        ]);

        return (string) $this->depot->fiche($id)['chemin_relatif'];
    }

    private function idDuType(string $nom): int
    {
        return $this->nomenclature->idDuType($nom) ?? throw new \RuntimeException("type absent : $nom");
    }

    /**
     * @param list<array<string,mixed>> $docs
     * @return list<string>
     */
    private function titresTries(array $docs): array
    {
        $titres = array_map(static fn (array $d): string => (string) $d['titre'], $docs);
        sort($titres);

        return array_values($titres);
    }
}
