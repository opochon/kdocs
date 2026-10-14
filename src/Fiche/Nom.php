<?php

declare(strict_types=1);

namespace Ged\Fiche;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use PDO;
use RuntimeException;
use Throwable;

/**
 * TITRE DE FICHIER d'un objet : lire son nom rangé et le renommer. Équivalent GEDv2 de
 * `GET|PUT /objects/{type}/{objectid}/{version}/files/{file}/title`.
 *
 * Le renommage est un ACTE réel, jamais une copie ni une suppression : le fichier, son `.meta.json` et
 * son dossier `.versions/` suivent ensemble (`Socle\Fichiers::deplacerAvecAnnexes`), l'index est mis à
 * jour, le geste est tracé dans `document_deplacements` (cause `renommage`) et le TITRE DE L'OBJET
 * (`.meta.json`) ne bouge pas. Le nom garde l'extension du fichier courant : changer de format de
 * fichier demanderait une décision de rangement distincte.
 */
final class Nom
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{nom:string, chemin:string}
     *
     * @throws RuntimeException document inconnu
     */
    public function lire(int $idDocument): array
    {
        $ligne = (new DepotFichiers($this->config))->fiche($idDocument);
        if ($ligne === null) {
            throw new RuntimeException("nom de fichier : document $idDocument inconnu");
        }

        return ['nom' => (string) $ligne['nom'], 'chemin' => (string) $ligne['chemin_relatif']];
    }

    /**
     * Renomme le fichier d'un objet (pas son titre). Rend le nom rangé final (dédoublonné si la cible
     * existait déjà). Le refus est nommé ; sur refus, rien n'a bougé.
     *
     * @throws RuntimeException nom vide, document inconnu ou à la corbeille, droits insuffisants
     * @throws \Ged\Archivage\ErreurScellement document scellé
     */
    public function renommer(int $idDocument, string $nouveauNom, string $auteur, ?int $compte = null, bool $administrateur = false): string
    {
        $propose = trim($nouveauNom);
        if ($propose === '') {
            throw new RuntimeException('renommage : le nom du fichier est requis');
        }

        $depot = new DepotFichiers($this->config);
        $ligne = $depot->fiche($idDocument);
        if ($ligne === null) {
            throw new RuntimeException("renommage : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] !== null) {
            throw new RuntimeException("renommage : le document $idDocument est à la corbeille, le restaurer d’abord");
        }

        $ancien = (string) $ligne['chemin_relatif'];
        $extension = strtolower(pathinfo($ancien, PATHINFO_EXTENSION));
        $base = pathinfo($propose, PATHINFO_FILENAME);
        if ($base === '') {
            $base = $propose;
        }
        $base = Fichiers::assainirSegment($base);
        $nomVoulu = $extension !== '' ? $base . '.' . $extension : $base;
        if ($nomVoulu === basename($ancien)) {
            return $nomVoulu;
        }

        $this->exigerDroitEcriture($ancien, $compte, $administrateur);

        $absoluAncien = $depot->cheminAbsolu($ancien);
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($absoluAncien, 'renommer le fichier', $idDocument);
        }

        $absoluNouveau = Fichiers::deplacerAvecAnnexes($absoluAncien, dirname($absoluAncien) . '/' . $nomVoulu);
        $nouveauRelatif = substr($absoluNouveau, strlen($depot->racine()) + 1);

        $pdo = Base::pdo($this->config);
        $pdo->prepare('UPDATE documents SET chemin_relatif = ?, nom = ? WHERE id = ?')
            ->execute([$nouveauRelatif, basename($absoluNouveau), $idDocument]);
        $pdo->prepare('INSERT INTO document_deplacements (document_id, chemin_avant, chemin_apres, cause, auteur)'
            . ' VALUES (?, ?, ?, ?, ?)')
            ->execute([$idDocument, $ancien, $nouveauRelatif, 'renommage', $auteur]);

        return basename($absoluNouveau);
    }

    /** Le compte doit pouvoir MODIFIER le dossier du document ; sans compte (oracles) ou admin, aucun refus. */
    private function exigerDroitEcriture(string $cheminRelatif, ?int $compte, bool $administrateur): void
    {
        if ($administrateur || $compte === null || $compte < 1) {
            return;
        }
        if (!class_exists(\Ged\Securite\DroitsDossiers::class)) {
            return;
        }

        $pdo = null;
        try {
            $pdo = Base::pdo($this->config);
        } catch (Throwable $e) {
            error_log('renommage : base indisponible pour le contrôle des droits — ' . $e->getMessage());
        }
        if ($pdo instanceof PDO) {
            try {
                $droits = new \Ged\Securite\DroitsDossiers($pdo);
                $dossier = $droits->dossierPourChemin($cheminRelatif);
                if ($dossier !== null && $droits->autorise($compte, $dossier, 'modifier')) {
                    return;
                }
            } catch (Throwable $e) {
                // Schéma d'ACL illisible : on NE laisse PAS passer, le refus est nommé et tracé.
                error_log('renommage : contrôle des droits indisponible — ' . $e->getMessage());
                throw new RuntimeException(sprintf(
                    'renommage : droits du dossier « %s » illisibles (contrôle indisponible)',
                    dirname($cheminRelatif)
                ), 0, $e);
            }
        }

        throw new RuntimeException(sprintf(
            'renommage : le dossier « %s » exige le niveau « modifier »',
            dirname($cheminRelatif)
        ));
    }
}
