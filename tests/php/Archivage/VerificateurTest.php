<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\Scellement;
use Ged\Archivage\Verificateur;
use PHPUnit\Framework\TestCase;

/**
 * Oracle du vérificateur (clause 3 du lot V2-09) : un fonds sain rend 0 écart, et le moindre octet changé
 * (document, métadonnées, jeton) est trouvé et nommé.
 */
final class VerificateurTest extends TestCase
{
    use SupportArchivage;

    protected function setUp(): void
    {
        $this->setUpArchivage();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchivage();
    }

    private function sceller(string $nom = 'facture-verif.pdf'): int
    {
        $id = $this->deposer($nom, ['titre' => 'Facture vérification ' . $nom, 'type' => 'facture', 'date' => '2026-03-01']);
        $this->scelleur()->sceller($id, 'olivia');

        return $id;
    }

    public function testFondsSainRendZeroEcart(): void
    {
        $id = $this->sceller();
        $bilan = (new Verificateur($this->racine))->verifierFonds();

        self::assertSame(1, $bilan['scellements']);
        self::assertTrue($bilan['ok'], 'un fonds sain ne doit produire aucun écart : ' . implode(' | ', $bilan['ecarts']));
        self::assertSame([], $bilan['ecarts']);
    }

    public function testUnJetonForgeAnnonceCommePublicEstRefuse(): void
    {
        $id = $this->sceller('jeton-forge.pdf');
        $absolu = $this->absolu($id);
        $json = Scellement::cheminJson($absolu);
        $scellement = Scellement::lire($absolu, $id);
        self::assertNotNull($scellement);
        $scellement['tsa_type'] = 'publique_non_qualifiee';
        $scellement['tsa_requete'] = 'inexistante.tsq';
        $scellement['tsa_chaine'] = 'inexistante.pem';
        file_put_contents($json, json_encode($scellement, JSON_THROW_ON_ERROR));

        $bilan = (new Verificateur($this->racine))->verifier($json);

        self::assertFalse($bilan['ok']);
        self::assertStringContainsString('nonce invérifiable', implode(' ', $bilan['ecarts']));
    }

    public function testUnOctetChangeDansLeDocumentEstTrouve(): void
    {
        $id = $this->sceller();
        $absolu = $this->absolu($id);
        file_put_contents($absolu, (string) file_get_contents($absolu) . 'Y');

        $bilan = (new Verificateur($this->racine))->verifierFonds();

        self::assertFalse($bilan['ok']);
        self::assertStringContainsString('fichier', strtolower(implode(' | ', $bilan['ecarts'])));
    }

    public function testUneMetaModifieeEstTrouvee(): void
    {
        $id = $this->sceller('facture-meta.pdf');
        $absolu = $this->absolu($id);
        $meta = json_decode((string) file_get_contents($absolu . '.meta.json'), true) ?: [];
        $meta['titre'] = 'Titre réécrit hors application';
        file_put_contents($absolu . '.meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $bilan = (new Verificateur($this->racine))->verifierFonds();

        self::assertFalse($bilan['ok']);
        self::assertStringContainsString('meta', strtolower(implode(' | ', $bilan['ecarts'])));
    }

    public function testUnJetonAltereEstTrouve(): void
    {
        $id = $this->sceller('facture-token.pdf');
        $jeton = Scellement::cheminJeton($this->absolu($id));
        $octets = (string) file_get_contents($jeton);
        $octets[5] = chr(ord($octets[5]) ^ 0x01);   // un octet du jeton change
        file_put_contents($jeton, $octets);

        $bilan = (new Verificateur($this->racine))->verifierFonds();

        self::assertFalse($bilan['ok']);
        self::assertStringContainsString('jeton', strtolower(implode(' | ', $bilan['ecarts'])));
    }

    public function testUnScellementIllisibleEstSignale(): void
    {
        $id = $this->sceller('facture-illisible.pdf');
        file_put_contents(Scellement::cheminJson($this->absolu($id)), '{ceci n’est pas du JSON');

        $bilan = (new Verificateur($this->racine))->verifierFonds();

        self::assertFalse($bilan['ok']);
        self::assertStringContainsString('illisible', strtolower(implode(' | ', $bilan['ecarts'])));
    }
}
