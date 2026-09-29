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
            if (($signatures[$cle] ?? null) === $document->signature) {
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
            $this->supprimer(array_values(array_diff(array_keys($signatures), array_keys($vus))));
        }

        return $faits;
    }

    /** Vide MA table (commande de reconstruction hors requête). */
    public function vider(): void
    {
        $this->pdo->exec('DELETE FROM recherche_textes');
    }

    /** @return array<string, string> cle => signature */
    private function signatures(): array
    {
        return $this->pdo->query('SELECT cle, signature FROM recherche_textes')->fetchAll(PDO::FETCH_KEY_PAIR);
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
        $sql = 'INSERT INTO recherche_textes (cle, chemin, document_id, titre, type, emetteur, date_document, montant, contenu, signature)'
            . ' VALUES (:cle, :chemin, :document_id, :titre, :type, :emetteur, :date_document, :montant, :contenu, :signature)'
            . ' ON DUPLICATE KEY UPDATE chemin = VALUES(chemin), document_id = VALUES(document_id), titre = VALUES(titre),'
            . ' type = VALUES(type), emetteur = VALUES(emetteur), date_document = VALUES(date_document),'
            . ' montant = VALUES(montant), contenu = VALUES(contenu), signature = VALUES(signature)';
        $this->pdo->prepare($sql)->execute([
            'cle' => $cle,
            'chemin' => $document->chemin,
            'document_id' => $documentId,
            'titre' => mb_substr($document->titre, 0, 200),
            'type' => $document->type,
            'emetteur' => $document->emetteur,
            'date_document' => $document->date,
            'montant' => $document->montant,
            'contenu' => $document->texte,
            'signature' => $document->signature,
        ]);
    }

    /** @param list<string> $cles */
    private function supprimer(array $cles): void
    {
        foreach (array_chunk($cles, 500) as $lot) {
            $marques = implode(', ', array_fill(0, count($lot), '?'));
            $this->pdo->prepare("DELETE FROM recherche_textes WHERE cle IN ($marques)")->execute($lot);
        }
    }
}
