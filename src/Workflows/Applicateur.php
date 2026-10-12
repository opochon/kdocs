<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Contrats\Depot;
use PDO;

/**
 * Applique un workflow Paperless à un document : il retient les déclencheurs du type demandé qui
 * correspondent, exécute les actions dans l'ordre, écrit la fiche (via le contrat `Depot`, jamais
 * directement en base) et trace chaque exécution (`Executions`, donc `WorkflowRun`).
 *
 * Une action en erreur est NOMMÉE et journalisée : elle ne bloque ni les actions suivantes, ni le
 * document. Le moteur ne lève jamais vers l'appelant.
 */
final class Applicateur
{
    private readonly Horloge $horloge;
    private readonly Executions $executions;
    private readonly Journal $journal;
    private readonly PortCourriel $courriel;
    private readonly PortWebhook $webhook;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Depot $depot = null,
        ?Horloge $horloge = null,
        ?PortCourriel $courriel = null,
        ?PortWebhook $webhook = null,
    ) {
        $this->horloge = $horloge ?? new HorlogeSysteme();
        $this->executions = new Executions($pdo);
        $this->journal = new Journal($pdo);
        $this->courriel = $courriel ?? new CourrielJournalise($this->journal);
        $this->webhook = $webhook ?? new WebhookJournalise($this->journal);
    }

    /**
     * @param array<string, mixed> $workflow
     * @param array<string, mixed> $fiche
     * @return array{declenche:bool, actions:list<array<string,mixed>>, ecritures:int, erreurs:list<array{action:int, cause:string}>}
     */
    public function appliquer(
        array $workflow,
        int $type,
        array $fiche,
        string $auteur = 'workflow',
        ?int $compte = null,
        bool $administrateur = false,
    ): array {
        $declencheurs = array_values(array_filter(
            (array) ($workflow['triggers'] ?? []),
            static fn (mixed $trigger): bool => is_array($trigger) && (int) ($trigger['type'] ?? 0) === $type,
        ));

        $retenu = false;
        foreach ($declencheurs as $declencheur) {
            if ($type === Declencheur::PLANIFIE) {
                $workflowId = (int) ($workflow['id'] ?? 0);
                $derniere = $workflowId > 0 && (int) ($fiche['id'] ?? 0) > 0
                    ? $this->executions->derniere($workflowId, (int) $fiche['id'])
                    : null;
                $retenu = Declencheur::planifie($declencheur, $fiche, $this->horloge->maintenant(), $derniere);
            } else {
                $retenu = Declencheur::correspondType($type, $declencheur, $fiche);
            }
            if ($retenu) {
                break;
            }
        }

        if (!$retenu) {
            return ['declenche' => false, 'actions' => [], 'ecritures' => 0, 'erreurs' => []];
        }

        $workflowId = (int) ($workflow['id'] ?? 0);
        $documentId = (int) ($fiche['id'] ?? 0);
        if ($workflowId > 0 && $documentId > 0) {
            $this->executions->enregistrer($workflowId, $type, $documentId, $this->horloge->maintenant(), $auteur);
        }

        $actions = (array) ($workflow['actions'] ?? []);
        usort($actions, static fn (mixed $a, mixed $b): int => (int) ((array) $a)['order'] <=> (int) ((array) $b)['order']);

        $resultats = [];
        $erreurs = [];
        $modifie = $fiche;
        $ecritures = 0;
        foreach ($actions as $index => $action) {
            if (!is_array($action)) {
                continue;
            }
            try {
                $application = Action::appliquer($action, $modifie, $this->courriel, $this->webhook);
                $modifie = $application['document'];
                $resultats[] = ['type' => (int) ($action['type'] ?? 0), 'effets' => $application['effets'],
                    'courriel' => $application['courriel'], 'webhook' => $application['webhook']];
                if ((int) ($action['type'] ?? 0) === Action::SUPPRESSION) {
                    $this->mettreALaCorbeille($documentId, $auteur);
                } elseif ($this->depot !== null && $documentId > 0) {
                    $this->depot->mettreAJour($documentId, self::metaDe($modifie), $auteur, $compte, $administrateur);
                }
                $ecritures++;
            } catch (\Throwable $e) {
                $erreurs[] = ['action' => (int) ($action['type'] ?? 0), 'cause' => $e->getMessage()];
                $this->journal->ajouter([
                    'document_id' => $documentId, 'definition_id' => $workflowId > 0 ? $workflowId : null,
                    'genre' => 'erreur', 'auteur' => $auteur, 'horodatage' => $this->horloge->maintenant(),
                    'details' => ['workflow' => (string) ($workflow['nom'] ?? ''), 'action' => (int) ($action['type'] ?? 0),
                        'cause' => $e->getMessage()],
                ]);
            }
        }

        return ['declenche' => true, 'actions' => $resultats, 'ecritures' => $ecritures, 'erreurs' => $erreurs];
    }

    /**
     * Normalise une fiche venue de `Depot::fiche()` ou d'un `.meta.json` : mêmes clés pour l'essai à blanc
     * et l'exécution réelle (le fossé entre les deux est nul).
     *
     * @param array<string, mixed> $ligne
     * @return array<string, mixed>
     */
    public static function fiche(array $ligne): array
    {
        $meta = is_array($ligne['meta'] ?? null) ? $ligne['meta'] : [];
        $chemin = (string) ($ligne['chemin_relatif'] ?? ($meta['chemin_relatif'] ?? ''));
        // Le dossier de stockage est un attribut EXPLICITE (`.meta.json`) : le rangement dérivé n'en est pas un.
        $dossier = isset($meta['dossier']) && is_string($meta['dossier']) ? $meta['dossier'] : null;

        return [
            'id' => (int) ($ligne['id'] ?? 0),
            'titre' => (string) ($ligne['titre'] ?? ($meta['titre'] ?? '')),
            'type' => isset($ligne['type']) && $ligne['type'] !== '' ? (string) $ligne['type'] : null,
            'emetteur' => isset($ligne['emetteur']) && $ligne['emetteur'] !== '' ? (string) $ligne['emetteur'] : null,
            'dossier' => $dossier !== '' ? $dossier : null,
            'proprietaire' => isset($meta['proprietaire']) && $meta['proprietaire'] !== '' ? (string) $meta['proprietaire'] : null,
            'etiquettes' => array_values(array_map('strval', (array) ($ligne['etiquettes'] ?? ($meta['etiquettes'] ?? [])))),
            'nom_fichier' => (string) ($meta['nom_origine'] ?? basename($chemin)),
            'chemin' => $chemin,
            'contenu' => (string) ($meta['texte'] ?? ''),
            'source' => (int) ($meta['source_ingestion'] ?? 0),
            'mailrule' => isset($meta['mailrule']) ? (int) $meta['mailrule'] : null,
            'droits' => self::droits($meta),
            'champs' => is_array($meta['champs'] ?? null) ? $meta['champs'] : [],
            'dates' => [
                'added' => (string) ($ligne['cree_le'] ?? ''),
                'created' => (string) ($meta['date_document'] ?? ($ligne['date_document'] ?? '')),
                'modified' => (string) ($ligne['maj_le'] ?? ''),
            ],
        ];
    }

    /**
     * Attributs d'une fiche qui se persistent dans le `.meta.json` (le dossier fait foi) et l'index.
     *
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public static function metaDe(array $fiche): array
    {
        return [
            'titre' => (string) ($fiche['titre'] ?? ''),
            'type' => $fiche['type'] ?? null,
            'emetteur' => $fiche['emetteur'] ?? null,
            'etiquettes' => array_values(array_map('strval', (array) ($fiche['etiquettes'] ?? []))),
            'dossier' => $fiche['dossier'] ?? null,
            'proprietaire' => $fiche['proprietaire'] ?? null,
            'droits' => self::droits(['droits' => $fiche['droits'] ?? []]),
            'champs' => (array) ($fiche['champs'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, array<string, list<string>>>
     */
    private static function droits(array $meta): array
    {
        $droits = is_array($meta['droits'] ?? null) ? $meta['droits'] : [];

        return [
            'lecture' => [
                'utilisateurs' => array_values(array_map('strval', (array) ($droits['lecture']['utilisateurs'] ?? []))),
                'groupes' => array_values(array_map('strval', (array) ($droits['lecture']['groupes'] ?? []))),
            ],
            'modification' => [
                'utilisateurs' => array_values(array_map('strval', (array) ($droits['modification']['utilisateurs'] ?? []))),
                'groupes' => array_values(array_map('strval', (array) ($droits['modification']['groupes'] ?? []))),
            ],
        ];
    }

    private function mettreALaCorbeille(int $documentId, string $auteur): void
    {
        if ($documentId < 1) {
            return;
        }
        if ($this->depot instanceof \Ged\Socle\DepotFichiers) {
            $this->depot->miseALaCorbeille($documentId, $auteur);

            return;
        }
        throw new ErreurWorkflow('action supprimer : le dépôt branché ne sait pas mettre à la corbeille');
    }
}
