<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Adaptateur de l'écran /workflows : la LISTE des circuits et des règles, et l'ÉDITEUR graphique repris
 * du designer de GEDv1. L'essai à blanc vit dans l'éditeur, sur un vrai document du fonds, et n'écrit rien.
 * Si le schéma n'est pas posé, l'écran le dit — jamais une erreur muette.
 */
final class Ecran
{
    public const DECLENCHEURS = [
        'document_depose' => 'Document déposé',
        'document_modifie' => 'Document mis à jour',
        'echeance' => 'Échéance planifiée',
    ];

    private readonly Horloge $horloge;

    public function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo = null,
        ?Horloge $horloge = null,
    ) {
        $this->horloge = $horloge ?? new HorlogeSysteme();
    }

    public static function depuisRacine(?string $racine = null): self
    {
        $config = new Config($racine ?? dirname(__DIR__, 2));

        return new self($config, Base::pdo($config));
    }

    /**
     * Données de la LISTE : circuits (avec leurs règles) et règles orphelines, gabarits, journal.
     *
     * @param array<string, mixed> $parametres query string de /workflows
     * @return array<string, mixed>
     */
    public function donnees(array $parametres): array
    {
        unset($parametres);   // l'écran de liste ne lit plus la query string (l'essai vit dans l'éditeur)
        $installe = true;
        $regles = $circuits = $journal = $gabarits = [];
        try {
            $regles = (new Regles($this->pdo))->toutes();
            $circuits = (new Circuits($this->pdo))->toutes();
            $journal = (new Journal($this->pdo))->recents(25);
            $gabarits = Gabarits::catalogue($this->pdo);
        } catch (\PDOException) {
            $installe = false;
        }

        $fonds = Fonds::depuis($this->config, $this->pdo);
        $documents = $this->documents($fonds);

        // Les circuits portent le nom de leurs règles (le gabarit ou le circuit_id les rattache).
        $editeur = new Editeur($this->pdo);
        foreach ($circuits as $i => $circuit) {
            $circuits[$i]['regles'] = $installe ? $editeur->reglesAssociees($circuit) : [];
        }

        return [
            'installe' => $installe,
            'regles' => $regles,
            'circuits' => $circuits,
            'gabarits' => $gabarits,
            'documents' => $documents,
            'journal' => array_map(fn (array $e): array => $this->entreeJournal($e), $journal),
            'declencheurs' => self::DECLENCHEURS,
            'a_porter' => Catalogue::aPorter(),
        ];
    }

    /**
     * Données de l'ÉDITEUR d'un circuit : graphe, catalogue, documents d'essai, essai à blanc éventuel.
     *
     * @param array<string, mixed> $parametres query string de l'éditeur (?essai=…&déclencheur=…)
     * @return array<string, mixed>|null null si le circuit n'existe pas
     */
    public function editeur(int $circuitId, array $parametres): ?array
    {
        $circuit = (new Circuits($this->pdo))->parId($circuitId);
        if ($circuit === null) {
            return null;
        }

        $regles = (new Editeur($this->pdo))->reglesAssociees($circuit);
        $graphe = AdaptateurGraphe::versGraphe($circuit, $regles);
        $essai = $this->essaiEditeur($parametres, $graphe, $regles);
        $fonds = Fonds::depuis($this->config, $this->pdo);

        $donneesJs = [
            'meta' => ['circuit_id' => $circuitId, 'nom' => $circuit['nom'], 'gabarit' => $circuit['gabarit'], 'actif' => $circuit['actif']],
            'graphe' => $graphe,
            'catalogue' => Catalogue::tout(),
            'champs_posables' => Moteur::CHAMPS_POSABLES,
            'declencheurs' => self::DECLENCHEURS,
            'essai' => $essai,
            'urls' => ['enregistrer' => '/workflows/circuits/' . $circuitId, 'essai' => '/workflows/essai', 'liste' => '/workflows'],
        ];

        return [
            'circuit' => ['id' => $circuitId, 'nom' => $circuit['nom'], 'gabarit' => $circuit['gabarit'],
                'actif' => $circuit['actif'], 'regles' => count($regles)],
            'graphe' => $graphe,
            'catalogue' => Catalogue::tout(),
            'documents' => $this->documents($fonds),
            'declencheurs' => self::DECLENCHEURS,
            'essai' => $essai,
            'donnees_json' => self::jsonSur($donneesJs),
        ];
    }

    /** Le catalogue seul, pour la route JSON (aucun nœud que le moteur n'exécute). @return array<string, mixed> */
    public function catalogue(): array
    {
        return ['catalogue' => Catalogue::tout(), 'a_porter' => Catalogue::aPorter(),
            'champs_posables' => Moteur::CHAMPS_POSABLES, 'declencheurs' => self::DECLENCHEURS];
    }

    /**
     * L'essai à blanc demandé par l'URL (`?essai=<id>&declencheur=<...>`) : un `Moteur` SANS dépôt, donc
     * aucune écriture possible, et le chemin suivi dans le graphe pour le surligner.
     *
     * @param array<string, mixed> $parametres
     * @param array<string, mixed> $graphe
     * @param list<array<string, mixed>> $regles
     * @return array<string, mixed>|null
     */
    private function essaiEditeur(array $parametres, array $graphe, array $regles): ?array
    {
        if ($this->pdo === null || !isset($parametres['essai'])) {
            return null;
        }
        $fiche = Fonds::depuis($this->config, $this->pdo)->parId((int) $parametres['essai']);
        if ($fiche === null) {
            return null;
        }
        $declencheur = (string) ($parametres['declencheur'] ?? 'document_depose');

        // Moteur sans dépôt : l'essai ne peut rien écrire, même par accident.
        $plan = (new Moteur($this->pdo, null, $this->horloge))->essayer($declencheur, $fiche, true);
        $chemin = [];
        try {
            $chemin = Editeur::chemin($graphe, $plan, array_map(static fn (array $r): int => (int) $r['id'], $regles));
        } catch (ErreurWorkflow) {
            $chemin = [];
        }

        return [
            'document' => ['id' => $fiche['id'], 'titre' => $fiche['titre']],
            'declencheur' => $declencheur,
            'regles' => $plan['regles'],
            'etapes' => array_map(fn (array $e): array => $this->etapeLisible($e), $plan['etapes']),
            'erreurs' => $plan['erreurs'],
            'chemin' => $chemin,
            'zero_ecriture' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(Fonds $fonds): array
    {
        return array_map(static fn (array $f): array => [
            'id' => $f['id'], 'titre' => $f['titre'], 'type' => $f['type'], 'emetteur' => $f['emetteur'],
            'montant' => $f['montant'], 'date' => $f['date'],
        ], $fonds->toutes());
    }

    /** JSON sûr à encastrer dans une balise script (les chevrons et guillemets sont échappés). */
    private static function jsonSur(array $donnees): string
    {
        return (string) json_encode($donnees,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** @param array<string, mixed> $entree @return array<string, mixed> */
    private function entreeJournal(array $entree): array
    {
        return [
            'id' => $entree['id'],
            'quand' => (string) $entree['horodatage'],
            'qui' => (string) $entree['auteur'],
            'genre' => (string) $entree['genre'],
            'document_id' => $entree['document_id'],
            'de' => $entree['de_etat'],
            'vers' => $entree['vers_etat'],
            'resume' => $this->resumeJournal($entree),
        ];
    }

    /** @param array<string, mixed> $entree */
    private function resumeJournal(array $entree): string
    {
        $details = (array) ($entree['details'] ?? []);

        return match ((string) $entree['genre']) {
            'notification' => (string) ($details['message'] ?? 'notification'),
            'erreur' => 'Erreur — ' . (string) ($details['cause'] ?? 'cause inconnue'),
            'demarrage' => 'Circuit « ' . (string) ($details['circuit'] ?? '?') . ' » → état ' . (string) $entree['vers_etat']
                . (isset($details['assigne']) && $details['assigne'] !== '' ? ' (assigné à ' . $details['assigne'] . ')' : ''),
            'transition' => 'Transition ' . (string) $entree['de_etat'] . ' → ' . (string) $entree['vers_etat']
                . ' (droit ' . (string) ($details['permission'] ?? '—') . ')',
            'refus' => 'Transition refusée ' . (string) $entree['de_etat'] . ' → ' . (string) $entree['vers_etat']
                . ' (droit ' . (string) ($details['permission'] ?? '—') . ' manquant)',
            'assignation' => 'Assigné à ' . (string) ($details['assigne'] ?? '?'),
            'jonction' => 'Jonction demandée : ' . (string) ($details['cible'] ?? '?'),
            'regle' => 'Règle « ' . (string) ($details['regle'] ?? '?') . ' » — ' . (string) ($details['action'] ?? ''),
            default => (string) $entree['genre'],
        };
    }

    /**
     * @param array<string, mixed> $etape
     * @return array<string, mixed>
     */
    private function etapeLisible(array $etape): array
    {
        $texte = match ((string) $etape['type']) {
            'poser' => 'Poser ' . (string) $etape['champ'] . ' = ' . (is_scalar($etape['valeur'] ?? null) ? (string) $etape['valeur'] : ''),
            'etiqueter' => 'Étiqueter « ' . (string) $etape['etiquette'] . ' »',
            'demarrer' => 'Démarrer le circuit « ' . (string) $etape['circuit'] . ' » → état ' . (string) $etape['etat']
                . ($etape['assigne'] !== '' ? ' (assigné à ' . (string) $etape['assigne'] . ')' : ''),
            'assigner' => 'Assigner le circuit « ' . (string) $etape['circuit'] . ' » à ' . (string) $etape['assigne'],
            'notifier' => 'Notifier ' . (string) $etape['destinataire'] . ' : ' . (string) $etape['message'],
            'jonction' => 'Appeler la jonction « ' . (string) $etape['cible'] . ' »',
            default => (string) $etape['type'],
        };

        return ['type' => (string) $etape['type'], 'regle' => (string) ($etape['regle'] ?? ''), 'texte' => $texte];
    }
}
