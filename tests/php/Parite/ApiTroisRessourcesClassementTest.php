<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use Ged\Kernel;
use Ged\Socle\Champs;
use Ged\Socle\Nomenclature;
use Ged\Socle\Vues;
use Ged\Tests\Jonctions\SupportJonctions;
use PHPUnit\Framework\TestCase;

/**
 * Famille « ressources de classement » du lot ged-parite-api-3.
 *
 * Vecteurs extraits en LECTURE SEULE de test_api_custom_fields.py et test_api_documents.py (Paperless-ngx
 * v2.20.5) : CustomField (create/update) et SavedView (create/retrieve/update/partial_update). Côté GEDv2,
 * les verbes passent par `/api/v1/champs` et `/api/v1/vues` (services Socle\Champs et Socle\Vues), sous la
 * même porte jeton que l'API des documents.
 *
 * Les chemins de stockage (test_api_objects.py) sont listés `non_rejouables` : GEDv2 range par dossier.
 */
final class ApiTroisRessourcesClassementTest extends TestCase
{
    use SupportJonctions;

    protected function setUp(): void
    {
        $this->setUpJonctions();
    }

    protected function tearDown(): void
    {
        $this->tearDownJonctions();
    }

    public function testRejoueLesVecteursDeClassement(): void
    {
        $donnees = Vecteurs::charger('api-3-ressources-classement');
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');

        // Le vecteur 003 attend un 400 « nom déjà pris » : on pose le champ homonyme AVANT le rejeu.
        $nomenclature = new Nomenclature($this->config());
        $nomenclature->assurer();
        $typeId = (int) ($nomenclature->idDuType('Facture') ?? 1);
        $champs = new Champs($this->config());
        $homonyme = false;
        foreach ($champs->tous() as $definition) {
            $homonyme = $homonyme || $definition['nom'] === 'Test Custom Field';
        }
        if (!$homonyme) {
            $champs->ajouter($typeId, 'Test Custom Field', 'texte');
        }

        $vueId = 0;
        $passes = 0;
        $echecs = [];
        foreach ($donnees['vecteurs'] as $vecteur) {
            $id = (string) ($vecteur['id'] ?? '?');
            $entree = is_array($vecteur['entree'] ?? null) ? $vecteur['entree'] : [];
            $attendu = (int) ($vecteur['sortie']['statut'] ?? 0);
            [$methode, $chemin, $corps] = $this->traduire($id, $entree, $vueId);
            [$statut] = $this->appel($app, $methode, $chemin, $jeton, $corps);

            if ($methode === 'POST' && $chemin === '/api/v1/vues' && $statut === 201) {
                $vueId = $this->derniereVue();
            }
            if ($statut === $attendu) {
                ++$passes;
            } else {
                $echecs[] = sprintf('%s (%s) : attendu %d, obtenu %d', $id,
                    (string) ($vecteur['reference'] ?? ''), $attendu, $statut);
            }
        }

        $this->mesurer('3-ressources-classement', $donnees, $passes, $echecs, count($donnees['non_rejouables'] ?? []));
        self::assertSame([], $echecs, "vecteurs de classement en écart :\n" . implode("\n", $echecs));
    }

    /**
     * Preuve directe des verbes des registres du fonds : créer, relire, remplacer (PUT), modifier (PATCH),
     * plus un refus d'accès sans jeton (une famille, un test de non-accès).
     */
    public function testLesVerbesDesRegistresDuFonds(): void
    {
        $app = Kernel::creer(dirname(__DIR__, 3));
        $compte = $this->compteEtDossiers();
        $jeton = $this->jetonDocuments($compte['compte'], $compte['identifiant'], 'documents_ecriture');

        // Étiquette
        [$statut, $etiquette] = $this->appel($app, 'POST', '/api/v1/etiquettes', $jeton,
            ['name' => 'Étiquette parcours', 'color' => '#112233']);
        self::assertSame(201, $statut);
        $eid = (int) $etiquette['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/etiquettes/$eid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/etiquettes/$eid", $jeton, ['name' => 'Étiquette renommée'])[0]);
        [$statut, $modifiee] = $this->appel($app, 'PATCH', "/api/v1/etiquettes/$eid", $jeton, ['is_inbox_tag' => true]);
        self::assertSame(200, $statut);
        self::assertTrue($modifiee['is_inbox_tag']);

        // Type de document
        [$statut, $type] = $this->appel($app, 'POST', '/api/v1/types', $jeton, ['name' => 'Type parcours']);
        self::assertSame(201, $statut);
        $tid = (int) $type['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/types/$tid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/types/$tid", $jeton, ['name' => 'Type renommé'])[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', "/api/v1/types/$tid", $jeton, ['active' => false])[0]);

        // Champ personnalisé
        [$statut, $champ] = $this->appel($app, 'POST', '/api/v1/champs', $jeton,
            ['name' => 'Champ parcours', 'data_type' => 'integer']);
        self::assertSame(201, $statut);
        $cid = (int) $champ['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/champs/$cid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/champs/$cid", $jeton, ['name' => 'Champ renommé'])[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', "/api/v1/champs/$cid", $jeton, ['name' => 'Champ affiné'])[0]);

        // Vue enregistrée
        [$statut, $vue] = $this->appel($app, 'POST', '/api/v1/vues', $jeton, ['name' => 'Vue parcours', 'type' => 'Facture']);
        self::assertSame(201, $statut);
        $vid = (int) $vue['id'];
        self::assertSame(200, $this->appel($app, 'GET', "/api/v1/vues/$vid", $jeton)[0]);
        self::assertSame(200, $this->appel($app, 'PATCH', "/api/v1/vues/$vid", $jeton, ['show_in_sidebar' => false])[0]);
        self::assertSame(200, $this->appel($app, 'PUT', "/api/v1/vues/$vid", $jeton, ['name' => 'Vue parcours', 'emetteur' => 'ACME'])[0]);

        // Non-accès : sans jeton, le verbe se refuse et se nomme (jamais un 500 muet).
        [$sans] = $this->appel($app, 'GET', "/api/v1/etiquettes/$eid");
        self::assertSame(401, $sans);
        [$sansEcriture] = $this->appel($app, 'POST', '/api/v1/etiquettes', null, ['name' => 'Refus']);
        self::assertSame(401, $sansEcriture);
    }

    /**
     * Traduit un vecteur Paperless en requête GEDv2. Rend [méthode, chemin, corps].
     *
     * @param array<string, mixed> $entree
     * @return array{0:string, 1:string, 2:array<string, mixed>|null}
     */
    private function traduire(string $id, array $entree, int $vueId): array
    {
        $chemin = (string) ($entree['chemin'] ?? '');
        $corps = is_array($entree['corps'] ?? null) ? $entree['corps'] : [];

        if (str_starts_with($chemin, '/api/custom_fields/')) {
            $nom = is_string($corps['name'] ?? null) ? (string) $corps['name'] : 'Champ vecteur ' . substr(md5($id), 0, 6);
            $nature = is_string($corps['data_type'] ?? null) ? (string) $corps['data_type'] : 'string';
            $payload = ['name' => $nom, 'data_type' => $nature];
            if ($nature === 'select') {
                $payload['select_options'] = [['label' => 'Option A'], ['label' => 'Option B']];
            }

            return ['POST', '/api/v1/champs', $payload];
        }

        if (str_starts_with($chemin, '/api/saved_views/')) {
            return $this->traduireVue($id, (string) ($entree['methode'] ?? 'GET'), $corps, $vueId);
        }

        return [(string) ($entree['methode'] ?? 'GET'), $chemin, $corps];
    }

    /**
     * @param array<string, mixed> $corps
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function traduireVue(string $id, string $methode, array $corps, int $vueId): array
    {
        if ($methode === 'POST') {
            return ['POST', '/api/v1/vues', ['name' => 'Vue ' . substr(md5($id), 0, 8), 'type' => 'Facture']];
        }
        $chemin = "/api/v1/vues/$vueId";
        if ($methode === 'PUT') {
            // Remplacement complet : filtres GEDv2 (les `filter_rules` Paperless n'ont pas d'équivalent).
            return ['PUT', $chemin, ['name' => 'Vue ' . substr(md5($id), 0, 8), 'emetteur' => 'ACME']];
        }
        $payload = [];
        foreach (['show_in_sidebar', 'show_on_dashboard', 'sort_field', 'sort_reverse'] as $cle) {
            if (array_key_exists($cle, $corps)) {
                $payload[$cle] = $corps[$cle];
            }
        }
        if (array_key_exists('display_fields', $corps) && is_array($corps['display_fields'])) {
            $payload['display_fields'] = array_map(
                static function (mixed $champ): string {
                    $nettoye = trim((string) $champ, '{}');
                    $position = strrpos($nettoye, '.');

                    return strtolower($position === false ? $nettoye : substr($nettoye, $position + 1));
                },
                $corps['display_fields'],
            );
        }

        return ['PATCH', $chemin, $payload];
    }

    private function derniereVue(): int
    {
        $vues = (new Vues($this->config()))->lister();

        return $vues === [] ? 0 : (int) $vues[array_key_last($vues)]['id'];
    }

    /**
     * @param array<string, mixed> $donnees
     * @param list<string> $echecs
     */
    private function mesurer(string $categorie, array $donnees, int $passes, array $echecs, int $nonRejouables): void
    {
        Vecteurs::ecrireApi($categorie, [
            'version' => 1,
            'reference' => $donnees['reference'] ?? [],
            'categorie' => $categorie,
            'total' => count($donnees['vecteurs']),
            'passes' => $passes,
            'ecarts' => $echecs,
            'non_rejouables' => $nonRejouables,
        ]);
    }
}
