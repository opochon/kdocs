<?php

declare(strict_types=1);

/**
 * Oracle du secteur tracabilite-audit — la piste de revision couvre-t-elle
 * le chemin que l'interface emprunte reellement ?
 *
 * Constat du 2026-08-09 : audit_logs porte 1261 lignes et fonctionne, mais
 * la couverture est trompeuse. Les ecritures viennent de auth.login (1022) et
 * des controleurs web historiques. AUCUN controleur de app/Controllers/Api/
 * n'ecrit de ligne d'audit — or les templates appellent /api/documents/ 28 fois.
 * Les mutations passees par l'interface moderne ne laissent aucune trace.
 *
 * Pour une GED fiduciaire suisse, une piste de revision incomplete ne vaut pas
 * mieux qu'une piste absente : elle donne l'illusion de la preuve. GeBuV / Olico
 * exigent de pouvoir retracer qui a modifie quoi, et quand.
 *
 * Cet oracle epingle le CABLAGE de l'audit sur les methodes qui modifient un
 * document. Il tombe des qu'une mutation cesse d'etre journalisee.
 *
 * @see governance/sectors.json  secteur tracabilite-audit
 * @see AGENTS.md  regle 2 : l'oracle prouve le cablage
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class ApiAuditTrailTest extends TestCase
{
    private const CONTROLEUR = __DIR__ . '/../../app/Controllers/Api/DocumentsApiController.php';

    /**
     * Methodes de l'API qui modifient l'etat d'un document. Chacune doit
     * laisser une trace. Cette liste ne descend jamais : en retirer une
     * methode, c'est accepter une mutation invisible.
     */
    private const MUTATIONS = [
        'update',
        'delete',
        'updateType',
        'updateCorrespondent',
        'updateFields',
        'addTags',
        'removeTag',
    ];

    private function source(): string
    {
        $src = file_get_contents(self::CONTROLEUR);
        $this->assertIsString($src, 'DocumentsApiController illisible');
        return $src;
    }

    private function corpsDeMethode(string $src, string $methode): ?string
    {
        $debut = strpos($src, "function {$methode}(");
        if ($debut === false) {
            return null;
        }
        $fin = preg_match(
            '/\n    (?:public|protected|private)\s+function\s+/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE,
            $debut + 10
        ) ? $m[0][1] : strlen($src);

        return substr($src, $debut, $fin - $debut);
    }

    /**
     * Le controleur API doit connaitre le service d'audit. Sans cela, aucune
     * de ses mutations ne peut laisser de trace.
     */
    public function testLeControleurApiReferenceLeServiceDAudit(): void
    {
        $this->assertStringContainsString(
            'AuditService',
            $this->source(),
            "DocumentsApiController ne reference pas AuditService. Les mutations "
            . "passees par l'API — le chemin que l'interface emprunte — ne laissent "
            . "aucune trace dans la piste de revision."
        );
    }

    /**
     * Chaque mutation de l'API journalise.
     * @dataProvider mutations
     */
    public function testChaqueMutationDeLApiEstJournalisee(string $methode): void
    {
        $corps = $this->corpsDeMethode($this->source(), $methode);
        $this->assertNotNull($corps, "Methode {$methode}() introuvable dans le controleur");

        $this->assertMatchesRegularExpression(
            '/AuditService::|journaliser/i',
            $corps,
            "DocumentsApiController::{$methode}() modifie un document sans laisser "
            . "de trace. Une mutation invisible n'est pas auditable, donc pas opposable."
        );
    }

    /** @return array<string,array{string}> */
    public static function mutations(): array
    {
        $cas = [];
        foreach (self::MUTATIONS as $m) {
            $cas[$m] = [$m];
        }
        return $cas;
    }

    /**
     * La table cible doit etre la bonne. Deux tables homonymes coexistent :
     * audit_logs (pluriel, 1261 lignes, vivante) et audit_log (singulier, vide,
     * colonnes differentes). Ecrire dans la mauvaise revient a ne rien ecrire.
     */
    public function testLeModeleEcritDansLaTableVivante(): void
    {
        $modele = file_get_contents(__DIR__ . '/../../app/Models/AuditLog.php');
        $this->assertIsString($modele, 'AuditLog illisible');

        $this->assertMatchesRegularExpression(
            '/INSERT\s+INTO\s+`?audit_logs`?/i',
            $modele,
            "AuditLog n'insere pas dans audit_logs. La table audit_log (singulier) "
            . "existe vide avec d'autres colonnes : y ecrire echoue en silence."
        );
    }
}
