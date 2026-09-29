<?php

declare(strict_types=1);

namespace Ged\Workflows;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Adaptateur de l'écran /workflows : règle et circuit au même endroit, essai à blanc sur un vrai document
 * du fonds, journal des transitions. Si le schéma n'est pas posé, l'écran le dit — jamais une erreur muette.
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
     * @param array<string, mixed> $parametres query string de /workflows
     * @return array<string, mixed>
     */
    public function donnees(array $parametres): array
    {
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
        $documents = array_map(static fn (array $f): array => [
            'id' => $f['id'], 'titre' => $f['titre'], 'type' => $f['type'], 'emetteur' => $f['emetteur'],
            'montant' => $f['montant'], 'date' => $f['date'],
        ], $fonds->toutes());

        return [
            'installe' => $installe,
            'regles' => $regles,
            'circuits' => $circuits,
            'gabarits' => $gabarits,
            'documents' => $documents,
            'journal' => array_map(fn (array $e): array => $this->entreeJournal($e), $journal),
            'essai' => $this->essai($parametres, $fonds, $installe),
            'declencheurs' => self::DECLENCHEURS,
            'champs_condition' => Conditions::CHAMPS,
            'operateurs' => Conditions::OPERATEURS,
            'etats_cibles' => $this->etatsCibles($circuits),
        ];
    }

    /**
     * L'essai à blanc demandé par la query string (`?essai=<id>&declencheur=<...>`) : pure lecture.
     *
     * @param array<string, mixed> $parametres
     * @return array<string, mixed>|null
     */
    private function essai(array $parametres, Fonds $fonds, bool $installe): ?array
    {
        if (!$installe || !isset($parametres['essai']) || $this->pdo === null) {
            return null;
        }
        $fiche = $fonds->parId((int) $parametres['essai']);
        if ($fiche === null) {
            return null;
        }
        $declencheur = (string) ($parametres['declencheur'] ?? 'document_depose');

        // Moteur sans dépôt : l'essai ne peut rien écrire, même par accident.
        $resultat = (new Moteur($this->pdo, null, $this->horloge))->essayer($declencheur, $fiche, true);

        return [
            'document' => ['id' => $fiche['id'], 'titre' => $fiche['titre']],
            'declencheur' => $declencheur,
            'regles' => $resultat['regles'],
            'etapes' => array_map(fn (array $e): array => $this->etapeLisible($e), $resultat['etapes']),
            'erreurs' => $resultat['erreurs'],
        ];
    }

    /** @param list<array<string, mixed>> $circuits @return list<string> */
    private function etatsCibles(array $circuits): array
    {
        $cibles = [];
        foreach ($circuits as $circuit) {
            foreach ($circuit['transitions'] as $transition) {
                $vers = (string) ($transition['vers'] ?? '');
                if ($vers !== '' && !in_array($vers, $cibles, true)) {
                    $cibles[] = $vers;
                }
            }
        }
        sort($cibles);

        return $cibles;
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
