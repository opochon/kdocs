<?php
/**
 * WinBiz Connector
 * Connexion a WinBiz via ODBC (FoxPro)
 *
 * @package KDocs\Connectors\WinBiz
 */

namespace KDocs\Connectors\WinBiz;

class WinBizConnector
{
    private $connection = null;
    private array $config;
    private bool $connected = false;

    public function __construct()
    {
        $this->config = require __DIR__ . '/config.php';
    }

    /**
     * Etablit la connexion ODBC
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        if (!$this->config['connector']['enabled']) {
            throw new \RuntimeException('Connecteur WinBiz non active');
        }

        if (!extension_loaded('odbc')) {
            throw new \RuntimeException('Extension PHP ODBC non chargee');
        }

        $dsn = is_callable($this->config['dsn'])
            ? ($this->config['dsn'])()
            : $this->config['dsn'];

        $this->connection = @odbc_connect($dsn, '', '');

        if (!$this->connection) {
            throw new \RuntimeException('Connexion WinBiz echouee: ' . odbc_errormsg());
        }

        $this->connected = true;
        return true;
    }

    /**
     * Ferme la connexion
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            odbc_close($this->connection);
            $this->connection = null;
            $this->connected = false;
        }
    }

    /**
     * Recherche des articles
     */
    public function searchArticles(string $query, int $limit = 50): array
    {
        $this->connect();

        $table = $this->config['tables']['articles'];
        $mapping = $this->config['field_mapping']['articles'];

        $sql = "SELECT TOP {$limit} * FROM {$table} WHERE {$mapping['designation']} LIKE ?";

        return $this->executeQuery($sql, ['%' . $query . '%']);
    }

    /**
     * Recupere un article par code
     */
    public function getArticle(string $code): ?array
    {
        $this->connect();

        $table = $this->config['tables']['articles'];
        $mapping = $this->config['field_mapping']['articles'];

        $sql = "SELECT * FROM {$table} WHERE {$mapping['code']} = ?";
        $results = $this->executeQuery($sql, [$code]);

        return $results[0] ?? null;
    }

    /**
     * Recherche des clients
     */
    public function searchClients(string $query, int $limit = 50): array
    {
        $this->connect();

        $table = $this->config['tables']['clients'];
        $mapping = $this->config['field_mapping']['clients'];

        $sql = "SELECT TOP {$limit} * FROM {$table} WHERE {$mapping['nom']} LIKE ?";

        return $this->executeQuery($sql, ['%' . $query . '%']);
    }

    /**
     * Recupere les bons de livraison
     */
    public function getBonsLivraison(string $clientCode = null, int $limit = 100): array
    {
        $this->connect();

        $table = $this->config['tables']['bons_livraison'];

        $sql = "SELECT TOP {$limit} * FROM {$table}";
        $params = [];

        if ($clientCode) {
            $sql .= " WHERE CLI_CODE = ?";
            $params[] = $clientCode;
        }

        $sql .= " ORDER BY BL_DATE DESC";

        return $this->executeQuery($sql, $params);
    }

    /**
     * Recupere un bon de livraison par numero
     */
    public function getBonLivraison(string $numero): ?array
    {
        $this->connect();

        $table = $this->config['tables']['bons_livraison'];
        $sql = "SELECT * FROM {$table} WHERE BL_NUM = ?";
        $results = $this->executeQuery($sql, [$numero]);

        return $results[0] ?? null;
    }

    /**
     * Recupere les fiches de travail
     */
    public function getFichesTravail(string $clientCode = null, int $limit = 100): array
    {
        $this->connect();

        $table = $this->config['tables']['fiches_travail'];

        $sql = "SELECT TOP {$limit} * FROM {$table}";
        $params = [];

        if ($clientCode) {
            $sql .= " WHERE CLI_CODE = ?";
            $params[] = $clientCode;
        }

        $sql .= " ORDER BY FT_DATE DESC";

        return $this->executeQuery($sql, $params);
    }

    /**
     * Execute une requete SQL
     */
    protected function executeQuery(string $sql, array $params = []): array
    {
        if (!$this->connected) {
            $this->connect();
        }

        // Verifier table autorisee
        if ($this->config['security']['read_only']) {
            if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/i', $sql)) {
                throw new \RuntimeException('Ecriture interdite en mode read_only');
            }
        }

        $stmt = odbc_prepare($this->connection, $sql);
        if (!$stmt) {
            throw new \RuntimeException('Erreur preparation requete: ' . odbc_errormsg($this->connection));
        }

        $result = odbc_execute($stmt, $params);
        if (!$result) {
            throw new \RuntimeException('Erreur execution requete: ' . odbc_errormsg($this->connection));
        }

        $rows = [];
        while ($row = odbc_fetch_array($stmt)) {
            $rows[] = $row;

            if (count($rows) >= $this->config['security']['max_results']) {
                break;
            }
        }

        odbc_free_result($stmt);

        return $rows;
    }

    /**
     * Teste la connexion
     */
    public function testConnection(): array
    {
        try {
            $this->connect();

            // Tester une requete simple
            $articles = $this->searchArticles('', 1);

            return [
                'success' => true,
                'message' => 'Connexion WinBiz OK',
                'db_path' => $this->config['odbc']['db_path'],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        } finally {
            $this->disconnect();
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
