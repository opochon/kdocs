<?php

declare(strict_types=1);

namespace Ged\Tests\Parite;

use PHPUnit\Framework\TestCase;

/**
 * Garde de la famille M-Files nº 5 (fiche & rendu) sur la CORRESPONDANCE de parité.
 *
 * L'agent du secteur Fiche ne pose aucune exclusion : c'est l'acte du juge. Mais la régénération de la
 * correspondance M-Files (`tools/parite/corriger-correspondance-mfiles.php`) ne doit pas non plus
 * EFFACER une exclusion déjà ratifiée par le juge (commit `dd241cc`, décision Olivier du 14.10.2026 :
 * session/auth propre à M-Files, serveur/coffres, dépôts externes). Ces deux oracles verrouillent ce
 * point : la correspondance versionnée les porte encore, et le générateur les préserve.
 */
final class MfilesFicheVecteursTest extends TestCase
{
    /**
     * Exclusions M-Files ratifiées par le juge : id => raison admise (liste fermée `Juge::EXCLUSIONS_AUTORISEES`).
     *
     * @var array<string, string>
     */
    private const EXCLUSIONS_RATIFIEES = [
        'mfiles:rest:delete:repositories-targetid-session' => 'depots-externes-mfiles',
        'mfiles:rest:delete:session' => 'auth-mfiles',
        'mfiles:rest:get:repositories' => 'depots-externes-mfiles',
        'mfiles:rest:get:server-capabilities' => 'serveur-coffres-mfiles',
        'mfiles:rest:get:server-publickey' => 'serveur-coffres-mfiles',
        'mfiles:rest:get:server-status' => 'serveur-coffres-mfiles',
        'mfiles:rest:get:server-vaults' => 'serveur-coffres-mfiles',
        'mfiles:rest:get:server-vaults-vault-status' => 'serveur-coffres-mfiles',
        'mfiles:rest:get:session' => 'auth-mfiles',
        'mfiles:rest:get:session-authenticationtoken' => 'auth-mfiles',
        'mfiles:rest:get:session-userid' => 'auth-mfiles',
        'mfiles:rest:get:session-vault' => 'auth-mfiles',
        'mfiles:rest:get:session-vaults' => 'auth-mfiles',
        'mfiles:rest:post:repositories-targetid-session' => 'depots-externes-mfiles',
        'mfiles:rest:post:server-authenticationtokens' => 'serveur-coffres-mfiles',
        'mfiles:rest:put:session' => 'auth-mfiles',
        'mfiles:rest:put:session-vault' => 'auth-mfiles',
    ];

    private const RACINE = __DIR__ . '/../../..';

    public function testLaCorrespondanceVersionneePreserveLesExclusionsRatifeesParLeJuge(): void
    {
        $elements = $this->lireCorrespondance(self::RACINE . '/docs/parite/correspondance.json');

        foreach (self::EXCLUSIONS_RATIFIEES as $id => $raison) {
            self::assertArrayHasKey($id, $elements, 'exclusion M-Files ratifiée disparue de la correspondance : ' . $id);
            self::assertSame('exclu', $elements[$id]['statut'] ?? null, 'l’exclusion ratifiée a été dégradée en « absent » : ' . $id);
            self::assertSame($raison, $elements[$id]['exclusion'] ?? null, 'raison d’exclusion modifiée pour : ' . $id);
        }
    }

    public function testLeGenerateurNeRetireJamaisUneExclusionRatifee(): void
    {
        // Rejoue le VRAI générateur dans un bac à sable : il ne doit pas transformer une exclusion en
        // « absent ». On lui donne la correspondance réelle et l'inventaire réel, dans une copie isolée.
        $bac = sys_get_temp_dir() . '/gedv2-fiche-parite-' . bin2hex(random_bytes(6));
        $racineBac = $bac;
        self::assertTrue(is_dir($bac) || mkdir($bac, 0777, true));
        self::assertTrue(mkdir($bac . '/tools/parite', 0777, true));
        self::assertTrue(mkdir($bac . '/docs/parite', 0777, true));
        copy(self::RACINE . '/tools/parite/corriger-correspondance-mfiles.php', $bac . '/tools/parite/corriger-correspondance-mfiles.php');
        copy(self::RACINE . '/docs/parite/correspondance.json', $bac . '/docs/parite/correspondance.json');
        copy(self::RACINE . '/docs/parite/inventaire-mfiles.json', $bac . '/docs/parite/inventaire-mfiles.json');

        try {
            $commande = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bac . '/tools/parite/corriger-correspondance-mfiles.php');
            exec($commande . ' 2>&1', $lignes, $code);
            self::assertSame(0, $code, 'le générateur a échoué : ' . implode(' | ', $lignes));

            $elements = $this->lireCorrespondance($bac . '/docs/parite/correspondance.json');
            foreach (self::EXCLUSIONS_RATIFIEES as $id => $raison) {
                self::assertSame('exclu', $elements[$id]['statut'] ?? null, 'le générateur a dégradé l’exclusion ratifiée : ' . $id);
                self::assertSame($raison, $elements[$id]['exclusion'] ?? null, 'le générateur a perdu la raison d’exclusion : ' . $id);
            }
        } finally {
            $this->supprimerRecursif($racineBac);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function lireCorrespondance(string $chemin): array
    {
        self::assertFileExists($chemin);
        $document = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document['elements'] ?? null);

        /** @var array<string, array<string, mixed>> $elements */
        $elements = $document['elements'];

        return $elements;
    }

    private function supprimerRecursif(string $chemin): void
    {
        if (!is_dir($chemin)) {
            @unlink($chemin);

            return;
        }
        foreach (scandir($chemin) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $this->supprimerRecursif($chemin . '/' . $entree);
        }
        @rmdir($chemin);
    }
}
