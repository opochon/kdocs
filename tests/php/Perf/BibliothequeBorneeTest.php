<?php

declare(strict_types=1);

namespace Ged\Tests\Perf;

use Ged\Base;
use Ged\Config;
use Ged\Socle\EcranBibliotheque;
use Ged\Socle\Schema as SchemaSocle;
use Ged\Ui\Vue;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Oracle PERF-3 (Bibliothèque sur gros fonds).
 *
 * Le constat mesuré : sur les 10 000 documents du banc, `EcranBibliotheque::donnees()` bâtissait la liste
 * en lisant TOUT l'index (la colonne `meta`, copie de chaque `.meta.json`), puis Twig rendait 10 000
 * lignes (3,3 Mo de HTML) — et sous charge, la file d'un `php -S` mono-processus faisait dépasser le seuil
 * de 1,5 s à l'écran (seule la Bibliothèque, parce qu'elle seule balayait le fonds entier).
 *
 * Ce que l'oracle exige, indépendamment de la taille du fonds :
 * - la liste rendue tient dans UNE page (`PAGE_TAILLE` lignes) ;
 * - le HTML rendu est borné (une page, pas le fonds) ;
 * - la requête ne relit pas le fonds : `Handler_read_rnd_next` doit rester borné ;
 * - l'écran répond à froid sous 1,5 s.
 *
 * Les 10 000 pièces sont synthétiques (aucune donnée réelle), insérées dans la base de TEST sous le
 * verrou de PHPUnit, puis retirées par ce même test. Le fonds n'est PAS tronqué : d'autres parcours
 * partagent `gedv2_test`.
 */
final class BibliothequeBorneeTest extends TestCase
{
    /** Fonds de l'oracle : le constat du banc de charge. */
    private const DOCUMENTS = 10000;

    /** Une page de Bibliothèque : c'est le contrat de l'écran (plan : « 100 lignes puis défilement »). */
    private const TAILLE_PAGE = 100;

    /** Borne du travail SQL : une requête de liste ne balaie pas le fonds. */
    private const LIGNES_SQL_MAX = 2000;

    private PDO $pdo;
    private Config $config;
    private string $prefixe;

    protected function setUp(): void
    {
        $this->config = new Config(dirname(__DIR__, 3));
        self::assertSame('gedv2_test', $this->config->get('DB_NAME'), 'l’oracle n’écrit que sur gedv2_test');
        Base::assurerBase($this->config);
        $this->pdo = Base::pdo($this->config);
        SchemaSocle::installer($this->pdo);

        $this->prefixe = 'perf3-' . bin2hex(random_bytes(4));
        $this->insererFonds(self::DOCUMENTS);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM documents WHERE chemin_relatif LIKE ?')->execute([$this->prefixe . '/%']);
    }

    public function testLaListeTientDansUnePageQuelQueSoitLeFonds(): void
    {
        $donnees = (new EcranBibliotheque($this->config))->donnees([], 'jeton');

        self::assertGreaterThanOrEqual(self::DOCUMENTS, $donnees['total'], 'le fonds de l’oracle est bien là');
        self::assertLessThanOrEqual(self::TAILLE_PAGE, count($donnees['docs']),
            sprintf('la Bibliothèque rend au plus %d lignes, pas %d', self::TAILLE_PAGE, self::DOCUMENTS));
        self::assertGreaterThan(1, $donnees['pages'] ?? 1, 'un fonds de 10 000 pièces tient en plusieurs pages');
    }

    public function testLeRenduNeDepassePasUnePage(): void
    {
        $donnees = (new EcranBibliotheque($this->config))->donnees([], 'jeton');
        $html = (new Vue(dirname(__DIR__, 3) . '/templates'))->html('ecrans/bibliotheque.twig', $donnees);

        $lignes = substr_count($html, 'data-testid="ligne-');
        self::assertLessThanOrEqual(self::TAILLE_PAGE, $lignes, 'une page de lignes, pas tout le fonds');
        self::assertLessThan(600000, strlen($html), 'le HTML d’un écran de liste reste borné');
    }

    public function testLaRequeteNeRelitPasLeFondsEntier(): void
    {
        $ecran = new EcranBibliotheque($this->config);
        $avant = $this->compteurAcces('Handler_read_rnd_next');
        $ecran->donnees([], 'jeton');
        $delta = $this->compteurAcces('Handler_read_rnd_next') - $avant;

        self::assertLessThan(self::LIGNES_SQL_MAX, $delta,
            sprintf('donnees() ne doit pas balayer les %d lignes du fonds (lu %d)', self::DOCUMENTS, $delta));
    }

    public function testLecranRepondAFroidSousLeSeuil(): void
    {
        // À froid : nouvelle instance, aucun cache Twig, première requête du scénario.
        $debut = microtime(true);
        $donnees = (new EcranBibliotheque($this->config))->donnees([], 'jeton');
        $html = (new Vue(dirname(__DIR__, 3) . '/templates'))->html('ecrans/bibliotheque.twig', $donnees);
        $dureeMs = (microtime(true) - $debut) * 1000.0;

        self::assertGreaterThan(1000, strlen($html), 'l’écran a réellement été rendu');
        self::assertLessThan(1500.0, $dureeMs, sprintf('écran Bibliothèque %.0f ms ≥ 1 500 ms', $dureeMs));
    }

    /** Valeur cumulée d'un compteur d'accès du serveur (delta entre deux relevés = travail réel). */
    private function compteurAcces(string $nom): int
    {
        $ligne = $this->pdo->query('SHOW SESSION STATUS LIKE ' . $this->pdo->quote($nom))->fetch(PDO::FETCH_ASSOC);

        return (int) ($ligne['Value'] ?? -1);
    }

    /**
     * Pose `$n` documents synthétiques d'un seul tenant, sans fichier : la liste et les comptages lisent
     * l'index, seul le document RETENU relit son `.meta.json` (et l'index lui sert alors de repli).
     */
    private function insererFonds(int $n): void
    {
        $types = ['Facture', 'Contrat', 'Attestation', 'Courrier', 'Devis', 'Relevé'];
        $emetteurs = ['Fournisseur Alpha', 'Fournisseur Beta', 'Client Delta', 'Assurance Zeta'];
        $texte = "maintenance contrat facture devis attestation\n" . str_repeat('pièce de charge synthétique ', 20);
        $insertion = $this->pdo->prepare('INSERT INTO documents (chemin_relatif, nom, empreinte, taille, mime, meta, '
            . 'titre, type, emetteur, date_document, montant, etiquettes, statut, source) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'valide\', \'dossier\')');

        $this->pdo->beginTransaction();
        try {
            for ($i = 1; $i <= $n; $i++) {
                $type = $types[$i % count($types)];
                $emetteur = $emetteurs[intdiv($i, 41) % count($emetteurs)];
                $date = sprintf('2026-%02d-%02d', (($i - 1) % 12) + 1, (($i - 1) % 28) + 1);
                $titre = sprintf('%s charge %05d', $type, $i);
                $nom = sprintf('piece-%05d.pdf', $i);
                $relatif = sprintf('%s/2026/%s/%s/%s', $this->prefixe, $type, str_replace(' ', '_', $emetteur), $nom);
                $meta = [
                    'titre' => $titre, 'type' => $type, 'emetteur' => $emetteur, 'date' => $date,
                    'montant' => 100.0 + $i, 'etiquettes' => ['perf3'], 'statut' => 'valide',
                    'source' => 'dossier', 'texte' => $texte,
                    'classement' => ['source' => 'regle', 'confiance' => 0.62, 'preuve' => 'mot « facture »'],
                ];
                $insertion->execute([$relatif, $nom, hash('sha256', $relatif), strlen($texte), 'application/pdf',
                    json_encode($meta, JSON_UNESCAPED_UNICODE), $titre, $type, $emetteur, $date, 100.0 + $i, '["perf3"]']);
            }
            $this->pdo->commit();
        } catch (\Throwable $erreur) {
            $this->pdo->rollBack();

            throw $erreur;
        }
    }
}
