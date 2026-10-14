<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\EspacePartage\EspacePerso;
use Ged\EspacePartage\Partages;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Registre;
use Ged\Tests\EspacePartage\SupportEspace;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files de l'espace personnel & du partage (lot ged-mfiles-espace-1)
 * contre les vrais services GEDv2 `Ged\EspacePartage\EspacePerso` et `Ged\EspacePartage\Partages`, le
 * registre portable du fonds (`.ged/`) et l'index MariaDB. Chaque vecteur décrit un scénario et sa sortie
 * mesurée sur un fonds jetable ; la mesure est écrite pour le juge M-Files.
 *
 * Ces vecteurs sont dérivés de la DOCUMENTATION PUBLIQUE M-Files (favorites / recentlyaccessedbyme /
 * sharedlinks), pas de son code. Un écart fait échouer le rejeu.
 */
final class MfilesEspaceVecteursTest extends TestCase
{
    use SupportEspace;

    private EspacePerso $espace;
    private Partages $partages;
    private DepotFichiers $depot;

    protected function setUp(): void
    {
        $this->setUpEspace();
        $this->services();
    }

    protected function tearDown(): void
    {
        $this->tearDownEspace();
    }

    public function testRejoueLesVecteursDesFavorisEtDesRecents(): void
    {
        $mesure = $this->rejouer('mfiles-espace-favoris');
        Vecteurs::ecrireMesure('mfiles-espace-favoris', $mesure);

        self::assertSame(6, $mesure['total']);
        self::assertSame(6, $mesure['passes'], 'vecteurs favoris/récents en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDesLiensDePartage(): void
    {
        $mesure = $this->rejouer('mfiles-espace-partages');
        Vecteurs::ecrireMesure('mfiles-espace-partages', $mesure);

        self::assertSame(4, $mesure['total']);
        self::assertSame(4, $mesure['passes'], 'vecteurs de partage en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    /** Un fonds NU (et sa base) pour chaque vecteur : deux scénarios ne partagent jamais un état. */
    private function services(): void
    {
        Registre::oublier();
        $this->espace = new EspacePerso($this->config);
        $this->partages = new Partages($this->config);
        $this->depot = new DepotFichiers($this->config);
    }

    private function fondsNeuf(): void
    {
        $this->tearDownEspace();
        $this->setUpEspace();
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
            'favoris-lister' => $this->scenarioFavorisLister($entree),
            'favori-objet' => $this->scenarioFavoriObjet($entree),
            'favori-ajouter' => $this->scenarioFavoriAjouter($entree),
            'favori-retirer' => $this->scenarioFavoriRetirer($entree),
            'recents-lister' => $this->scenarioRecentsLister($entree),
            'recent-consulter' => $this->scenarioRecentConsulter($entree),
            'partages-lister' => $this->scenarioPartagesLister($entree),
            'partage-creer' => $this->scenarioPartageCreer($entree),
            'partage-telecharger' => $this->scenarioPartageTelecharger($entree),
            'partage-revoquer' => $this->scenarioPartageRevoquer($entree),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioFavorisLister(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        foreach ((array) $entree['favoris'] as $titre) {
            $this->espace->ajouterFavori($utilisateur, $ids[(string) $titre]);
        }

        return [
            'titres' => array_column($this->espace->favoris($utilisateur), 'titre'),
            'portable' => is_file($this->racine . '/fonds/.ged/favoris.json'),
            'deplacements' => (int) $this->pdo->query('SELECT COUNT(*) FROM document_deplacements')->fetchColumn(),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioFavoriObjet(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        $this->espace->ajouterFavori($utilisateur, $ids[(string) $entree['favori']]);
        $reponses = [];
        foreach ((array) $entree['interroge'] as $titre) {
            $reponses[] = $this->espace->estFavori($utilisateur, $ids[(string) $titre]);
        }

        return ['reponses' => $reponses];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioFavoriAjouter(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        $document = $ids[(string) $entree['document']];
        for ($i = 0; $i < (int) $entree['ajouts']; ++$i) {
            $this->espace->ajouterFavori($utilisateur, $document);
        }

        return ['compte' => count($this->espace->favoris($utilisateur)), 'actif' => $this->espace->estFavori($utilisateur, $document)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioFavoriRetirer(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        $document = $ids[(string) $entree['document']];
        $this->espace->ajouterFavori($utilisateur, $document);
        $this->espace->retirerFavori($utilisateur, $document);
        $etat = json_decode((string) file_get_contents($this->racine . '/fonds/.ged/favoris.json'), true);

        return [
            'compte_actifs' => count($this->espace->favoris($utilisateur)),
            'actif' => $this->espace->estFavori($utilisateur, $document),
            'trace_retrait' => is_array($etat) && ($etat[$utilisateur][0]['retire_le'] ?? null) !== null,
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRecentsLister(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        foreach ((array) $entree['consultations'] as $titre) {
            $this->espace->enregistrerConsultation($utilisateur, $ids[(string) $titre]);
        }
        $recents = $this->espace->recents($utilisateur);

        return [
            'titres' => array_column($recents, 'titre'),
            'compte' => count($recents),
            'portable' => is_file($this->racine . '/fonds/.ged/recents.json'),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRecentConsulter(array $entree): array
    {
        $ids = $this->deposerTous((array) $entree['documents']);
        $utilisateur = (string) $entree['utilisateur'];
        foreach ((array) $entree['consultations'] as $titre) {
            $this->espace->enregistrerConsultation($utilisateur, $ids[(string) $titre]);
        }
        $recents = $this->espace->recents($utilisateur);

        return ['premier' => (string) ($recents[0]['titre'] ?? ''), 'compte' => count($recents)];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioPartagesLister(array $entree): array
    {
        $id = $this->deposerTous([(string) $entree['document']])[(string) $entree['document']];
        $auteur = (string) $entree['auteur'];
        $jetons = [];
        for ($i = 0; $i < (int) $entree['creations']; ++$i) {
            $jetons[] = $this->partages->creer($id, $auteur, (string) $entree['expiration'])['jeton'];
        }
        $liens = $this->partages->liens();
        $empreintes = (string) $this->pdo->query("SELECT GROUP_CONCAT(jeton_empreinte) FROM document_collaboration WHERE type = 'partage'")->fetchColumn();
        $enClair = false;
        foreach ($jetons as $jeton) {
            if (str_contains($empreintes, $jeton)) {
                $enClair = true;
            }
        }

        return ['liens' => count($liens), 'titres' => array_column($liens, 'titre'), 'secret_en_clair' => $enClair];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioPartageCreer(array $entree): array
    {
        $id = $this->deposerTous([(string) $entree['document']])[(string) $entree['document']];
        $lien = $this->partages->creer($id, (string) $entree['auteur'], (string) $entree['expiration']);
        $empreintes = (string) $this->pdo->query("SELECT GROUP_CONCAT(jeton_empreinte) FROM document_collaboration WHERE type = 'partage'")->fetchColumn();

        return [
            'format_jeton' => preg_match('/^[A-Za-z0-9_-]{40,}$/', $lien['jeton']) === 1,
            'expire_le' => (string) $this->partages->liens()[0]['expire_le'],
            'condensat_seul' => !str_contains($empreintes, $lien['jeton']),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioPartageTelecharger(array $entree): array
    {
        $id = $this->deposerTous([(string) $entree['document']])[(string) $entree['document']];
        $lien = $this->partages->creer($id, (string) $entree['auteur'], (string) $entree['expiration']);
        $ouvert = $this->partages->ouvrir($lien['jeton']);
        $titre = $ouvert === null ? '' : (string) $this->depot->fiche($ouvert['document'])['titre'];
        $this->pdo->prepare("UPDATE document_collaboration SET expire_le = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?")
            ->execute([$lien['id']]);

        return [
            'titre' => $titre,
            'faux_jeton' => $this->partages->ouvrir(str_repeat('x', 43)),
            'expire_ferme' => $this->partages->ouvrir($lien['jeton']),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioPartageRevoquer(array $entree): array
    {
        $id = $this->deposerTous([(string) $entree['document']])[(string) $entree['document']];
        $lien = $this->partages->creer($id, (string) $entree['auteur'], (string) $entree['expiration']);
        $avant = $this->partages->ouvrir($lien['jeton']) !== null;
        $this->partages->revoquerParCle($lien['jeton'], (string) $entree['auteur']);

        return ['avant' => $avant, 'apres' => $this->partages->ouvrir($lien['jeton']) !== null, 'liste' => count($this->partages->liens())];
    }

    /**
     * Dépose chaque titre et rend la correspondance titre => identifiant.
     *
     * @param list<mixed> $titres
     * @return array<string, int>
     */
    private function deposerTous(array $titres): array
    {
        $ids = [];
        foreach ($titres as $titre) {
            $titre = (string) $titre;
            $ids[$titre] = $this->deposerPiece($titre);
        }

        return $ids;
    }
}
