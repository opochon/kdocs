<?php
/**
 * Service d'isolation multi-mandant (GAP-041).
 *
 * Quand MULTI_TENANT_ENABLED=true, les documents sont filtrés par tenant_id :
 *  - doc.tenant_id NULL  → document global (visible par tous).
 *  - user.tenant_id NULL → utilisateur global (voit tout).
 *  - Sinon : égalité stricte des tenant_id.
 *
 * Désactivé → comportement actuel inchangé (pas de régression).
 *
 * Intégration dans les listings :
 *  - DocumentsApiController::index()  ligne ~29  (scope WHERE injecté via scopeSql)
 *  - DocumentsApiController::show()   ligne ~128 (canSee après fetch)
 *
 * @see Tests\Feature\MultitenantIsolationTest
 */

namespace KDocs\Services;

class TenantScopeService
{
    private bool $enabled;

    /**
     * @param bool|null $enabled Surcharge explicite pour les tests (null = lire env).
     */
    public function __construct(?bool $enabled = null)
    {
        if ($enabled !== null) {
            $this->enabled = $enabled;
        } else {
            $this->enabled = filter_var(env('MULTI_TENANT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        }
    }

    /**
     * Renvoie true si le mode multi-mandant est actif.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Un utilisateur peut-il voir un document ?
     *
     * - Multi-tenant désactivé            → true
     * - doc.tenant_id NULL                 → true (document global)
     * - user.tenant_id NULL                → true (utilisateur global)
     * - Sinon : égalité des tenant_id      → bool
     */
    public function canSee(array $user, array $doc): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $docTenant  = $doc['tenant_id']  ?? null;
        $userTenant = $user['tenant_id'] ?? null;

        if ($docTenant === null || $userTenant === null) {
            return true;
        }

        return (int) $docTenant === (int) $userTenant;
    }

    /**
     * Retourne un fragment SQL WHERE à injecter dans les listings.
     *
     * Si multi-tenant désactivé ou user global → sql vide (pas de filtre).
     *
     * @param  string $alias Alias de la table documents (ex. « d »)
     * @param  array  $user  Tableau utilisateur (tenant_id, …)
     * @return array{sql: string, params: array}
     */
    public function scopeSql(string $alias, array $user): array
    {
        if (!$this->enabled) {
            return ['sql' => '', 'params' => []];
        }

        $userTenant = $user['tenant_id'] ?? null;
        if ($userTenant === null) {
            return ['sql' => '', 'params' => []];
        }

        // Nettoyage de l'alias pour éviter toute injection
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'documents';

        return [
            'sql'    => "({$t}.tenant_id IS NULL OR {$t}.tenant_id = ?)",
            'params' => [(int) $userTenant],
        ];
    }
}
