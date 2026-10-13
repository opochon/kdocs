<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\DepotFileserver\Arborescence;
use Ged\DepotFileserver\DepotSepare;
use Ged\DepotFileserver\HorlogeFigee;
use Ged\DepotFileserver\Manifeste;
use Ged\DepotFileserver\Reversibilite;
use Ged\DepotFileserver\Scanner;
use Ged\DepotFileserver\Veille;
use Ged\DepotFileserver\Versions;
use Ged\Tests\DepotFileserver\SupportDepot;
use PHPUnit\Framework\TestCase;

/**
 * Rejeu des VECTEURS DE COMPORTEMENT M-Files (famille archivage / versions) contre le vrai service
 * GEDv2 `Ged\DepotFileserver` : chaque vecteur décrit un scénario « le dossier fait foi » et la
 * sortie attendue, mesurée sur un fonds jetable. La mesure est écrite pour le juge M-Files.
 *
 * Ces vecteurs sont dérivés de la DOCUMENTATION PUBLIQUE M-Files (versions, historique, corbeille,
 * fichiers), pas de son code : M-Files n'a pas de tests publics. Un écart fait échouer le rejeu.
 */
final class MfilesVecteursTest extends TestCase
{
    private string $fonds;
    private string $garantie;
    private string $export;

    protected function setUp(): void
    {
        $this->fondsNeufs();
    }

    protected function tearDown(): void
    {
        SupportDepot::supprimer($this->fonds);
        SupportDepot::supprimer($this->garantie);
        SupportDepot::supprimer($this->export);
    }

    /** Un fonds, une garantie et un export NEUFS pour chaque vecteur : aucun mélange entre scénarios. */
    private function fondsNeufs(): void
    {
        if (isset($this->fonds)) {
            SupportDepot::supprimer($this->fonds);
            SupportDepot::supprimer($this->garantie);
            SupportDepot::supprimer($this->export);
        }
        $this->fonds = SupportDepot::dossier('mfiles-vecteurs-fonds');
        $this->garantie = SupportDepot::dossier('mfiles-vecteurs-garantie');
        $this->export = SupportDepot::dossier('mfiles-vecteurs-export');
    }

    public function testRejoueLesVecteursDesVersions(): void
    {
        $mesure = $this->rejouer('mfiles-versions');
        Vecteurs::ecrireMesure('mfiles-versions', $mesure);

        self::assertSame(4, $mesure['total']);
        self::assertSame(4, $mesure['passes'], 'vecteurs de versions en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
    }

    public function testRejoueLesVecteursDeLaGarantieEtDeLaReversibilite(): void
    {
        $mesure = $this->rejouer('mfiles-garantie');
        Vecteurs::ecrireMesure('mfiles-garantie', $mesure);

        self::assertSame(3, $mesure['total']);
        self::assertSame(3, $mesure['passes'], 'vecteurs de garantie en écart : ' . json_encode($mesure['ecarts'], JSON_UNESCAPED_UNICODE));
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
            $this->fondsNeufs();
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
            'modification' => $this->scenarioModification($entree),
            'deplacement' => $this->scenarioDeplacement($entree),
            'suppression' => $this->scenarioSuppression($entree),
            'restauration' => $this->scenarioRestauration($entree),
            'scan-initial' => $this->scenarioScanInitial($entree),
            'depot-separe' => $this->scenarioDepotSepare($entree),
            'reversibilite' => $this->scenarioReversibilite($entree),
            default => throw new \RuntimeException('scénario de vecteur inconnu : ' . (string) $entree['scenario']),
        };
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioModification(array $entree): array
    {
        $fichier = $this->fonds . '/' . (string) $entree['fichier'];
        SupportDepot::ecrire($fichier, (string) $entree['avant']);
        $this->veille('2026-10-13T10:00:00+02:00')->passer();
        SupportDepot::ecrire($fichier, (string) $entree['apres']);
        $this->veille('2026-10-13T11:00:00+02:00')->passer();

        $versions = new Versions();
        $archive = $versions->cheminVersion($fichier, substr(hash('sha256', (string) $entree['avant']), 0, 8));

        return [
            'type' => (string) $this->veille()->journal()[0]['type'],
            'versions_apres' => count($versions->lister($fichier)),
            'version_ancienne' => $archive !== null ? (string) file_get_contents($archive) : '',
            'version_courante' => (string) file_get_contents($fichier),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioDeplacement(array $entree): array
    {
        $avant = $this->fonds . '/' . (string) $entree['avant'];
        $apres = $this->fonds . '/' . (string) $entree['apres'];
        SupportDepot::ecrire($avant, 'contenu-deplace');
        $this->veille('2026-10-13T10:00:00+02:00')->passer();
        SupportDepot::ecrire($apres, 'contenu-deplace');
        @unlink($avant);
        $this->veille('2026-10-13T11:00:00+02:00')->passer();

        $manifeste = (new Manifeste($this->fonds))->lire()['fichiers'];
        $journal = $this->veille()->journal();

        return [
            'type' => (string) ($journal[0]['type'] ?? ''),
            'meme_empreinte' => ($manifeste[(string) $entree['apres']]['empreinte'] ?? '') === hash('sha256', 'contenu-deplace'),
            'ancien_chemin' => (string) ($journal[0]['ancien_chemin'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioSuppression(array $entree): array
    {
        $fichier = $this->fonds . '/' . (string) $entree['fichier'];
        SupportDepot::ecrire($fichier, (string) $entree['contenu']);
        $this->veille('2026-10-13T10:00:00+02:00')->passer();
        @unlink($fichier);
        $this->veille('2026-10-13T11:00:00+02:00')->passer();

        $manifeste = (new Manifeste($this->fonds))->lire()['fichiers'];

        return [
            'type' => (string) $this->veille()->journal()[0]['type'],
            'etat_manifeste' => (string) ($manifeste[(string) $entree['fichier']]['etat'] ?? ''),
            'conserve_garantie' => (new DepotSepare($this->garantie))->aCopie(hash('sha256', (string) $entree['contenu'])),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioRestauration(array $entree): array
    {
        $fichier = $this->fonds . '/' . (string) $entree['fichier'];
        SupportDepot::ecrire($fichier, (string) $entree['v1']);
        $this->veille('2026-10-13T10:00:00+02:00')->passer();
        SupportDepot::ecrire($fichier, (string) $entree['v2']);
        $this->veille('2026-10-13T11:00:00+02:00')->passer();

        $versions = new Versions();
        $versions->restaurer($fichier, substr(hash('sha256', (string) $entree['v1']), 0, 8));

        return [
            'type' => 'restaure',
            'courant' => (string) file_get_contents($fichier),
            'versions_conservees' => count($versions->lister($fichier)),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioScanInitial(array $entree): array
    {
        $fichiers = (array) $entree['fichiers'];
        foreach ($fichiers as $relatif) {
            SupportDepot::ecrire($this->fonds . '/' . $relatif, 'contenu de ' . $relatif);
        }
        $stats = $this->veille('2026-10-13T10:00:00+02:00')->passer();

        return [
            'empreintes' => count((new Scanner($this->fonds))->scan()),
            'garantis' => $stats['garantis'],
            'versions' => $stats['versions'],
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioDepotSepare(array $entree): array
    {
        $contenu = (string) $entree['contenu'];
        $depot = new DepotSepare($this->garantie);
        foreach ((array) $entree['chemins'] as $relatif) {
            $fichier = $this->fonds . '/' . $relatif;
            SupportDepot::ecrire($fichier, $contenu);
            $depot->conserver($fichier, Arborescence::empreinte($fichier), $relatif, [], '2026-10-13T10:00:00+02:00');
        }

        return [
            'contenus_garantis' => $depot->compter(),
            'origines' => count($depot->origines(hash('sha256', $contenu))),
        ];
    }

    /** @param array<string,mixed> $entree @return array<string,mixed> */
    private function scenarioReversibilite(array $entree): array
    {
        foreach ((array) $entree['fichiers'] as $relatif) {
            SupportDepot::ecrire($this->fonds . '/' . $relatif, 'contenu de ' . $relatif);
        }
        SupportDepot::ecrire($this->fonds . '/' . Arborescence::INTERNE . '/empreintes.json', '{}');
        SupportDepot::ecrire($this->fonds . '/.facture.txt/v001_deadbeef.txt', 'ancienne');

        $reversibilite = new Reversibilite($this->fonds);
        $resultat = $reversibilite->exporter($this->export);
        $verification = $reversibilite->verifier($this->export);

        return [
            'exportes' => $resultat['exportes'],
            'identiques' => $verification['identiques'],
            'ecarts' => count($verification['ecarts']),
            'interne_absent' => !is_dir($this->export . '/' . Arborescence::INTERNE) && !is_dir($this->export . '/.facture.txt'),
        ];
    }

    private function veille(string $instant = '2026-10-13T12:00:00+02:00'): Veille
    {
        return new Veille($this->fonds, new DepotSepare($this->garantie), new HorlogeFigee($instant));
    }
}
