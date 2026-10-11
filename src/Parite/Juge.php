<?php

declare(strict_types=1);

namespace Ged\Parite;

/**
 * Juge de parite Paperless-ngx / GEDv2 (lot ged-oracle-paperless-1).
 *
 * Le juge ne decide pas du pourcentage : il constate. Un element n'est « couvert » que si
 * l'equivalent GEDv2 cite existe ET si la preuve citee a reussi au dernier rejeu. Toute
 * autre situation (preuve absente, preuve en echec, equivalent introuvable, statut manquant)
 * reclasse l'element en ecart.
 *
 * Regles :
 *  - une exclusion (hors perimetre) n'est jamais comptee comme couverte ;
 *  - la parite STRICTE compte les exclusions comme des ecarts ;
 *  - la parite HORS EXCLUSIONS les retire du denominateur ;
 *  - un statut manquant ou un id inconnu rend le juge rouge (sortie non nulle cote CLI).
 */
final class Juge
{
    /** @var list<string> */
    public const STATUTS = ['couvert', 'couvert-autrement', 'absent', 'exclu'];

    /** @var list<string> */
    private const EFFECTIFS_COUVERTS = ['couvert', 'couvert-autrement'];

    /**
     * @param list<array<string, mixed>> $inventaire
     * @param array<string, mixed> $correspondance
     * @param list<string> $preuvesReussies
     * @param list<string> $preuvesEchouees
     * @param array{routes:list<string>, ecrans:list<string>, services:list<string>} $connu
     * @return array<string, mixed>
     */
    public function analyser(
        array $inventaire,
        array $correspondance,
        array $preuvesReussies,
        array $preuvesEchouees,
        array $connu,
    ): array {
        $erreurs = [];
        $ids = [];
        foreach ($inventaire as $element) {
            $ids[(string) ($element['id'] ?? '')] = true;
        }
        foreach (array_keys($correspondance) as $id) {
            if (!isset($ids[(string) $id])) {
                $erreurs[] = 'correspondance cite un id inconnu : ' . (string) $id;
            }
        }

        /** @var array<string, array<string, int>> $compteurs */
        $compteurs = [];
        /** @var array<string, array<string, int>> $compteursGlobaux */
        $compteursGlobaux = [];
        $ecarts = [];
        $exclusions = [];

        foreach ($inventaire as $element) {
            $id = (string) ($element['id'] ?? '');
            $categorie = (string) ($element['categorie'] ?? 'inconnue');
            $entree = $correspondance[$id] ?? null;

            $effectif = 'sans-statut';
            $raison = 'aucun statut dans correspondance.json';

            if (!is_array($entree)) {
                $erreurs[] = 'element sans statut : ' . $id;
            } else {
                $statutBrut = $entree['statut'] ?? null;
                if (!is_string($statutBrut) || !in_array($statutBrut, self::STATUTS, true)) {
                    $erreurs[] = 'statut invalide ou manquant pour : ' . $id;
                } elseif ($statutBrut === 'absent') {
                    $effectif = 'absent';
                    $raison = $this->raisonTexte($entree, 'note', 'ecart assume, lot a creer');
                } elseif ($statutBrut === 'exclu') {
                    $exclusion = $entree['exclusion'] ?? '';
                    if (!is_string($exclusion) || trim($exclusion) === '') {
                        $erreurs[] = 'exclusion sans raison pour : ' . $id;
                        $raison = 'exclusion sans raison';
                    } else {
                        $raison = $exclusion;
                    }
                    $effectif = 'exclu';
                    $exclusions[] = [
                        'id' => $id,
                        'categorie' => $categorie,
                        'libelle' => (string) ($element['libelle'] ?? ''),
                        'reference' => (string) ($element['reference'] ?? ''),
                        'statut' => 'exclu',
                        'exclusion' => $raison,
                    ];
                } else {
                    [$couvert, $raisonCouverture] = $this->verifierCouverture(
                        $entree,
                        $preuvesReussies,
                        $preuvesEchouees,
                        $connu,
                    );
                    $effectif = $couvert ? $statutBrut : 'non-prouve';
                    $raison = $couvert ? '' : $raisonCouverture;
                }
            }

            $this->compter($compteurs, $categorie, $effectif);
            $this->compter($compteursGlobaux, 'global', $effectif);

            if (!in_array($effectif, self::EFFECTIFS_COUVERTS, true)) {
                $ecarts[] = [
                    'id' => $id,
                    'categorie' => $categorie,
                    'libelle' => (string) ($element['libelle'] ?? ''),
                    'reference' => (string) ($element['reference'] ?? ''),
                    'statut' => $effectif,
                    'raison' => $raison,
                    'lot' => is_array($entree) ? $this->raisonTexte($entree, 'lot', '') : '',
                ];
            }
        }

        return [
            'erreurs' => $erreurs,
            'total' => count($inventaire),
            'categories' => $this->finaliser($compteurs),
            'global' => $this->finaliserBloc($compteursGlobaux['global'] ?? $this->compteursVides()),
            'ecarts' => $ecarts,
            'exclusions' => $exclusions,
        ];
    }

    /** @return array{total:int, couverts:int, absents:int, exclus:int, non_prouves:int} */
    private function compteursVides(): array
    {
        return ['total' => 0, 'couverts' => 0, 'absents' => 0, 'exclus' => 0, 'non_prouves' => 0];
    }

    /**
     * @param array<string, array<string, int>> $compteurs
     */
    private function compter(array &$compteurs, string $cle, string $effectif): void
    {
        if (!isset($compteurs[$cle])) {
            $compteurs[$cle] = $this->compteursVides();
        }
        $compteurs[$cle]['total']++;
        if (in_array($effectif, self::EFFECTIFS_COUVERTS, true)) {
            $compteurs[$cle]['couverts']++;
        } elseif ($effectif === 'exclu') {
            $compteurs[$cle]['exclus']++;
        } elseif ($effectif === 'absent') {
            $compteurs[$cle]['absents']++;
        } else {
            $compteurs[$cle]['non_prouves']++;
        }
    }

    /**
     * @param array<string, array<string, int>> $compteurs
     * @return array<string, array<string, int|float>>
     */
    private function finaliser(array $compteurs): array
    {
        $resultat = [];
        foreach ($compteurs as $cle => $valeurs) {
            $resultat[$cle] = $this->finaliserBloc($valeurs);
        }

        return $resultat;
    }

    /**
     * @param array<string, int> $valeurs
     * @return array{total:int, couverts:int, absents:int, exclus:int, non_prouves:int, strict_pct:float, hors_exclusions_pct:float}
     */
    private function finaliserBloc(array $valeurs): array
    {
        $total = $valeurs['total'];
        $horsExclusions = $total - $valeurs['exclus'];

        return [
            'total' => $total,
            'couverts' => $valeurs['couverts'],
            'absents' => $valeurs['absents'],
            'exclus' => $valeurs['exclus'],
            'non_prouves' => $valeurs['non_prouves'],
            'strict_pct' => $total > 0 ? round($valeurs['couverts'] / $total * 100, 2) : 0.0,
            'hors_exclusions_pct' => $horsExclusions > 0
                ? round($valeurs['couverts'] / $horsExclusions * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param array<string, mixed> $entree
     * @param list<string> $preuvesReussies
     * @param list<string> $preuvesEchouees
     * @param array{routes:list<string>, ecrans:list<string>, services:list<string>} $connu
     * @return array{0:bool, 1:string}
     */
    private function verifierCouverture(
        array $entree,
        array $preuvesReussies,
        array $preuvesEchouees,
        array $connu,
    ): array {
        $gedv2 = $entree['gedv2'] ?? null;
        if (!is_array($gedv2) || !isset($gedv2['type'])) {
            return [false, 'equivalent GEDv2 non declare'];
        }
        $cible = $this->cibleGedv2($gedv2);
        $existe = match ($gedv2['type']) {
            'route' => in_array($cible, $connu['routes'], true),
            'ecran' => in_array($cible, $connu['ecrans'], true),
            'service' => in_array($cible, $connu['services'], true),
            default => false,
        };
        if (!$existe) {
            return [false, 'equivalent GEDv2 introuvable : ' . $cible];
        }

        $preuve = $entree['preuve'] ?? null;
        if (!is_array($preuve) || !isset($preuve['cible']) || !is_string($preuve['cible'])) {
            return [false, 'preuve non declaree'];
        }
        $ciblePreuve = $preuve['cible'];
        if (in_array($ciblePreuve, $preuvesReussies, true)) {
            return [true, ''];
        }
        if (in_array($ciblePreuve, $preuvesEchouees, true)) {
            return [false, 'la preuve citee a echoue au dernier rejeu : ' . $ciblePreuve];
        }

        return [false, 'preuve introuvable ou jamais rejouee : ' . $ciblePreuve];
    }

    /** @param array<string, mixed> $gedv2 */
    private function cibleGedv2(array $gedv2): string
    {
        return match ($gedv2['type']) {
            'route' => strtoupper((string) ($gedv2['methode'] ?? 'GET')) . ' ' . (string) ($gedv2['chemin'] ?? ''),
            'ecran' => (string) ($gedv2['fichier'] ?? ''),
            'service' => (string) ($gedv2['classe'] ?? ''),
            default => '',
        };
    }

    /** @param array<string, mixed> $entree */
    private function raisonTexte(array $entree, string $cle, string $defaut): string
    {
        $valeur = $entree[$cle] ?? null;

        return is_string($valeur) && trim($valeur) !== '' ? $valeur : $defaut;
    }
}
