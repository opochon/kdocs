<?php

declare(strict_types=1);

namespace Ged\Tests\Classement;

use Ged\Base;
use Ged\Classement\ClasseurGed;
use Ged\Classement\ClientIa;
use Ged\Classement\ClientIaNul;
use Ged\Classement\Habitudes;
use Ged\Classement\Schema;
use Ged\Config;
use PDO;

/**
 * Socle commun des oracles du secteur Classement : base gedv2_test remise à neuf, table d'apprentissage
 * vide. Aucune donnée réelle, aucun appel réseau (le client IA est toujours injecté).
 */
trait SupportClassement
{
    private PDO $pdo;
    private Config $config;

    protected function setUpClassement(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'les tests ne touchent que gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        // Base de TEST uniquement : on repart d'une mémoire vide pour que l'apprentissage soit reproductible.
        $this->pdo->exec('DROP TABLE IF EXISTS classement_choix');
        Schema::installer($this->pdo);
    }

    /** Un classeur branché sur la base de test, sans IA par défaut (aucun appel réseau). */
    private function classeur(?ClientIa $ia = null): ClasseurGed
    {
        return new ClasseurGed($this->config, $this->pdo, $ia ?? new ClientIaNul(), new Habitudes($this->pdo));
    }
}
