<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Fonds;
use PDO;
use Throwable;

/**
 * Fiche document (/documents/{id}) branchée sur le fonds réel : titre, type, émetteur, date, montant,
 * rangement et nombre de pages viennent du `.meta.json` à côté du fichier (le dossier fait foi), les
 * versions et l'historique de l'index, et l'aperçu de la MÊME visionneuse que Bibliothèque et À traiter.
 *
 * Un identifiant inconnu du fonds rend `null` : `Ecrans::document` retombe alors sur la maquette, donc
 * jamais d'écran muet ni de 500.
 */
final class Document
{
    public function __construct(private readonly Config $config)
    {
    }

    /** Construit la fiche depuis la racine du projet (la config vient de l'environnement). */
    public static function depuisRacine(?string $racine = null): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)));
    }

    /** @return array<string, mixed>|null */
    public function donnees(int $id): ?array
    {
        $fonds = new Fonds($this->config);
        $pdo = null;
        try {
            $pdo = Base::pdo($this->config);
        } catch (Throwable) {
            // Base muette : le dossier fait foi et peut encore répondre.
        }

        try {
            $doc = $fonds->parId($id, $pdo);
        } catch (Throwable) {
            return null;
        }
        if ($doc === null) {
            return null;
        }

        $chemin = (string) $doc['chemin'];
        $absolu = $fonds->racine() . '/' . $chemin;
        // Les VALEURS canoniques se relisent du `.meta.json` (l'index n'en est qu'une copie).
        $meta = $this->meta($absolu);
        $meta['titre'] ??= (string) $doc['titre'];

        return [
            'id' => (int) $doc['id'],
            'titre' => (string) $doc['titre'],
            'type' => (string) ($doc['type'] ?? ''),
            'emetteur' => (string) ($doc['emetteur'] ?? ''),
            'date' => $doc['date'],
            'montant' => $doc['montant'],
            'dossier' => dirname($chemin) === '.' ? '' : dirname($chemin),
            'fichier' => basename($chemin),
            'texte' => self::lignesTexte($meta['texte'] ?? null),
            'rendu' => Rendu::pour((int) $doc['id'], $absolu, $meta),
            'versions' => $this->versions($pdo, (int) $doc['id']),
            'historique' => $this->historique($pdo, (int) $doc['id']),
            'notes' => [],
            'liens' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function meta(string $absolu): array
    {
        $fichier = $absolu . '.meta.json';
        if (!is_file($fichier)) {
            return [];
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);

        return is_array($donnees) ? $donnees : [];
    }

    /** Versions réellement archivées dans `.versions/` (lues de l'index, jamais inventées). @return list<array<string, mixed>> */
    private function versions(?PDO $pdo, int $id): array
    {
        if ($pdo === null) {
            return [];
        }
        try {
            $requete = $pdo->prepare('SELECT numero, nom_archive, taille, cree_le FROM document_versions'
                . ' WHERE document_id = ? ORDER BY numero DESC');
            $requete->execute([$id]);
            $lignes = $requete->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $v): array => [
            'n' => (int) $v['numero'],
            'quand' => (string) $v['cree_le'],
            'qui' => 'système',
            'quoi' => 'contenu archivé (' . (string) $v['nom_archive'] . ')',
            'taille' => self::taille((int) $v['taille']),
        ], $lignes);
    }

    /** Déplacements réellement tracés par le dépôt / l'indexeur. @return list<array{quand:string, quoi:string}> */
    private function historique(?PDO $pdo, int $id): array
    {
        if ($pdo === null) {
            return [];
        }
        try {
            $requete = $pdo->prepare('SELECT chemin_avant, chemin_apres, cause, auteur, date_deplacement'
                . ' FROM document_deplacements WHERE document_id = ? ORDER BY date_deplacement DESC');
            $requete->execute([$id]);
            $lignes = $requete->fetchAll();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (array $d): array => [
            'quand' => (string) $d['date_deplacement'],
            'quoi' => 'Déplacé : ' . (string) $d['chemin_avant'] . ' → ' . (string) $d['chemin_apres']
                . ' (' . (string) $d['cause'] . ', ' . (string) $d['auteur'] . ')',
        ], $lignes);
    }

    private static function taille(int $octets): string
    {
        if ($octets < 1024) {
            return $octets . ' o';
        }
        if ($octets < 1024 * 1024) {
            return round($octets / 1024) . ' Ko';
        }

        return round($octets / (1024 * 1024), 1) . ' Mo';
    }

    /** @return list<string> */
    private static function lignesTexte(mixed $texte): array
    {
        if (is_array($texte)) {
            $lignes = array_map('strval', $texte);
        } elseif (is_string($texte) && trim($texte) !== '') {
            $lignes = preg_split('/\R/u', $texte) ?: [];
        } else {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lignes), static fn (string $l): bool => $l !== ''));
    }
}
