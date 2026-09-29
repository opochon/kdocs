<?php

declare(strict_types=1);

namespace Ged\Tests\Contrats;

use Ged\Base;
use Ged\Config;
use Ged\Ingestion\Entree;
use Ged\Ingestion\Imap\Boite;
use Ged\Ingestion\Schema;
use Ged\Ingestion\Source;
use Ged\Ingestion\SourceAdresse;
use Ged\Ingestion\SourceDossier;
use Ged\Ingestion\Travaux;
use Ged\Jonctions\SourceApi;
use PDO;
use PHPUnit\Framework\TestCase;

/** Une même arrivée ne peut jamais ressortir deux fois d'une porte d'entrée. */
abstract class SourceContrat extends TestCase
{
    /** @return array{0:list<Entree>,1:list<Entree>} */
    abstract protected function releverDeuxFois(): array;

    public function testReleverEstIdempotent(): void
    {
        [$premier, $rejeu] = $this->releverDeuxFois();
        self::assertNotEmpty($premier, 'la source doit livrer l’arrivée préparée');
        self::assertSame([], $rejeu, 'la même arrivée ne doit pas être livrée deux fois');
        foreach ($premier as $entree) {
            self::assertInstanceOf(Entree::class, $entree);
            self::assertFileExists($entree->chemin);
            self::assertNotSame('', $entree->source);
            self::assertNotSame('', $entree->nomOrigine);
        }
    }
}

final class SourceDossierContratFixture extends SourceContrat
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/gedv2-contrat-dossier-' . bin2hex(random_bytes(5));
        mkdir($this->tmp . '/arrivee', 0775, true);
        file_put_contents($this->tmp . '/arrivee/facture.pdf', '%PDF-1.4 synthétique');
    }

    protected function tearDown(): void { self::supprimer($this->tmp); }

    protected function releverDeuxFois(): array
    {
        $source = new SourceDossier($this->tmp . '/arrivee', $this->tmp . '/travail', $this->tmp . '/etat.json');
        self::assertSame([], $source->relever(), 'premier relevé : le fichier doit devenir stable');
        $premier = $source->relever();

        return [$premier, $source->relever()];
    }

    public static function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($dossier);
    }
}

abstract class SourceAvecBaseContrat extends SourceContrat
{
    protected PDO $pdo;
    protected string $tmp;

    protected function setUp(): void
    {
        $config = new Config(dirname(__DIR__, 2));
        self::assertSame('gedv2_test', $config->get('DB_NAME'));
        Base::assurerBase($config);
        $this->pdo = Base::pdo($config);
        Schema::installer($this->pdo);
        $this->pdo->beginTransaction();
        $this->tmp = sys_get_temp_dir() . '/gedv2-contrat-source-' . bin2hex(random_bytes(5));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        SourceDossierContratFixture::supprimer($this->tmp);
    }
}

final class BoiteContrat implements Boite
{
    /** @param array<int,string> $messages */
    public function __construct(private array $messages) {}
    public function nom(): string { return 'contrat-source@exemple.test'; }
    public function nonLus(): array { return array_keys($this->messages); }
    public function lire(int $uid): string { return $this->messages[$uid]; }
    public function classer(int $uid, string $dossier): void {}
    public function fermer(): void {}
}
