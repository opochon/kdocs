<?php

declare(strict_types=1);

namespace Ged\Archivage;

/**
 * Rétention légale : une durée par type de document (CO art. 958f, dix ans par défaut), une échéance
 * calculée à partir de la date du document, une liste « à échéance ». AUCUN effacement automatique :
 * la fin de rétention propose, un humain décide de lever le scellement, et le journal le trace.
 *
 * La table des durées est modifiable et vit dans le dossier (`<fonds>/.archivage/durees.json`) : elle suit
 * le fonds, elle est reconstruisible, et elle ne dépend d'aucun schéma de base.
 */
final class Retention
{
    /** Durées par défaut (années) : pièces comptables et courriers usuels, prudent à dix ans. */
    public const DUREES_PAR_DEFAUT = [
        'facture' => 10,
        'note de credit' => 10,
        'recu' => 10,
        'contrat' => 10,
        'courrier' => 10,
        'bulletin de salaire' => 10,
        'dossier rh' => 10,
    ];

    private const DOSSIER = '.archivage';

    public function __construct(private readonly string $racineFonds, private readonly Horloge $horloge)
    {
    }

    /**
     * La table des durées effective : les valeurs du dossier par-dessus les valeurs livrées.
     *
     * @return array<string, int>
     */
    public function durees(): array
    {
        $fichier = $this->racineFonds . '/' . self::DOSSIER . '/durees.json';
        $perso = is_file($fichier) ? (json_decode((string) file_get_contents($fichier), true) ?: []) : [];

        return array_merge(self::DUREES_PAR_DEFAUT, array_map('intval', $perso));
    }

    /**
     * Modifie la table des durées (acte d'administration tracé par le journal).
     *
     * @param array<string, int> $durees
     * @return array<string, int>
     */
    public function enregistrerDurees(array $durees): array
    {
        $table = array_merge($this->durees(), array_map('intval', $durees));
        $dossier = $this->racineFonds . '/' . self::DOSSIER;
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw ErreurScellement::scellement("impossible de créer $dossier");
        }
        file_put_contents($dossier . '/durees.json', json_encode($table, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

        return $table;
    }

    /** Durée de rétention (années) pour un type ; inconnu = durée par défaut (jamais zéro). */
    public function annees(?string $type): int
    {
        $cle = $this->normaliser($type);
        $durees = $this->durees();

        return max(1, $durees[$cle] ?? self::DUREES_PAR_DEFAUT['facture']);
    }

    /**
     * Date de départ de la rétention : la date du document si elle est propre, sinon l'instant du scellement.
     *
     * @param array<string, mixed> $meta
     */
    public function reference(array $meta, ?\DateTimeImmutable $scelleLe = null): string
    {
        $date = $meta['date'] ?? null;
        if (is_string($date) && preg_match('#^\d{4}-\d{2}-\d{2}$#', $date) === 1) {
            return $date;
        }

        return ($scelleLe ?? $this->horloge->maintenant())->format('Y-m-d');
    }

    /** Échéance = date de référence + durée du type. */
    public function echeance(string $reference, ?string $type): string
    {
        $depart = new \DateTimeImmutable($reference);

        return $depart->add(new \DateInterval('P' . $this->annees($type) . 'Y'))->format('Y-m-d');
    }

    /**
     * Les scellements actifs dont l'échéance est atteinte (aujourd'hui inclus), sans rien effacer.
     *
     * @return list<array<string, mixed>>
     */
    public function aEcheance(int $dansJours = 0): array
    {
        $aujourdhui = $this->horloge->maintenant()->modify('+' . max(0, $dansJours) . ' days')->format('Y-m-d');
        $lignes = [];
        foreach (Scellement::parcourir($this->racineFonds) as $fichier) {
            $scellement = Scellement::decoder((string) file_get_contents($fichier));
            if (!Scellement::estActif($scellement)) {
                continue;
            }
            $echeance = (string) ($scellement['echeance'] ?? '');
            if ($echeance !== '' && $echeance <= $aujourdhui) {
                $lignes[] = [
                    'document_id' => (int) ($scellement['document_id'] ?? 0),
                    'chemin_relatif' => (string) ($scellement['chemin_relatif'] ?? ''),
                    'type' => $scellement['type'] ?? null,
                    'echeance' => $echeance,
                    'justificatif' => $fichier,
                ];
            }
        }
        usort($lignes, static fn (array $a, array $b): int => [$a['echeance'], $a['chemin_relatif']] <=> [$b['echeance'], $b['chemin_relatif']]);

        return $lignes;
    }

    /**
     * Lève le scellement sur décision humaine : le justificatif est conservé (drapeau `leve_le`), jamais
     * supprimé, et l'acte est journalisé. Le document redevient alors mutable.
     *
     * @return array<string, mixed> le justificatif mis à jour
     */
    public function lever(string $cheminJson, string $auteur): array
    {
        if (!is_file($cheminJson)) {
            throw ErreurScellement::scellement("justificatif de scellement introuvable : $cheminJson");
        }
        $scellement = Scellement::decoder((string) file_get_contents($cheminJson));
        if ($scellement === null) {
            throw ErreurScellement::scellement("justificatif de scellement illisible : $cheminJson");
        }
        if (!Scellement::estActif($scellement)) {
            return $scellement;   // déjà levé : idempotent
        }
        $scellement['leve_le'] = $this->horloge->maintenant()->format(\DateTimeInterface::ATOM);
        $scellement['leve_par'] = $auteur;
        file_put_contents($cheminJson, json_encode($scellement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        $this->journaliser([
            'action' => 'archivage.levee',
            'auteur' => $auteur,
            'document_id' => (int) ($scellement['document_id'] ?? 0),
            'chemin_relatif' => (string) ($scellement['chemin_relatif'] ?? ''),
            'echeance' => (string) ($scellement['echeance'] ?? ''),
            'horodatage' => $this->horloge->maintenant()->format(\DateTimeInterface::ATOM),
        ]);

        return $scellement;
    }

    /**
     * Journal du secteur, en ajout seul, dans le dossier (`<fonds>/.archivage/journal.ndjson`).
     *
     * @param array<string, mixed> $entree
     */
    public function journaliser(array $entree): void
    {
        $dossier = $this->racineFonds . '/' . self::DOSSIER;
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw ErreurScellement::scellement("impossible de créer $dossier");
        }
        file_put_contents($dossier . '/journal.ndjson',
            json_encode($entree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function normaliser(?string $type): string
    {
        $cle = mb_strtolower(trim((string) $type));

        return str_replace('_', ' ', $cle);
    }
}
