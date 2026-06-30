<?php

declare(strict_types=1);

/**
 * Assistant IA — intentions de decompte.
 * Épingle le fix bug E : « Combien de documents ai-je ? » doit repondre par
 * un decompte numerique (path quantite execute AVANT le early-return total=0),
 * pas par « Je n'ai trouve aucun document » (recherche litterale de la phrase).
 *
 * Hermetique : phpunit.xml neutralise l'IA -> questionToSearchQuery() retourne
 * null -> fallback sans reseau. La recherche avancee utilise la base de test
 * (kdocs_test), comme SearchServiceTest.
 */

namespace KDocs\Tests\Unit\Services;

use KDocs\Services\NaturalLanguageQueryService;
use KDocs\Tests\TestCase;

class NaturalLanguageQueryCountTest extends TestCase
{
    private NaturalLanguageQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NaturalLanguageQueryService();
    }

    /**
     * « Combien de documents ai-je ? » -> reponse numerique, jamais le message
     * « aucun document correspondant » (qui etait le bug : recherche litterale
     * de la phrase "combien de documents" -> 0 hits -> early-return).
     */
    public function testCountAllQuestionReturnsQuantityResponse(): void
    {
        $result = $this->service->query('Combien de documents ai-je ?');

        $this->assertIsInt($result->total);
        $this->assertGreaterThanOrEqual(0, $result->total);
        $this->assertNotEmpty($result->aiResponse);

        // Le path quantite s'execute avant le early-return total=0 : on a toujours
        // « J'ai trouvé **N ... » meme si N=0.
        $this->assertStringContainsString("J'ai trouvé", $result->aiResponse);
        $this->assertStringNotContainsString(
            'Je n\'ai trouvé aucun document correspondant',
            $result->aiResponse
        );
        $this->assertMatchesRegularExpression('/J\'ai trouvé \*\*\d+/', $result->aiResponse);
    }

    /**
     * Variante lexicale : « Combien de fichiers ? » doit aussi etre detectee
     * comme une question de decompte global.
     */
    public function testCountAllQuestionAcceptsFichiersSynonym(): void
    {
        $result = $this->service->query('Combien de fichiers ai-je dans la GED ?');

        $this->assertStringContainsString("J'ai trouvé", $result->aiResponse);
        $this->assertStringNotContainsString(
            'Je n\'ai trouvé aucun document correspondant',
            $result->aiResponse
        );
    }

    /**
     * Le filtre textuel est vide pour une question count-all pure (aucun filtre
     * semantique) -> la recherche ne filtre PAS sur la phrase litterale.
     * result->query = la question (texte vide remplace par la question).
     */
    public function testCountAllQuestionDoesNotFilterOnLiteralText(): void
    {
        $result = $this->service->query('Combien de documents ai-je ?');

        // text vide -> query retombe sur la question originale.
        $this->assertSame('Combien de documents ai-je ?', $result->query);
    }

    /**
     * Question non-decompte (« notaire ») -> fallback d'extraction de termes,
     * pas d'erreur HTML/JSON (bug E : l'API renvoyait parfois du HTML au lieu
     * de JSON). Au niveau service : on obtient un SearchResult coherent.
     */
    public function testNonCountQuestionFallsBackToTextSearch(): void
    {
        $result = $this->service->query('notaire');

        $this->assertIsInt($result->total);
        $this->assertNotEmpty($result->aiResponse);
        // Le terme « notaire » est extrait comme recherche textuelle.
        $this->assertStringContainsString('notaire', strtolower($result->query));
    }

    /**
     * « Combien de documents de 2024 » : la detection count-all s'active, mais
     * le filtre date (si l'IA ou le repli le pose) doit etre conserve. Ici, sans
     * IA, aucun filtre date n'est pose -> comportement identique au count-all
     * global (on verifie juste qu'on ne plante pas et qu'on repond par un nombre).
     */
    public function testCountQuestionWithDateDoesNotCrash(): void
    {
        $result = $this->service->query('Combien de documents de 2024 ?');

        $this->assertIsInt($result->total);
        $this->assertStringContainsString("J'ai trouvé", $result->aiResponse);
    }
}
