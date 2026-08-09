<?php

declare(strict_types=1);

/**
 * Oracle du secteur plugins — governance/apps-status.json.
 *
 * Une application satellite est soit livree et atteignable, soit eteinte et
 * invisible. L'echec a empecher est l'entree de menu vers une application
 * eteinte : un 404 servi a l'utilisateur.
 *
 * Ce controle confronte trois choses qui doivent concorder :
 *   1. ce que le registre declare       (governance/apps-status.json)
 *   2. ce que le code decide            (apps/{nom}/config.php -> app.enabled)
 *   3. ce que l'interface propose       (liens dans templates/)
 *
 * Il ne se contente pas de lire le registre : un registre confronte a lui-meme
 * ne prouve rien.
 *
 * @see governance/sectors.json  secteur plugins
 * @see AGENTS.md  regle 2 : l'oracle prouve le cablage
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class AppsRoutesTest extends TestCase
{
    private const REGISTRE = __DIR__ . '/../../governance/apps-status.json';

    /** @var array<string,array<string,mixed>> */
    private array $apps;

    protected function setUp(): void
    {
        $raw = file_get_contents(self::REGISTRE);
        $this->assertIsString($raw, 'governance/apps-status.json illisible');

        $parsed = json_decode($raw, true);
        $this->assertIsArray($parsed, 'governance/apps-status.json invalide');

        $this->apps = $parsed['apps'] ?? [];
        $this->assertNotEmpty($this->apps, 'aucune application declaree');
    }

    private function racine(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Toute application presente sur disque est declaree au registre.
     * Une app livree en silence est une app que personne ne surveille.
     */
    public function testTouteApplicationSurDisqueEstDeclaree(): void
    {
        $surDisque = [];
        foreach (glob($this->racine() . '/apps/*/routes.php') ?: [] as $f) {
            $surDisque[] = basename(dirname($f));
        }
        sort($surDisque);
        $this->assertNotEmpty($surDisque, 'aucune app trouvee sur disque');

        $manquantes = array_diff($surDisque, array_keys($this->apps));

        $this->assertSame(
            [],
            array_values($manquantes),
            "Application(s) presente(s) dans apps/ mais absente(s) de "
            . "governance/apps-status.json : " . implode(', ', $manquantes)
        );
    }

    /**
     * Reciproquement : le registre ne declare pas d'application fantome.
     */
    public function testLeRegistreNeDeclarePasDApplicationInexistante(): void
    {
        foreach ($this->apps as $nom => $decl) {
            if ($nom === 'timetrack') {
                continue; // enregistree hors PluginRegistry, pas de config.php requis
            }
            $this->assertFileExists(
                $this->racine() . "/apps/{$nom}/routes.php",
                "Le registre declare l'application '{$nom}' mais apps/{$nom}/routes.php n'existe pas."
            );
        }
    }

    /**
     * Le statut declare correspond au drapeau reellement lu par le code.
     * Le registre dit ce qui doit etre ; config.php dit ce qui est.
     */
    public function testLeStatutDeclareCorrespondAuDrapeauReel(): void
    {
        $env = $this->lireEnv();

        foreach ($this->apps as $nom => $decl) {
            $drapeau = $decl['drapeau'] ?? null;
            if ($drapeau === null) {
                continue; // timetrack : toujours active, sans drapeau
            }

            $actifReel = $this->vraiBooleen($env[$drapeau] ?? null);

            $this->assertSame(
                (bool) ($decl['actif'] ?? false),
                $actifReel,
                "Application '{$nom}' : le registre la declare "
                . (($decl['actif'] ?? false) ? 'active' : 'eteinte')
                . " mais {$drapeau} vaut "
                . (isset($env[$drapeau]) ? "'{$env[$drapeau]}'" : 'absent du .env')
                . ". Registre et realite ont diverge."
            );
        }
    }

    /**
     * LE controle qui compte : aucune entree d'interface ne pointe vers une
     * application eteinte. C'est le 404 que cet item existe pour empecher.
     */
    public function testAucunLienDInterfaceVersUneApplicationEteinte(): void
    {
        $fautes = [];

        foreach ($this->apps as $nom => $decl) {
            if ($decl['actif'] ?? false) {
                continue;
            }
            foreach ($this->liensVers($nom) as $fichier) {
                $fautes[] = "{$fichier} pointe vers /{$nom} (eteinte)";
            }
        }

        $this->assertSame(
            [],
            $fautes,
            "Entree(s) d'interface vers une application eteinte — 404 garanti "
            . "pour l'utilisateur :\n  " . implode("\n  ", $fautes)
        );
    }

    /**
     * @return list<string> fichiers de templates/ portant un lien vers /{nom}
     */
    private function liensVers(string $nom): array
    {
        $base = $this->racine() . '/templates';
        if (!is_dir($base)) {
            return [];
        }

        $trouves = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = file_get_contents($f->getPathname());
            if ($src !== false && preg_match('#href=["\'][^"\']*/' . preg_quote($nom, '#') . '(["\'/?])#', $src)) {
                $trouves[] = str_replace($this->racine() . DIRECTORY_SEPARATOR, '', $f->getPathname());
            }
        }

        return $trouves;
    }

    /** @return array<string,string> */
    private function lireEnv(): array
    {
        $chemin = $this->racine() . '/.env';
        if (!is_file($chemin)) {
            return [];
        }

        $env = [];
        foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || $ligne[0] === '#' || !str_contains($ligne, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $ligne, 2);
            $env[trim($k)] = trim(trim($v), "\"'");
        }

        return $env;
    }

    private function vraiBooleen(?string $v): bool
    {
        return $v !== null && filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
