<?php

declare(strict_types=1);

namespace Ged\Workflows;

use PDO;

/**
 * Accès à l'éditeur graphique : charge un circuit avec ses règles en graphe, enregistre un graphe
 * dans le modèle du moteur, et relie un plan d'essai à blanc aux nœuds du dessin (le « chemin suivi »).
 *
 * Rien ici n'invente un format : le dessin passe par `AdaptateurGraphe`, le modèle par `Circuits` et
 * `Regles`. Le graphe de GEDv1 n'entre jamais en base.
 */
final class Editeur
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function circuit(int $id): ?array
    {
        return (new Circuits($this->pdo))->parId($id);
    }

    /**
     * Les règles d'un circuit : celles de son gabarit d'origine, ou celles que le designer lui a attachées.
     * Ordre stable par identifiant : il définit l'ordre des chaînes dans le graphe.
     *
     * @param array<string, mixed> $circuit
     * @return list<array<string, mixed>>
     */
    public function reglesAssociees(array $circuit): array
    {
        $id = (int) ($circuit['id'] ?? 0);
        $gabarit = $circuit['gabarit'] ?? null;
        $associees = [];
        foreach ((new Regles($this->pdo))->toutes() as $regle) {
            $parGabarit = $gabarit !== null && $gabarit !== '' && $regle['gabarit'] === $gabarit;
            $parCircuit = $regle['circuit_id'] !== null && $regle['circuit_id'] === $id;
            if ($parGabarit || $parCircuit) {
                $associees[$regle['id']] = $regle;
            }
        }
        ksort($associees);

        return array_values($associees);
    }

    /** @return array<string, mixed>|null le graphe prêt pour l'éditeur, ou null si le circuit n'existe pas. */
    public function graphe(int $circuitId): ?array
    {
        $circuit = $this->circuit($circuitId);
        if ($circuit === null) {
            return null;
        }

        return AdaptateurGraphe::versGraphe($circuit, $this->reglesAssociees($circuit));
    }

    /** Crée un circuit vide (un nom) et rend son identifiant. Jamais de doublon de nom : le nom est unique par offre. */
    public function creerCircuit(string $nom): int
    {
        $propose = trim($nom) !== '' ? trim($nom) : 'Nouveau circuit';
        $existant = (new Circuits($this->pdo))->parNom($propose);
        if ($existant !== null) {
            throw new ErreurWorkflow("un circuit s’appelle déjà « $propose »");
        }

        return (new Circuits($this->pdo))->creer(AdaptateurGraphe::grapheVide($propose));
    }

    /**
     * Enregistre un graphe dans le circuit : états + transitions d'un côté, règles de l'autre.
     * Aucune suppression : une règle qui disparaît du dessin est mise EN VEILLE (le journal et
     * l'historique restent).
     *
     * @param array<string, mixed> $graphe
     * @return array{circuit_id:int, regles:int, en_veille:int}
     */
    public function enregistrer(int $circuitId, array $graphe): array
    {
        $circuit = $this->circuit($circuitId);
        if ($circuit === null) {
            throw new ErreurWorkflow("circuit $circuitId introuvable");
        }

        $modele = AdaptateurGraphe::versModele($graphe);
        $associees = $this->reglesAssociees($circuit);

        (new Circuits($this->pdo))->mettreAJour($circuitId, $modele['circuit']);

        $regles = new Regles($this->pdo);
        $nb = 0;
        foreach ($modele['regles'] as $i => $regle) {
            $regle['circuit_id'] = $circuitId;
            if (isset($associees[$i])) {
                $regles->mettreAJour($associees[$i]['id'], $regle);
            } else {
                $regles->creer($regle);
            }
            $nb++;
        }

        $enVeille = 0;
        for ($i = $nb; $i < count($associees); $i++) {
            $detachee = $associees[$i];
            $detachee['actif'] = false;
            $detachee['circuit_id'] = $circuitId;
            $regles->mettreAJour((int) $associees[$i]['id'], $detachee);
            $enVeille++;
        }

        return ['circuit_id' => $circuitId, 'regles' => $nb, 'en_veille' => $enVeille];
    }

    /**
     * Chemin suivi dans le graphe lors d'un essai à blanc : les nœuds des règles retenues et les états
     * visés par un démarrage. Sert à surligner le dessin.
     *
     * @param array<string, mixed> $graphe
     * @param array<string, mixed> $plan résultat de `Moteur::essayer()`
     * @param list<int> $ordreRegles identifiants de règles associées, dans l'ordre du graphe
     * @return list<string> identifiants de nœuds, sans doublon
     */
    public static function chemin(array $graphe, array $plan, array $ordreRegles): array
    {
        $disposition = AdaptateurGraphe::versModele($graphe)['circuit']['graphe']['noeuds'];
        $indexParId = array_flip($ordreRegles);
        $chemin = [];

        foreach ((array) ($plan['regles'] ?? []) as $regle) {
            if (empty($regle['retenue'])) {
                continue;
            }
            $index = $indexParId[$regle['id']] ?? null;
            if ($index === null) {
                continue;
            }
            foreach ($disposition as $entree) {
                if ((int) ($entree['regle'] ?? -1) === $index && !in_array((string) $entree['id'], $chemin, true)) {
                    $chemin[] = (string) $entree['id'];
                }
            }
        }

        foreach ((array) ($plan['etapes'] ?? []) as $etape) {
            if ((string) ($etape['type'] ?? '') !== 'demarrer') {
                continue;
            }
            $cible = (string) ($etape['etat'] ?? '');
            foreach ((array) ($graphe['noeuds'] ?? []) as $noeud) {
                if (($noeud['genre'] ?? '') === 'etat' && (string) (($noeud['config']['nom'] ?? '')) === $cible
                    && !in_array((string) $noeud['id'], $chemin, true)) {
                    $chemin[] = (string) $noeud['id'];
                }
            }
        }

        return $chemin;
    }
}
