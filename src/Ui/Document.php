<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Fonds;
use Ged\Archivage\Scellement;
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
    public function donnees(int $id, ?int $compte = null): ?array
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
        $scellement = Scellement::lire($absolu, (int) $doc['id']);
        $statut = $this->statut($pdo, (int) $doc['id']);
        $pdfa = $this->pdfa($absolu, (int) $doc['id']);
        $collaboration = null;
        try { $collaboration = new \Ged\Socle\Collaboration($this->config); } catch (Throwable) { /* base en amorçage */ }

        // Édition depuis le poste (F13) : ouverture informative affichée et copies de secours disponibles.
        $ouverture = null;
        $secours = [];
        if ($pdo !== null) {
            try {
                $ouverture = (new \Ged\Edition\Ouvertures($pdo))->active((int) $doc['id']);
            } catch (Throwable $e) {
                error_log(sprintf('[GEDv2 edition] ouverture illisible pour le document %d : %s', (int) $doc['id'], $e->getMessage()));
            }
            try {
                $secours = (new \Ged\Edition\Secours($this->config))->lister((int) $doc['id']);
            } catch (Throwable $e) {
                error_log(sprintf('[GEDv2 edition] copies de secours illisibles pour le document %d : %s', (int) $doc['id'], $e->getMessage()));
            }
        }

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
            'historique' => array_merge($this->historique($pdo, (int) $doc['id']), $collaboration?->historique((int) $doc['id']) ?? []),
            'notes' => $collaboration?->notes((int) $doc['id']) ?? [],
            'liens' => $collaboration?->liens((int) $doc['id'], $compte) ?? [],
            'partages' => $collaboration?->partages((int) $doc['id']) ?? [],
            'ouverture' => $ouverture,
            'secours' => $secours,
            'statut' => $statut,
            'pdfa' => $pdfa,
            'archivage' => [
                'scelle' => Scellement::estActif($scellement),
                'scelle_le' => (string) ($scellement['scelle_le'] ?? ''),
                'echeance' => (string) ($scellement['echeance'] ?? ''),
                'tsa_jeton' => (string) ($scellement['tsa_jeton'] ?? ''),
                'tsa_url' => (string) ($scellement['tsa_url'] ?? ''),
            ],
        ] + $this->champsPaperless($doc, $meta, $pdo, $absolu);
    }

    /**
     * Champs Paperless du Document montrés par la fiche (lot ged-parite-final-1) : propriétaire, nom de
     * fichier d'origine, nombre de pages, numéro d'archive et type de stockage. Chaque valeur vient du
     * dossier ou de la file d'ingestion ; une information absente le dit (« — » à l'écran), rien n'est
     * inventé, et une panne de lecture ne casse jamais la fiche.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function champsPaperless(array $doc, array $meta, ?\PDO $pdo, string $absolu): array
    {
        $id = (int) $doc['id'];
        $proprietaireId = isset($meta['proprietaire_id']) && is_numeric($meta['proprietaire_id'])
            ? (int) $meta['proprietaire_id'] : null;
        $proprietaire = '';
        if ($proprietaireId !== null && $pdo !== null) {
            try {
                $requete = $pdo->prepare('SELECT identifiant FROM comptes WHERE id = ?');
                $requete->execute([$proprietaireId]);
                $proprietaire = (string) ($requete->fetchColumn() ?: '');
            } catch (Throwable) {
                $proprietaire = '';
            }
        }
        $nomOrigine = (string) ($meta['nom_origine'] ?? '');
        if ($nomOrigine === '' && $pdo !== null) {
            try {
                $requete = $pdo->prepare('SELECT nom_origine FROM ingestion_travaux WHERE document_id = ? AND nom_origine <> \'\' ORDER BY id DESC LIMIT 1');
                $requete->execute([$id]);
                $nomOrigine = (string) ($requete->fetchColumn() ?: '');
            } catch (Throwable) {
                $nomOrigine = '';
            }
        }
        $pages = 0;
        try {
            $pages = (int) (\Ged\Ingestion\ParseurPiece::metadonnees($absolu, (string) ($doc['mime'] ?? ''))['pages'] ?? 0);
        } catch (Throwable) {
            $pages = isset($meta['pages']) && is_numeric($meta['pages']) ? (int) $meta['pages'] : 0;
        }

        return [
            'proprietaire_id' => $proprietaireId,
            'proprietaire' => $proprietaire,
            'nom_origine' => $nomOrigine,
            'pages' => $pages,
            'asn' => isset($meta['asn']) && $meta['asn'] !== '' ? (string) $meta['asn'] : '',
            // GEDv2 stocke le fichier LISIBLE, jamais chiffré par le produit : c'est le « plain text » sidecar
            // de Paperless, équivalent de `storage_type: unencrypted`.
            'storage_type' => 'unencrypted',
        ];
    }

    /**
     * État réel de la copie d'archivage PDF/A-2b (lot ged-pdfa-1), lu du `.meta.json` ou du flanc
     * `.pdfa/<fichier>.pdfa.json` d'un document scellé. Toute panne rend un état neutre : l'écran n'est
     * jamais muet ni en erreur 500.
     *
     * @return array<string, mixed>
     */
    private function pdfa(string $absolu, int $idDocument): array
    {
        $neutre = ['statut' => 'en_attente', 'niveau' => '2b', 'telechargeable' => false,
            'chemin' => '', 'raison' => '', 'empreinte_sha256' => '', 'reprise' => ''];
        try {
            return (new \Ged\Ingestion\CopieArchivage($this->config, \Ged\Ingestion\ConversionPdfA::depuis()))->etat($absolu, $idDocument);
        } catch (Throwable $e) {
            error_log(sprintf('[GEDv2 pdfa] état illisible pour le document %d : %s', $idDocument, $e->getMessage()));

            return $neutre;
        }
    }

    private function statut(?PDO $pdo, int $id): string
    {
        if ($pdo === null) {
            return '';
        }
        try {
            $requete = $pdo->prepare('SELECT statut FROM documents WHERE id = ?');
            $requete->execute([$id]);

            return (string) ($requete->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
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

    /**
     * Versions réellement archivées dans `.versions/` (lues de l'index, jamais inventées).
     *
     * @return list<array<string, mixed>>
     */
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
            'restaurable' => true,
        ], $lignes);
    }

    /**
     * Événements réels du Socle : versions archivées et déplacements, jamais une note inventée.
     *
     * @return list<array{quand:string, quoi:string}>
     */
    private function historique(?PDO $pdo, int $id): array
    {
        if ($pdo === null) {
            return [];
        }
        try {
            $requete = $pdo->prepare('SELECT chemin_avant, chemin_apres, cause, auteur, date_deplacement'
                . ' FROM document_deplacements WHERE document_id = ? ORDER BY date_deplacement DESC');
            $requete->execute([$id]);
            $lignes = array_map(static fn (array $d): array => [
                'quand' => (string) $d['date_deplacement'],
                'quoi' => (string) $d['cause'] === 'restauration'
                    ? 'Restauration : nouvelle version créée (' . (string) $d['auteur'] . ')'
                    : 'Déplacé : ' . (string) $d['chemin_avant'] . ' → ' . (string) $d['chemin_apres']
                        . ' (' . (string) $d['cause'] . ', ' . (string) $d['auteur'] . ')',
            ], $requete->fetchAll());
            $versions = $pdo->prepare('SELECT numero, nom_archive, cree_le FROM document_versions WHERE document_id = ?');
            $versions->execute([$id]);
            foreach ($versions->fetchAll() as $version) {
                $lignes[] = ['quand' => (string) $version['cree_le'],
                    'quoi' => 'Version v' . (int) $version['numero'] . ' archivée (' . (string) $version['nom_archive'] . ')'];
            }
            usort($lignes, static fn (array $a, array $b): int => strcmp($b['quand'], $a['quand']));
        } catch (Throwable) {
            return [];
        }

        return $lignes;
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
