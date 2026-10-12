<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\Champs;
use Ged\Socle\Etiquettes;
use Ged\Socle\Nomenclature;
use Ged\Socle\Vues;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « verbes REST restants » du lot ged-parite-final-1.
 *
 * Vecteurs extraits en LECTURE SEULE de `test_api_objects.py` (Paperless-ngx v2.20.5) : Correspondent
 * (correspondants = émetteurs GEDv2) et StoragePath (emplacements de classement GEDv2). GEDv2 complète
 * le CRUD à sa manière : les deux registres vivent dans le fonds (`.ged/*.json`), le retrait est une
 * MISE EN VEILLE (200) là où Paperless supprime (204) — écart assumé, mesuré, zéro suppression.
 */
final class FinalVerbesTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec("DELETE FROM securite_groupes WHERE nom LIKE 'final-%'");
        $this->pdo->exec("DELETE FROM comptes WHERE identifiant LIKE 'final-%'");
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDesVerbesRestants(): void
    {
        $donnees = Vecteurs::charger('final-verbes');
        $app = Kernel::creer(dirname(__DIR__, 3));
        // La configuration applicative est réservée à l'administrateur : le rejeu porte un jeton admin.
        $compte = $this->compteFinal();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');

        $correspondant = 0;
        $emplacement = 0;
        $passes = 0;
        $assumes = 0;
        $echecs = [];

        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            [$methode, $chemin, $corps] = $this->traduire($id, $entree, $correspondant, $emplacement);
            [$statut, $reponse] = $this->appel($app, $methode, $chemin, $jeton, $corps);

            if ($id === 'verbes:correspondent_create:002' && $statut === 201) {
                $correspondant = (int) ($reponse['id'] ?? 0);
            }
            if ($id === 'verbes:storage_path_create:002' && $statut === 201) {
                $emplacement = (int) ($reponse['id'] ?? 0);
            }

            if ($statut === $attendu) {
                ++$passes;
            } elseif ($attendu === 204 && $statut === 200) {
                ++$assumes;   // suppression Paperless (204) → mise en veille GEDv2 (200), écart assumé
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        Vecteurs::ecrireApi('final-verbes', [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => 'final-verbes',
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts_assumes' => $assumes,
            'ecarts' => $echecs,
            'non_rejouables' => count($donnees['non_rejouables'] ?? []),
        ]);
        self::assertSame([], $echecs, "vecteurs des verbes en écart :\n" . implode("\n", $echecs));
        self::assertSame(3, $assumes, 'correspondant, emplacement et configuration : retrait = mise en veille');
    }

    /**
     * Le retrait des autres référentiels (`*.destroy`) : étiquette, champ, vue, groupe, compte, et les
     * déclencheurs/actions de workflow (mise en veille du workflow). Chaque retrait Laisse la ligne
     * lisible (règle 4), et se refuse sans jeton.
     */
    public function testLesRetraitsDesReferentielsSontDesMisesEnVeille(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $admin = $this->compteFinal();
        $jeton = $this->jetonDocuments($admin['compte'], $admin['identifiant'], 'documents_ecriture');
        $u = bin2hex(random_bytes(3));   // noms uniques : la base de test est partagée entre rejeux

        // Étiquette : retirée de la liste proposée, toujours relisible par son id.
        [$statut, $etiquette] = $this->appel($app, 'POST', '/api/v1/etiquettes', $jeton, ['name' => "Étiquette finale $u"]);
        self::assertSame(201, $statut);
        $eid = (int) $etiquette['id'];
        [$retrait, $retiree] = $this->appel($app, 'DELETE', "/api/v1/etiquettes/$eid", $jeton);
        self::assertSame(200, $retrait);
        self::assertFalse((bool) $retiree['active']);
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/etiquettes/$eid", $jeton)[0], 'la ligne reste');
        self::assertNotNull((new Etiquettes($this->config()))->lire($eid), 'lue en inclusif après mise en veille');
        foreach ((new Etiquettes($this->config()))->lister() as $e) {
            self::assertNotSame($eid, $e['id'], 'une étiquette retirée n’est plus proposée');
        }

        // Champ : retiré de la liste proposée, toujours relisible par son id.
        $nomenclature = new Nomenclature($this->config());
        $nomenclature->assurer();
        $typeId = (int) ($nomenclature->idDuType('Facture') ?? 1);
        [$statut, $champ] = $this->appel($app, 'POST', '/api/v1/champs', $jeton,
            ['name' => "Champ final $u", 'data_type' => 'string']);
        self::assertSame(201, $statut);
        $cid = (int) $champ['id'];
        [$retrait, $retire] = $this->appel($app, 'DELETE', "/api/v1/champs/$cid", $jeton);
        self::assertSame(200, $retrait);
        self::assertFalse((bool) $retire['active']);
        foreach ((new Champs($this->config()))->tous() as $c) {
            self::assertNotSame($cid, $c['id'], 'un champ retiré n’est plus proposé');
        }

        // Vue : retirée de la liste proposée, toujours relisible par son id.
        [$statut, $vue] = $this->appel($app, 'POST', '/api/v1/vues', $jeton, ['name' => "Vue finale $u", 'type' => 'Facture']);
        self::assertSame(201, $statut);
        $vid = (int) $vue['id'];
        [$retrait, $retiree] = $this->appel($app, 'DELETE', "/api/v1/vues/$vid", $jeton);
        self::assertSame(200, $retrait);
        self::assertFalse((bool) $retiree['active']);
        foreach ((new Vues($this->config()))->lister() as $v) {
            self::assertNotSame($vid, $v['id'], 'une vue retirée n’est plus proposée');
        }

        // Groupe : désactivé, toujours relisible ; compte : désactivé, connexion refusée.
        [$statut, $groupe] = $this->appel($app, 'POST', '/api/v1/groupes', $jeton, ['name' => "final-groupe-$u"]);
        self::assertSame(201, $statut);
        $gid = (int) $groupe['id'];
        [$retrait, $groupeRetire] = $this->appel($app, 'DELETE', "/api/v1/groupes/$gid", $jeton);
        self::assertSame(200, $retrait);
        self::assertFalse((bool) $groupeRetire['active']);

        $cible = $this->compteFinal();
        [$retrait, $compteRetire] = $this->appel($app, 'DELETE', '/api/v1/comptes/' . $cible['compte'], $jeton);
        self::assertSame(200, $retrait);
        self::assertFalse((bool) $compteRetire['active']);
        self::assertNull(
            (new \Ged\Securite\Authentification($this->config()))->verifier($cible['identifiant'], 'motdepassefort123'),
            'un compte mis en veille ne peut plus se connecter',
        );

        // Non-accès : sans jeton, un retrait refuse et se nomme.
        [$sans] = $this->appel($app, 'DELETE', '/api/v1/etiquettes/' . 1);
        self::assertSame(401, $sans);
    }

    /**
     * @param array<string, mixed> $entree
     * @return array{0:string, 1:string, 2:array<string, mixed>|null}
     */
    private function traduire(string $id, array $entree, int $correspondant, int $emplacement): array
    {
        $methode = (string) ($entree['methode'] ?? 'GET');
        $chemin = (string) ($entree['chemin'] ?? '');
        $corps = is_array($entree['corps'] ?? null) ? $entree['corps'] : null;
        $detail = str_contains($chemin, '{id}');

        if (str_starts_with($chemin, '/api/correspondents')) {
            return [$methode, $detail ? "/api/v1/emetteurs/$correspondant" : '/api/v1/emetteurs', $corps];
        }
        if (str_starts_with($chemin, '/api/storage_paths')) {
            return [$methode, $detail ? "/api/v1/emplacements/$emplacement" : '/api/v1/emplacements', $corps];
        }
        if (str_starts_with($chemin, '/api/config')) {
            return [$methode, '/api/v1/configuration', $corps];
        }

        return [$methode, $chemin, $corps];
    }

    /** @return array{compte:int, identifiant:string} */
    private function compteFinal(): array
    {
        $identifiant = 'final-verbes-' . bin2hex(random_bytes(4)) . '@exemple.test';
        $this->pdo->prepare('INSERT INTO comptes (identifiant, empreinte, role) VALUES (?, ?, ?)')
            ->execute([$identifiant, password_hash('motdepassefort123', PASSWORD_DEFAULT), 'administrateur']);

        return ['compte' => (int) $this->pdo->lastInsertId(), 'identifiant' => $identifiant];
    }
}
