<?php

declare(strict_types=1);

namespace Ged\Recherche;

use Ged\Config;
use PDO;

/**
 * Tient la table FULLTEXT à jour depuis le fonds. La synchronisation est bornée (`limite`) : un appel
 * court fait avancer l'index, le suivant reprend là où il faut — jamais de traitement long dans la requête.
 * Une ligne déjà signée identique n'est pas réécrite ; une ligne dont le fichier a disparu est retirée de
 * MA table (jamais une donnée d'un autre secteur).
 */
final class IndexPleinTexte
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SourceRecherche $source,
    ) {
    }

    public static function depuis(Config $config, PDO $pdo): self
    {
        return new self($pdo, new SourceRecherche($config->chemin('STORAGE_ROOT', 'tests/corpus/apercu/documents')));
    }

    /**
     * @param int|null $limite nombre maximal de documents (re)indexés par appel ; null = tout
     * @return int nombre de documents (re)posés
     */
    public function synchroniser(?int $limite = null): int
    {
        $documents = $this->source->documents();
        $signatures = $this->signatures();
        $identifiants = $this->identifiantsDocuments();
        $vus = [];
        $faits = 0;
        $complet = true;

        foreach ($documents as $document) {
            $cle = hash('sha256', $document->chemin);
            $vus[$cle] = true;
            $existante = $signatures[$cle] ?? null;
            if ($existante !== null && $existante['signature'] === $document->signature) {
                if ($existante['absent']) {
                    // Une reconstruction commence par marquer le cache absent. Réactiver est donc
                    // aussi un vrai travail, borné comme une indexation : sinon un premier appel
                    // relirait et réactiverait tout le fonds malgré sa limite.
                    if ($limite !== null && $faits >= $limite) {
                        $complet = false;
                        break;
                    }
                    $this->reactiver($cle);   // le fichier est revenu : la ligne ressort de l'absence
                    $faits++;
                }
                continue;
            }
            if ($limite !== null && $faits >= $limite) {
                $complet = false;   // le prochain appel reprendra : les lignes déjà à jour sont sautées
                break;
            }
            $this->poser($cle, $document, $identifiants[$document->chemin] ?? $document->documentId);
            $faits++;
        }

        if ($complet && $signatures !== []) {
            // Zéro suppression : une ligne dont le fichier a disparu est marquée absente, jamais retirée.
            $obsoletes = array_values(array_filter(
                array_diff(array_keys($signatures), array_keys($vus)),
                static fn (string $cle): bool => !$signatures[$cle]['absent'],
            ));
            $this->marquerAbsents($obsoletes);
        }

        return $faits;
    }

    /** Marque tout l'index absent : la prochaine synchronisation le reconstruit depuis le dossier. */
    public function vider(): void
    {
        $this->pdo->exec('UPDATE recherche_textes SET absent_le = NOW() WHERE absent_le IS NULL');
    }

    /** @return array<string, array{signature:string, absent:bool}> cle => état de la ligne */
    private function signatures(): array
    {
        $lignes = $this->pdo->query('SELECT cle, signature, absent_le FROM recherche_textes')->fetchAll();
        $etats = [];
        foreach ($lignes as $ligne) {
            $etats[(string) $ligne['cle']] = [
                'signature' => (string) $ligne['signature'],
                'absent' => $ligne['absent_le'] !== null,
            ];
        }

        return $etats;
    }

    /** @return array<string, int> chemin_relatif => id (vide si la table du Socle n'existe pas) */
    private function identifiantsDocuments(): array
    {
        try {
            return $this->pdo->query('SELECT chemin_relatif, id FROM documents')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\PDOException) {
            return [];
        }
    }

    private function poser(string $cle, DocumentFonds $document, ?int $documentId): void
    {
        // V2-12d : les notes font partie du même cache FULLTEXT que le document. Aucun second moteur,
        // et une note retirée sort au prochain passage de l'indexeur.
        $notes = '';
        if ($documentId !== null) {
            try {
                $s = $this->pdo->prepare('SELECT texte FROM document_notes WHERE document_id = ? AND retire_le IS NULL ORDER BY id');
                $s->execute([$documentId]);
                $notes = implode("\n", array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN)));
            } catch (\PDOException) {
                // Base d'une version antérieure : le schéma Socle sera posé avant le prochain job.
            }
        }
        $sql = 'INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, date_document, montant, contenu, signature, absent_le)'
            . ' VALUES (:cle, :chemin, :document_id, :titre, :type, :emetteur, :date_document, :montant, :contenu, :signature, NULL)'
            . ' ON DUPLICATE KEY UPDATE chemin = VALUES(chemin), document_id = VALUES(document_id), titre = VALUES(titre),'
            . ' type = VALUES(type), emetteur = VALUES(emetteur), date_document = VALUES(date_document),'
            . ' montant = VALUES(montant), contenu = VALUES(contenu), signature = VALUES(signature), absent_le = NULL';
        $this->pdo->prepare($sql)->execute([
            'cle' => $cle,
            'chemin' => $document->chemin,
            'document_id' => $documentId,
            'titre' => mb_substr($document->titre, 0, 200),
            'type' => $document->type,
            'emetteur' => $document->emetteur,
            'date_document' => $document->date,
            'montant' => $document->montant,
            'contenu' => trim($document->texte . "\n" . $notes),
            'signature' => $document->signature,
        ]);
    }

    /** @param list<string> $cles */
    private function marquerAbsents(array $cles): void
    {
        foreach (array_chunk($cles, 500) as $lot) {
            $marques = implode(', ', array_fill(0, count($lot), '?'));
            $this->pdo->prepare("UPDATE recherche_textes SET absent_le = NOW() WHERE cle IN ($marques)")->execute($lot);
        }
    }

    private function reactiver(string $cle): void
    {
        $this->pdo->prepare('UPDATE recherche_textes SET absent_le = NULL WHERE cle = ?')->execute([$cle]);
    }
}
