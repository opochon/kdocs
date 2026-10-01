<?php

declare(strict_types=1);

namespace Ged\Workflows;

/**
 * Adaptateur graphe ↔ modèle du moteur, dans les deux sens :
 *  - `versModele()` projette le dessin (nœuds + liaisons) sur ce que le moteur exécute réellement :
 *    les nœuds « état » deviennent les états du CIRCUIT, les liaisons entre états ses transitions ;
 *    chaque chaîne déclencheur → conditions → action(s) devient une RÈGLE ;
 *  - `versGraphe()` reconstruit le dessin depuis le circuit et ses règles. La disposition (positions,
 *    identifiants, ordre) n'a rien à voir avec le moteur : elle est rangée dans `corps.graphe` du circuit,
 *    jamais dans le format de nœuds de GEDv1.
 *
 * Le graphe est CANONIQUE : un nœud = {id, genre, type, nom, x, y, config}. Le graphe → circuit → graphe
 * est exact (positions et identifiants compris).
 */
final class AdaptateurGraphe
{
    public const GENRES = ['declencheur', 'condition', 'action', 'etat'];

    /**
     * @param array<string, mixed> $graphe
     * @return array{circuit: array<string, mixed>, regles: list<array<string, mixed>>}
     */
    public static function versModele(array $graphe): array
    {
        $noeuds = array_values((array) ($graphe['noeuds'] ?? []));
        $liens = array_values((array) ($graphe['liens'] ?? []));
        $parId = self::indexer($noeuds);

        $sortants = [];
        $entrants = [];
        foreach ($liens as $lien) {
            $de = (string) ($lien['de'] ?? '');
            $vers = (string) ($lien['vers'] ?? '');
            if (!isset($parId[$de]) || !isset($parId[$vers])) {
                throw new ErreurWorkflow("liaison vers un nœud inconnu ($de → $vers)");
            }
            $sortants[$de][] = $lien;
            $entrants[$vers][] = $lien;
        }

        [$etats, $etatParNoeud] = self::etats($noeuds);
        $transitions = self::transitions($liens, $etatParNoeud);

        // Chaque déclencheur ouvre une règle : conditions rencontrées (ET) puis actions.
        $regles = [];
        $infos = [];
        foreach ($noeuds as $noeud) {
            if ($noeud['genre'] !== 'declencheur') {
                continue;
            }
            $id = (string) $noeud['id'];
            if (isset($entrants[$id])) {
                throw new ErreurWorkflow('le déclencheur « ' . self::nom($noeud) . ' » ne peut pas avoir d’entrée');
            }
            [$regle, $infosChaine] = self::chaineRegle($noeud, $parId, $sortants, count($regles));
            $regles[] = $regle;
            $infos += $infosChaine;
        }

        // Un nœud de règle non atteint n'aurait aucun sens : on le refuse nommément plutôt que de le perdre.
        foreach ($noeuds as $noeud) {
            if (in_array($noeud['genre'], ['condition', 'action'], true) && !isset($infos[(string) $noeud['id']])) {
                throw new ErreurWorkflow('le nœud « ' . self::nom($noeud) . ' » n’est relié à aucune règle');
            }
        }

        $gabarit = isset($graphe['gabarit']) && $graphe['gabarit'] !== null && $graphe['gabarit'] !== ''
            ? (string) $graphe['gabarit'] : null;
        foreach ($regles as $i => $regle) {
            $regles[$i]['gabarit'] = $gabarit;
        }

        // La disposition garde l'ORDRE du dessin : c'est ce qui rend l'aller-retour exact.
        $disposition = [];
        foreach ($noeuds as $noeud) {
            $id = (string) $noeud['id'];
            $disposition[] = $noeud['genre'] === 'etat'
                ? self::entreeEtat($noeud)
                : self::entreeRegle($noeud, $infos[$id]['regle'], $infos[$id]['role'], $infos[$id]['rang']);
        }

        $circuit = [
            'nom' => (string) ($graphe['nom'] ?? 'Circuit sans nom'),
            'gabarit' => $gabarit,
            'actif' => !empty($graphe['actif']),
            'etats' => $etats,
            'transitions' => $transitions,
            'graphe' => ['noeuds' => $disposition, 'liens' => $liens],
        ];

        return ['circuit' => $circuit, 'regles' => $regles];
    }

    /**
     * @param array<string, mixed> $circuit
     * @param list<array<string, mixed>> $regles
     * @return array<string, mixed>
     */
    public static function versGraphe(array $circuit, array $regles): array
    {
        $disposition = $circuit['graphe'] ?? null;
        if (!is_array($disposition) || ($disposition['noeuds'] ?? []) === []) {
            $disposition = self::dispositionParDefaut($circuit, $regles);
        }

        $etats = [];
        foreach ((array) ($circuit['etats'] ?? []) as $etat) {
            $etats[(string) ($etat['nom'] ?? '')] = $etat;
        }

        $noeuds = [];
        foreach ((array) ($disposition['noeuds'] ?? []) as $entree) {
            $noeuds[] = self::noeudDepuisDisposition((array) $entree, $etats, $regles);
        }

        $liens = [];
        foreach ((array) ($disposition['liens'] ?? []) as $lien) {
            $liens[] = (array) $lien;
        }

        return [
            'nom' => (string) ($circuit['nom'] ?? 'Circuit sans nom'),
            'gabarit' => $circuit['gabarit'] ?? null,
            'actif' => (bool) ($circuit['actif'] ?? false),
            'noeuds' => $noeuds,
            'liens' => $liens,
        ];
    }

    /** @return array<string, mixed> */
    public static function grapheVide(string $nom, ?string $gabarit = null): array
    {
        return ['nom' => $nom, 'gabarit' => $gabarit, 'actif' => true, 'noeuds' => [], 'liens' => []];
    }

    // ------------------------------------------------------------------ vers le modèle

    /**
     * @param list<array<string, mixed>> $noeuds
     * @return array<string, array<string, mixed>>
     */
    private static function indexer(array $noeuds): array
    {
        $parId = [];
        foreach ($noeuds as $noeud) {
            $id = (string) ($noeud['id'] ?? '');
            $genre = (string) ($noeud['genre'] ?? '');
            if ($id === '') {
                throw new ErreurWorkflow('un nœud du graphe n’a pas d’identifiant');
            }
            if (isset($parId[$id])) {
                throw new ErreurWorkflow("identifiant de nœud dupliqué : « $id »");
            }
            if (!in_array($genre, self::GENRES, true)) {
                throw new ErreurWorkflow("nœud « $id » : genre inconnu « $genre » (hors catalogue du moteur)");
            }
            if (Catalogue::parType((string) ($noeud['type'] ?? '')) === null) {
                throw new ErreurWorkflow('nœud « ' . self::nom($noeud) . ' » : type inconnu « ' . (string) ($noeud['type'] ?? '') . ' »');
            }
            $parId[$id] = $noeud;
        }

        return $parId;
    }

    /**
     * @param list<array<string, mixed>> $noeuds
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private static function etats(array $noeuds): array
    {
        $etats = [];
        $parNoeud = [];
        foreach ($noeuds as $noeud) {
            if ($noeud['genre'] !== 'etat') {
                continue;
            }
            $config = (array) ($noeud['config'] ?? []);
            $nom = trim((string) ($config['nom'] ?? ''));
            if ($nom === '') {
                throw new ErreurWorkflow('l’état « ' . self::nom($noeud) . ' » n’a pas d’identifiant');
            }
            if (isset($parNoeud[$nom])) {
                throw new ErreurWorkflow("deux états portent le même identifiant : « $nom »");
            }
            $etats[] = ['nom' => $nom, 'libelle' => ((string) ($config['libelle'] ?? '')) ?: $nom,
                'initial' => !empty($config['initial']), 'final' => !empty($config['final'])];
            $parNoeud[(string) $noeud['id']] = $nom;
        }

        return [$etats, $parNoeud];
    }

    /**
     * @param list<array<string, mixed>> $liens
     * @param array<string, string> $etatParNoeud
     * @return list<array<string, mixed>>
     */
    private static function transitions(array $liens, array $etatParNoeud): array
    {
        $transitions = [];
        foreach ($liens as $lien) {
            $de = (string) ($lien['de'] ?? '');
            $vers = (string) ($lien['vers'] ?? '');
            $estEtatDe = isset($etatParNoeud[$de]);
            $estEtatVers = isset($etatParNoeud[$vers]);
            if ($estEtatDe !== $estEtatVers) {
                throw new ErreurWorkflow("une liaison ne peut pas mêler un état et un nœud de règle ($de → $vers)");
            }
            if ($estEtatDe) {
                $transitions[] = [
                    'de' => $etatParNoeud[$de], 'vers' => $etatParNoeud[$vers],
                    'libelle' => (string) ($lien['libelle'] ?? ''),
                    'permission' => (string) ($lien['permission'] ?? ('workflow.transition.' . $etatParNoeud[$vers])),
                ];
            }
        }

        return $transitions;
    }

    /**
     * @param array<string, mixed> $depart
     * @param array<string, array<string, mixed>> $parId
     * @param array<string, list<array<string, mixed>>> $sortants
     * @return array{0: array<string, mixed>, 1: array<string, array{regle:int, role:string, rang:int}>}
     */
    private static function chaineRegle(array $depart, array $parId, array $sortants, int $index): array
    {
        $id = (string) $depart['id'];
        $infos = [$id => ['regle' => $index, 'role' => 'declencheur', 'rang' => 0]];
        $conditions = [];
        $actions = [];
        $rangCondition = 0;
        $rangAction = 0;
        $courant = $id;
        $vus = [$id => true];

        while (($sortants[$courant] ?? []) !== []) {
            if (count($sortants[$courant]) > 1) {
                throw new ErreurWorkflow('branchement non supporté sur « ' . self::nom($parId[$courant]) . ' » : une seule suite par nœud de règle');
            }
            $lien = $sortants[$courant][0];
            if ((string) ($lien['sortie'] ?? 'default') !== 'default') {
                throw new ErreurWorkflow('sortie « ' . (string) ($lien['sortie'] ?? '') . ' » non supportée sur un nœud de règle');
            }
            $suivant = (string) ($lien['vers'] ?? '');
            if (isset($vus[$suivant])) {
                throw new ErreurWorkflow('boucle détectée dans la règle « ' . self::nom($depart) . ' »');
            }
            $vus[$suivant] = true;
            $noeud = $parId[$suivant];

            if ($noeud['genre'] === 'condition') {
                $config = (array) ($noeud['config'] ?? []);
                $champ = trim((string) ($config['champ'] ?? ''));
                if (!in_array($champ, Conditions::CHAMPS, true)) {
                    throw new ErreurWorkflow('condition sans champ valide sur « ' . self::nom($noeud) . ' »');
                }
                $conditions[] = ['champ' => $champ, 'operateur' => (string) ($config['operateur'] ?? 'egal'), 'valeur' => $config['valeur'] ?? ''];
                $infos[$suivant] = ['regle' => $index, 'role' => 'condition', 'rang' => $rangCondition++];
            } elseif ($noeud['genre'] === 'action') {
                $config = (array) ($noeud['config'] ?? []);
                if (trim((string) ($config['type'] ?? '')) === '') {
                    throw new ErreurWorkflow('action sans type sur « ' . self::nom($noeud) . ' »');
                }
                $actions[] = $config;
                $infos[$suivant] = ['regle' => $index, 'role' => 'action', 'rang' => $rangAction++];
            } else {
                throw new ErreurWorkflow('une règle ne peut pas traverser un nœud « ' . $noeud['genre'] . ' » (' . self::nom($noeud) . ')');
            }

            $courant = $suivant;
        }

        if ($actions === []) {
            throw new ErreurWorkflow('la règle « ' . self::nom($depart) . ' » n’aboutit à aucune action');
        }

        $declencheur = (array) ($depart['config'] ?? []);
        $regle = [
            'nom' => self::nom($depart),
            'declencheur' => (string) ($declencheur['evenement'] ?? 'document_depose'),
            'conditions' => $conditions,
            'actions' => $actions,
            'actif' => true,
            'gabarit' => null,
        ];

        return [$regle, $infos];
    }

    /** @param array<string, mixed> $noeud @return array<string, mixed> */
    private static function entreeEtat(array $noeud): array
    {
        $config = (array) ($noeud['config'] ?? []);

        return ['id' => (string) $noeud['id'], 'genre' => 'etat', 'type' => 'etat',
            'nom' => (string) ($config['libelle'] ?? $config['nom'] ?? ''), 'etat' => (string) ($config['nom'] ?? ''),
            'x' => (int) ($noeud['x'] ?? 0), 'y' => (int) ($noeud['y'] ?? 0)];
    }

    /** @param array<string, mixed> $noeud @return array<string, mixed> */
    private static function entreeRegle(array $noeud, int $regle, string $role, int $rang): array
    {
        return ['id' => (string) $noeud['id'], 'genre' => (string) $noeud['genre'], 'type' => (string) $noeud['type'],
            'nom' => self::nom($noeud), 'x' => (int) ($noeud['x'] ?? 0), 'y' => (int) ($noeud['y'] ?? 0),
            'regle' => $regle, 'role' => $role, 'rang' => $rang];
    }

    /** @param array<string, mixed> $noeud */
    private static function nom(array $noeud): string
    {
        return (string) ($noeud['nom'] ?? $noeud['type'] ?? 'nœud');
    }

    // ------------------------------------------------------------------ vers le graphe

    /**
     * @param array<string, mixed> $entree
     * @param array<string, array<string, mixed>> $etats
     * @param list<array<string, mixed>> $regles
     * @return array<string, mixed>
     */
    private static function noeudDepuisDisposition(array $entree, array $etats, array $regles): array
    {
        $genre = (string) ($entree['genre'] ?? '');
        if ($genre === 'etat') {
            $nom = (string) ($entree['etat'] ?? '');
            if (!isset($etats[$nom])) {
                throw new ErreurWorkflow("l’état « $nom » de la disposition n’existe pas dans le circuit");
            }
            $etat = $etats[$nom];

            return ['id' => (string) $entree['id'], 'genre' => 'etat', 'type' => 'etat',
                'nom' => (string) ($entree['nom'] ?? $etat['libelle'] ?? $nom),
                'x' => (int) ($entree['x'] ?? 0), 'y' => (int) ($entree['y'] ?? 0),
                'config' => ['nom' => (string) $etat['nom'], 'libelle' => (string) ($etat['libelle'] ?? $etat['nom']),
                    'initial' => (bool) ($etat['initial'] ?? false), 'final' => (bool) ($etat['final'] ?? false)]];
        }

        $regle = $regles[(int) ($entree['regle'] ?? -1)] ?? null;
        if ($regle === null) {
            throw new ErreurWorkflow('disposition : règle introuvable pour le nœud « ' . (string) ($entree['id'] ?? '') . ' »');
        }
        $role = (string) ($entree['role'] ?? '');
        $rang = (int) ($entree['rang'] ?? 0);
        $config = match ($role) {
            'declencheur' => ['evenement' => (string) ($regle['declencheur'] ?? 'document_depose')],
            'condition' => (array) ($regle['conditions'][$rang] ?? []),
            'action' => (array) ($regle['actions'][$rang] ?? []),
            default => throw new ErreurWorkflow("rôle inconnu dans la disposition : « $role »"),
        };
        if ($config === []) {
            throw new ErreurWorkflow('disposition incomplète pour le nœud « ' . (string) ($entree['id'] ?? '') . ' »');
        }
        $type = match ($role) {
            'declencheur' => 'declencheur',
            'condition' => 'condition_' . (string) ($config['champ'] ?? ''),
            'action' => 'action_' . (string) ($config['type'] ?? ''),
            default => (string) ($entree['type'] ?? ''),
        };

        return ['id' => (string) $entree['id'], 'genre' => (string) ($entree['genre'] ?? ''),
            'type' => $type, 'nom' => (string) ($entree['nom'] ?? ''), 'x' => (int) ($entree['x'] ?? 0), 'y' => (int) ($entree['y'] ?? 0),
            'config' => $config];
    }

    /**
     * Disposition par défaut : les règles en haut (chaque règle = une ligne), les états en dessous.
     * Utilisée quand le circuit n'a pas encore de disposition (gabarit installé hors éditeur).
     *
     * @param array<string, mixed> $circuit
     * @param list<array<string, mixed>> $regles
     * @return array{noeuds: list<array<string, mixed>>, liens: list<array<string, mixed>>}
     */
    private static function dispositionParDefaut(array $circuit, array $regles): array
    {
        $noeuds = [];
        $liens = [];
        $largeur = 210;

        foreach ($regles as $i => $regle) {
            $y = 40 + $i * 190;
            $x = 40;
            $idPrecedent = "r{$i}_t";
            $noeuds[] = ['id' => $idPrecedent, 'genre' => 'declencheur', 'type' => 'declencheur',
                'nom' => (string) ($regle['nom'] ?? 'Règle'), 'x' => $x, 'y' => $y, 'regle' => $i, 'role' => 'declencheur', 'rang' => 0];
            foreach (array_values((array) ($regle['conditions'] ?? [])) as $rang => $condition) {
                $x += $largeur;
                $id = "r{$i}_c{$rang}";
                $noeuds[] = ['id' => $id, 'genre' => 'condition', 'type' => 'condition_' . (string) ($condition['champ'] ?? ''),
                    'nom' => self::decrireCondition((array) $condition), 'x' => $x, 'y' => $y, 'regle' => $i, 'role' => 'condition', 'rang' => $rang];
                $liens[] = ['id' => "l{$i}_{$rang}", 'de' => $idPrecedent, 'vers' => $id, 'sortie' => 'default'];
                $idPrecedent = $id;
            }
            foreach (array_values((array) ($regle['actions'] ?? [])) as $rang => $action) {
                $x += $largeur;
                $id = "r{$i}_a{$rang}";
                $noeuds[] = ['id' => $id, 'genre' => 'action', 'type' => 'action_' . (string) ($action['type'] ?? ''),
                    'nom' => self::decrireAction((array) $action), 'x' => $x, 'y' => $y, 'regle' => $i, 'role' => 'action', 'rang' => $rang];
                $liens[] = ['id' => "l{$i}_a{$rang}", 'de' => $idPrecedent, 'vers' => $id, 'sortie' => 'default'];
                $idPrecedent = $id;
            }
        }

        $yEtats = 40 + count($regles) * 190 + 40;
        $idParEtat = [];
        foreach (array_values((array) ($circuit['etats'] ?? [])) as $i => $etat) {
            $id = "e{$i}";
            $idParEtat[(string) ($etat['nom'] ?? '')] = $id;
            $noeuds[] = ['id' => $id, 'genre' => 'etat', 'type' => 'etat',
                'nom' => (string) ($etat['libelle'] ?? $etat['nom'] ?? ''), 'etat' => (string) ($etat['nom'] ?? ''),
                'x' => 40 + $i * $largeur, 'y' => $yEtats];
        }
        foreach (array_values((array) ($circuit['transitions'] ?? [])) as $i => $transition) {
            $de = $idParEtat[(string) ($transition['de'] ?? '')] ?? null;
            $vers = $idParEtat[(string) ($transition['vers'] ?? '')] ?? null;
            if ($de === null || $vers === null) {
                continue;
            }
            $liens[] = ['id' => "t{$i}", 'de' => $de, 'vers' => $vers, 'sortie' => 'default',
                'libelle' => (string) ($transition['libelle'] ?? ''), 'permission' => (string) ($transition['permission'] ?? '')];
        }

        return ['noeuds' => $noeuds, 'liens' => $liens];
    }

    /** @param array<string, mixed> $condition */
    private static function decrireCondition(array $condition): string
    {
        $libelles = ['egal' => '=', 'different' => '≠', 'contient' => 'contient', 'superieur' => '>',
            'superieur_ou_egal' => '≥', 'inferieur' => '<', 'inferieur_ou_egal' => '≤', 'dans' => 'dans', 'non_vide' => 'non vide'];
        $operateur = (string) ($condition['operateur'] ?? 'egal');
        $valeur = $condition['valeur'] ?? '';
        $suffixe = is_scalar($valeur) && (string) $valeur !== '' ? ' ' . (string) $valeur : '';

        return ucfirst((string) ($condition['champ'] ?? '')) . ' ' . ($libelles[$operateur] ?? $operateur) . $suffixe;
    }

    /** @param array<string, mixed> $action */
    private static function decrireAction(array $action): string
    {
        $entree = Catalogue::parType('action_' . (string) ($action['type'] ?? ''));

        return (string) ($entree['libelle'] ?? ($action['type'] ?? 'Action'));
    }
}
