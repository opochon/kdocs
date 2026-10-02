<?php

declare(strict_types=1);

namespace Ged\Tests\Archivage;

use Ged\Archivage\EcranArchives;
use Ged\Archivage\Scellement;
use Ged\Archivage\TacheVerification;
use PHPUnit\Framework\TestCase;

/**
 * Oracle V2-09b : les données affichables viennent du fonds scellé et la vérification est une
 * tâche reprise, jamais un traitement HTTP long. La TSA reste la simulation du SupportArchivage.
 */
final class InterfaceArchivageTest extends TestCase
{
    use SupportArchivage;

    protected function setUp(): void
    {
        $this->setUpArchivage();
    }

    protected function tearDown(): void
    {
        $this->tearDownArchivage();
    }

    public function testArchivesListeLeScellementEtLesEcheancesProches(): void
    {
        $id = $this->deposer('facture-interface.pdf', [
            'titre' => 'Facture interface synthétique', 'type' => 'facture', 'date' => '2016-05-01',
        ]);
        $this->scelleur()->sceller($id, 'recette');

        $ecran = new EcranArchives($this->configArchivage(), $this->horloge());
        $donnees = $ecran->donnees(['type' => 'facture', 'echeance' => 'proche']);

        self::assertCount(1, $donnees['documents']);
        self::assertSame($id, $donnees['documents'][0]['document_id']);
        self::assertSame('2026-05-01', $donnees['documents'][0]['echeance']);
        self::assertCount(1, $donnees['a_echeance'], 'une échéance à moins de 90 jours est signalée');
    }

    public function testVerificationRepriseNommeUnFichierModifie(): void
    {
        $id = $this->deposer('facture-verification-interface.pdf', [
            'titre' => 'Facture vérification synthétique', 'type' => 'facture', 'date' => '2026-03-01',
        ]);
        $this->scelleur()->sceller($id, 'recette');
        file_put_contents($this->absolu($id), 'octet modifié');

        $taches = new TacheVerification($this->racineArchivage());
        $tache = $taches->demarrer();
        while ($tache['etat'] === 'en_cours') {
            $tache = $taches->reprendre($tache['id'], 10);
        }

        self::assertSame('terminee', $tache['etat']);
        self::assertFalse($tache['ok']);
        self::assertStringContainsString('empreinte du fichier différente', implode("\n", $tache['ecarts']));
        self::assertFileExists(Scellement::cheminJson($this->absolu($id)));
    }
}
