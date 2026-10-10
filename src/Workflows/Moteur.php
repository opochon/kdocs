<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Contrats\Depot;
use PDO;

/**
 * LE moteur du secteur : il interprète les deux niveaux ensemble.
 *  - les RÈGLES (déclencheur + conditions + actions) réagissent à un événement ;
 *  - les CIRCUITS à états font traverser un document, chaque transition avec son droit et sa trace.
 *
 * `planifier()` est PUR : il ne fait que lire et rend un plan de décisions.
 * `essayer()` rend ce plan sans rien écrire (essai à blanc).
 * `executer()` applique exactement le même plan (écritures réelles).
 * L'horloge est injectable ; le dépôt (Socle) n'est utilisé que pour les actions qui touchent l'index.
 */
final class Moteur
{
    /** Champs connus du `.meta.json` qu'une action « poser » peut modifier (publics : le designer les propose). */
    public const CHAMPS_POSABLES = ['titre', 'type', 'emetteur', 'date', 'montant', 'statut'];

    private readonly Horloge $horloge;
    private readonly Regles $regles;
    private readonly Circuits $circuits;
    private readonly Journal $journal;

    public function __construct(
        PDO $pdo,
        private readonly ?Depot $depot = null,
        ?Horloge $horloge = null,
    ) {
        $this->horloge = $horloge ?? new HorlogeSysteme();
        $this->regles = new Regles($pdo);
        $this->circuits = new Circuits($pdo);
        $this->journal = new Journal($pdo);
    }

    public function horloge(): Horloge
    {
        return $this->horloge;
    }

    /**
     * ESSAI À BLANC : rend le plan sans rien écrire. `$avecEnVeille` inclut les règles en veille pour
     * montrer ce qui se passerait si on les activait (usage de l'écran, avant activation).
     *
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public function essayer(string $evenement, array $fiche, bool $avecEnVeille = false): array
    {
        return $this->planifier($evenement, $fiche, $avecEnVeille);
    }

    /**
     * EXÉCUTION RÉELLE : même plan que l'essai à blanc, puis application. Rend le plan et le nombre d'écritures.
     * Le compte de l'appelant est transmis au service : une action « poser » qui change le rangement
     * exige le niveau `deposer` sur le dossier d'ARRIVÉE (F10) ; compte `null` = moteur système.
     *
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public function executer(string $evenement, array $fiche, string $auteur = 'systeme', ?int $compte = null, bool $administrateur = false): array
    {
        $plan = $this->planifier($evenement, $fiche, false);
        $application = $this->appliquer($plan, $fiche, $auteur, $compte, $administrateur);
        $plan['erreurs'] = array_merge($plan['erreurs'], $application['erreurs']);
        $plan['ecritures'] = $application['ecritures'];

        return $plan;
    }

    /**
     * Le plan pur : quelles règles du déclencheur sont retenues, quelles actions en découlent, quelles erreurs.
     *
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    public function planifier(string $evenement, array $fiche, bool $avecEnVeille = false): array
    {
        $candidats = $avecEnVeille
            ? array_values(array_filter($this->regles->toutes(), static fn (array $r): bool => $r['declencheur'] === $evenement))
            : $this->regles->applicables($evenement);

        $contexte = $this->contexte($fiche, $evenement);
        $regles = [];
        $etapes = [];
        $erreurs = [];

        foreach ($candidats as $regle) {
            $retenue = Conditions::satisfaites($regle['conditions'], $contexte);
            $regles[] = ['id' => $regle['id'], 'nom' => $regle['nom'], 'actif' => $regle['actif'], 'retenue' => $retenue];
            if (!$retenue) {
                continue;
            }
            foreach ($regle['actions'] as $action) {
                if (!is_array($action)) {
                    $erreurs[] = $this->erreur($regle, 'action mal formée');
                    continue;
                }
                try {
                    $etapes[] = $this->planifierAction($regle, $action, $fiche, $contexte);
                } catch (ErreurWorkflow $e) {
                    $erreurs[] = $this->erreur($regle, $e->getMessage());
                }
            }
        }

        return [
            'declencheur' => $evenement,
            'document_id' => (int) ($fiche['id'] ?? 0),
            'regles' => $regles,
            'etapes' => $etapes,
            'erreurs' => $erreurs,
        ];
    }

    /**
     * Applique une transition à la main (action d'un utilisateur) : le droit de la transition est vérifié,
     * la transition est tracée (qui, quand, depuis quel état), et un refus est journalisé.
     *
     * @param list<string> $permissions permissions de la session
     * @return array<string, mixed>
     */
    public function transitionner(int $documentId, string $vers, string $auteur, array $permissions): array
    {
        foreach ($this->circuits->instancesDocument($documentId) as $instance) {
            $circuit = $instance['circuit'];
            $transition = $this->circuits->transition((int) $circuit['id'], (string) $instance['etat'], $vers);
            if ($transition === null) {
                continue;
            }
            $permission = (string) ($transition['permission'] ?? '');
            if ($permission !== '' && !in_array('*', $permissions, true) && !in_array($permission, $permissions, true)) {
                $this->journal->ajouter([
                    'document_id' => $documentId, 'definition_id' => $circuit['id'], 'instance_id' => $instance['id'],
                    'genre' => 'refus', 'de_etat' => $instance['etat'], 'vers_etat' => $vers, 'auteur' => $auteur,
                    'horodatage' => $this->horloge->maintenant(),
                    'details' => ['circuit' => $circuit['nom'], 'permission' => $permission],
                ]);
                throw new ErreurWorkflow(sprintf('transition refusée : droit « %s » requis pour « %s → %s » sur le document %d',
                    $permission, $instance['etat'], $vers, $documentId));
            }

            $this->circuits->changerEtat((int) $instance['id'], $vers);
            $this->journal->ajouter([
                'document_id' => $documentId, 'definition_id' => $circuit['id'], 'instance_id' => $instance['id'],
                'genre' => 'transition', 'de_etat' => $instance['etat'], 'vers_etat' => $vers, 'auteur' => $auteur,
                'horodatage' => $this->horloge->maintenant(),
                'details' => ['circuit' => $circuit['nom'], 'permission' => $permission],
            ]);

            return ['document_id' => $documentId, 'circuit' => $circuit['nom'], 'de_etat' => $instance['etat'],
                'vers_etat' => $vers, 'auteur' => $auteur];
        }

        throw new ErreurWorkflow("transition refusée : aucune transition vers « $vers » pour le document $documentId");
    }

    // ------------------------------------------------------------------ planification

    /**
     * @param array<string, mixed> $regle
     * @param array<string, mixed> $action
     * @param array<string, mixed> $fiche
     * @param array<string, mixed> $contexte
     * @return array<string, mixed>
     */
    private function planifierAction(array $regle, array $action, array $fiche, array $contexte): array
    {
        $type = (string) ($action['type'] ?? '');
        $base = ['regle_id' => $regle['id'], 'regle' => $regle['nom']];

        return match ($type) {
            'poser' => $this->planifierPose($base, $action),
            'etiqueter' => $this->planifierEtiquette($base, $action),
            'demander_validation', 'assigner' => $this->planifierAssignation($base, $action, $fiche),
            'notifier' => $base + [
                'type' => 'notifier',
                'destinataire' => trim((string) ($action['destinataire'] ?? 'responsable')),
                'message' => $this->substituer((string) ($action['message'] ?? ''), $fiche, $contexte),
            ],
            'appeler_jonction' => $base + [
                'type' => 'jonction',
                'cible' => trim((string) ($action['cible'] ?? '')),
                'charge' => (array) ($action['charge'] ?? []),
            ],
            default => throw new ErreurWorkflow("action inconnue : « $type »"),
        };
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function planifierPose(array $base, array $action): array
    {
        $champ = (string) ($action['champ'] ?? '');
        if (!in_array($champ, self::CHAMPS_POSABLES, true)) {
            throw new ErreurWorkflow("action « poser » : champ inconnu « $champ »");
        }

        return $base + ['type' => 'poser', 'champ' => $champ, 'valeur' => $action['valeur'] ?? null];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function planifierEtiquette(array $base, array $action): array
    {
        $etiquette = trim((string) ($action['etiquette'] ?? ($action['valeur'] ?? '')));
        if ($etiquette === '') {
            throw new ErreurWorkflow('action « étiqueter » : étiquette vide');
        }

        return $base + ['type' => 'etiqueter', 'etiquette' => $etiquette];
    }

    /**
     * `demander_validation` et `assigner` partagent la même mécanique : trouver le circuit, démarrer
     * l'instance si elle n'existe pas (à l'état demandé ou initial), sinon réassigner.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $action
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    private function planifierAssignation(array $base, array $action, array $fiche): array
    {
        $reference = trim((string) ($action['circuit'] ?? ''));
        $circuit = $this->circuits->parGabarit($reference) ?? $this->circuits->parNom($reference);
        if ($circuit === null) {
            throw new ErreurWorkflow("circuit inconnu : « $reference »");
        }
        $role = trim((string) ($action['assigne'] ?? ($action['role'] ?? '')));
        $documentId = (int) ($fiche['id'] ?? 0);
        $instance = $documentId > 0 ? $this->circuits->instance((int) $circuit['id'], $documentId) : null;

        if ($instance === null) {
            $etat = trim((string) ($action['vers'] ?? ''));
            if ($etat === '' || !$this->circuits->aEtat($circuit, $etat)) {
                $etat = (string) Circuits::etatInitial($circuit);
            }

            return $base + ['type' => 'demarrer', 'circuit_id' => $circuit['id'], 'circuit' => $circuit['nom'],
                'etat' => $etat, 'assigne' => $role];
        }

        return $base + ['type' => 'assigner', 'circuit_id' => $circuit['id'], 'circuit' => $circuit['nom'],
            'instance_id' => $instance['id'], 'assigne' => $role];
    }

    /**
     * @param array<string, mixed> $fiche
     * @param array<string, mixed> $contexte
     */
    private function substituer(string $message, array $fiche, array $contexte): string
    {
        $montant = $fiche['montant'] ?? null;

        return strtr($message, [
            '{titre}' => (string) ($fiche['titre'] ?? ''),
            '{emetteur}' => (string) ($fiche['emetteur'] ?? ''),
            '{date}' => (string) ($fiche['date'] ?? ''),
            '{montant}' => is_numeric($montant) ? number_format((float) $montant, 2, '.', "'") : '',
            '{echeance}' => (string) ($fiche['echeance'] ?? ''),
            '{jours}' => $contexte['delai_echeance_jours'] !== null ? (string) $contexte['delai_echeance_jours'] : '',
        ]);
    }

    /**
     * @param array<string, mixed> $fiche
     * @return array<string, mixed>
     */
    private function contexte(array $fiche, string $evenement): array
    {
        return [
            'type' => $fiche['type'] ?? null,
            'emetteur' => $fiche['emetteur'] ?? null,
            'titre' => $fiche['titre'] ?? null,
            'montant' => $fiche['montant'] ?? null,
            'date' => $fiche['date'] ?? null,
            'etiquettes' => array_values((array) ($fiche['etiquettes'] ?? [])),
            'etiquette' => array_values((array) ($fiche['etiquettes'] ?? [])),
            'texte' => trim((string) ($fiche['titre'] ?? '') . ' ' . (string) ($fiche['texte'] ?? '')),
            'delai_echeance_jours' => $this->delaiEcheance($fiche),
            'declencheur' => $evenement,
        ];
    }

    /** @param array<string, mixed> $fiche */
    private function delaiEcheance(array $fiche): ?int
    {
        $echeance = $fiche['echeance'] ?? (is_array($fiche['meta'] ?? null) ? ($fiche['meta']['echeance'] ?? null) : null);
        if (!is_string($echeance) || preg_match('#^\d{4}-\d{2}-\d{2}#', $echeance) !== 1) {
            return null;
        }
        try {
            // Le délai se compte en JOURS de calendrier : on ramène l'horloge et l'échéance à minuit,
            // sinon l'heure courante ferait perdre un jour (J-90 annoncé à 89).
            $cible = (new \DateTimeImmutable(substr($echeance, 0, 10)))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
        $maintenant = $this->horloge->maintenant()->setTime(0, 0);

        return (int) $maintenant->diff($cible)->format('%r%a');
    }

    // ------------------------------------------------------------------ application

    /**
     * Applique un plan. Chaque étape est isolée : une action qui échoue est journalisée, les suivantes passent.
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $fiche
     * @return array{ecritures:int, erreurs:list<array{regle_id:?int, regle:string, cause:string}>}
     */
    private function appliquer(array $plan, array $fiche, string $auteur, ?int $compte = null, bool $administrateur = false): array
    {
        $ecritures = 0;
        $erreurs = [];

        foreach ($plan['erreurs'] as $erreur) {
            $this->journaliser(['document_id' => $plan['document_id'], 'genre' => 'erreur', 'auteur' => $auteur,
                'details' => ['regle' => $erreur['regle'], 'cause' => $erreur['cause']]]);
            $ecritures++;
        }

        foreach ($plan['etapes'] as $etape) {
            try {
                $this->appliquerEtape($etape, $fiche, $auteur, $compte, $administrateur);
                $ecritures++;
            } catch (\Throwable $e) {
                $erreurs[] = ['regle_id' => $etape['regle_id'] ?? null, 'regle' => (string) ($etape['regle'] ?? '(moteur)'),
                    'cause' => $e->getMessage()];
                $this->journaliser(['document_id' => $plan['document_id'], 'genre' => 'erreur', 'auteur' => $auteur,
                    'details' => ['regle' => $etape['regle'] ?? '(moteur)', 'action' => $etape['type'] ?? '?',
                        'cause' => $e->getMessage()]]);
                $ecritures++;
            }
        }

        return ['ecritures' => $ecritures, 'erreurs' => $erreurs];
    }

    /**
     * @param array<string, mixed> $etape
     * @param array<string, mixed> $fiche
     */
    private function appliquerEtape(array $etape, array $fiche, string $auteur, ?int $compte = null, bool $administrateur = false): void
    {
        $documentId = (int) ($fiche['id'] ?? 0);
        $details = ['regle_id' => $etape['regle_id'] ?? null, 'regle' => $etape['regle'] ?? null];

        switch ($etape['type']) {
            case 'poser':
                $this->depotObligatoire()->mettreAJour($documentId, [(string) $etape['champ'] => $etape['valeur']], $auteur, $compte, $administrateur);
                $this->journaliser(['document_id' => $documentId, 'genre' => 'regle',
                    'details' => $details + ['action' => 'poser', 'champ' => $etape['champ'], 'valeur' => $etape['valeur']]]);
                break;

            case 'etiqueter':
                $etiquettes = array_values((array) ($fiche['etiquettes'] ?? []));
                if (!in_array($etape['etiquette'], $etiquettes, true)) {
                    $etiquettes[] = (string) $etape['etiquette'];
                    $this->depotObligatoire()->mettreAJour($documentId, ['etiquettes' => $etiquettes], $auteur, $compte, $administrateur);
                }
                $this->journaliser(['document_id' => $documentId, 'genre' => 'regle',
                    'details' => $details + ['action' => 'etiqueter', 'etiquette' => $etape['etiquette']]]);
                break;

            case 'demarrer':
                $instanceId = $this->circuits->demarrer((int) $etape['circuit_id'], $documentId, (string) $etape['etat'],
                    $etape['assigne'] !== '' ? (string) $etape['assigne'] : null);
                $this->journaliser(['document_id' => $documentId, 'definition_id' => (int) $etape['circuit_id'],
                    'instance_id' => $instanceId, 'genre' => 'demarrage', 'vers_etat' => (string) $etape['etat'],
                    'auteur' => $auteur, 'details' => $details + ['circuit' => $etape['circuit'], 'assigne' => $etape['assigne']]]);
                break;

            case 'assigner':
                $this->circuits->assigner((int) $etape['instance_id'], (string) $etape['assigne']);
                $this->journaliser(['document_id' => $documentId, 'definition_id' => (int) $etape['circuit_id'],
                    'instance_id' => (int) $etape['instance_id'], 'genre' => 'assignation', 'auteur' => $auteur,
                    'details' => $details + ['circuit' => $etape['circuit'], 'assigne' => $etape['assigne']]]);
                break;

            case 'notifier':
                $this->journaliser(['document_id' => $documentId, 'genre' => 'notification', 'auteur' => $auteur,
                    'details' => $details + ['destinataire' => $etape['destinataire'], 'message' => $etape['message']]]);
                break;

            case 'jonction':
                $this->journaliser(['document_id' => $documentId, 'genre' => 'jonction', 'auteur' => $auteur,
                    'details' => $details + ['cible' => $etape['cible'], 'charge' => $etape['charge']]]);
                break;

            default:
                throw new ErreurWorkflow('étape inconnue : « ' . (string) $etape['type'] . ' »');
        }
    }

    /** @param array<string, mixed> $entree */
    private function journaliser(array $entree): void
    {
        $this->journal->ajouter($entree + ['horodatage' => $this->horloge->maintenant()]);
    }

    private function depotObligatoire(): Depot
    {
        if ($this->depot === null) {
            throw new ErreurWorkflow('dépôt indisponible : le moteur ne peut pas modifier l’index documentaire');
        }

        return $this->depot;
    }

    /**
     * @param array<string, mixed> $regle
     * @return array{regle_id:int,regle:string,cause:string}
     */
    private function erreur(array $regle, string $cause): array
    {
        return ['regle_id' => (int) $regle['id'], 'regle' => (string) $regle['nom'], 'cause' => $cause];
    }
}
