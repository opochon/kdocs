<?php

declare(strict_types=1);

namespace Tests\Feature;

use KDocs\Services\TenantScopeService;
use PHPUnit\Framework\TestCase;

/**
 * GAP-041 — multi-mandant : un document du mandant A est invisible pour un
 * utilisateur du mandant B, via canSee() et via le fragment scopeSql() injecté
 * dans les listings (DocumentsApiController::index/show).
 * Hermétique : SQLite en mémoire, activation forcée par le constructeur.
 */
class MultitenantIsolationTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE documents (
            id INTEGER PRIMARY KEY,
            title TEXT,
            tenant_id INTEGER,
            deleted_at TEXT
        )');

        // Mandant 1 : doc 10 · mandant 2 : doc 20 · global : doc 30
        $this->db->exec("INSERT INTO documents (id, title, tenant_id) VALUES
            (10, 'Facture mandant A', 1),
            (20, 'Facture mandant B', 2),
            (30, 'Note globale', NULL)");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listVisible(TenantScopeService $scope, array $user): array
    {
        $where = ['d.deleted_at IS NULL'];
        $params = [];

        $fragment = $scope->scopeSql('d', $user);
        if ($fragment['sql'] !== '') {
            $where[] = $fragment['sql'];
            $params = array_merge($params, $fragment['params']);
        }

        $stmt = $this->db->prepare(
            'SELECT d.id FROM documents d WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id'
        );
        $stmt->execute($params);

        return array_map('intval', array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id'));
    }

    public function testCanSeeIsoleLesMandants(): void
    {
        $scope = new TenantScopeService(true);
        $userA = ['id' => 1, 'tenant_id' => 1];
        $docA = ['id' => 10, 'tenant_id' => 1];
        $docB = ['id' => 20, 'tenant_id' => 2];

        $this->assertTrue($scope->canSee($userA, $docA), 'l’utilisateur voit son mandant');
        $this->assertFalse($scope->canSee($userA, $docB), 'le document du mandant B doit être invisible');
    }

    public function testScopeSqlFiltreLeListing(): void
    {
        $scope = new TenantScopeService(true);

        $this->assertSame([10, 30], $this->listVisible($scope, ['id' => 1, 'tenant_id' => 1]));
        $this->assertSame([20, 30], $this->listVisible($scope, ['id' => 2, 'tenant_id' => 2]));
    }

    public function testDocumentGlobalVisibleParTous(): void
    {
        $scope = new TenantScopeService(true);

        $this->assertTrue($scope->canSee(['tenant_id' => 1], ['tenant_id' => null]));
        $this->assertTrue($scope->canSee(['tenant_id' => 2], ['tenant_id' => null]));
    }

    public function testUtilisateurGlobalVoitTout(): void
    {
        $scope = new TenantScopeService(true);

        $this->assertTrue($scope->canSee(['tenant_id' => null], ['tenant_id' => 2]));
        $this->assertSame([10, 20, 30], $this->listVisible($scope, ['id' => 9, 'tenant_id' => null]));
    }

    public function testDesactiveToutVisible(): void
    {
        $scope = new TenantScopeService(false);

        $this->assertFalse($scope->isEnabled());
        $this->assertTrue($scope->canSee(['tenant_id' => 1], ['tenant_id' => 2]));
        $this->assertSame([10, 20, 30], $this->listVisible($scope, ['id' => 1, 'tenant_id' => 1]));
    }

    public function testDesactiveParDefautSansEnv(): void
    {
        // Sans MULTI_TENANT_ENABLED posé, le scope est inactif (pas de régression).
        $scope = new TenantScopeService();

        $this->assertFalse($scope->isEnabled());
    }
}
