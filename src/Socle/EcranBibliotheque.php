<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Écran Bibliothèque (V2-04), branché sur les VRAIES données : la liste des documents vient de
 * l'index (miroir reconstructible du dossier — F12), les valeurs du document retenu se relisent de
 * son `.meta.json` (le dossier fait foi), les vues enregistrées filtrent sans jamais déplacer.
 */
final class EcranBibliotheque
{
    private readonly PDO $pdo;
    private readonly Fonds $fonds;
    private readonly Vues $vues;
    private readonly Champs $champs;
    private readonly Nomenclature $nomenclature;

    public function __construct(Config $config)
    {
        $this->pdo = Base::pdo($config);
        $this->fonds = new Fonds($config);
        $this->vues = new Vues($config);
        $this->champs = new Champs($config);
        $this->nomenclature = new Nomenclature($config);
    }

    /**
     * Tout ce que `ecrans/bibliotheque.twig` attend : documents filtrés, panneau d'aperçu (avec les
     * champs personnalisés du document retenu), vues du volet, filtres compilés pour le formulaire.
     *
     * @param array<string, mixed> $parametres paramètres de requête (?doc=, ?vue=, filtres GET)
     * @return array<string, mixed>
     */
    public function donnees(array $parametres, string $csrf): array
    {
        // La LISTE vient de l'index (une requête) : sur un gros fonds, marcher le dossier ruinerait
        // l'écran (mesuré au banc de charge : p95 > 20 s pour 10 000 documents). L'index n'existe
        // pas encore (première seconde d'une installation) : le dossier répond seul.
        try {
            $tous = $this->fonds->indexes($this->pdo);
        } catch (PDOException) {
            $tous = $this->fonds->tous();
        }

        // Vue demandée par son numéro (liens du volet) : SES filtres compilés s'appliquent.
        $vueActive = null;
        $candidat = trim((string) ($parametres['vue'] ?? ''));
        if ($candidat !== '') {
            $vueActive = ctype_digit($candidat) ? $this->vues->lire((int) $candidat) : $this->vues->parSlug($candidat);
        }

        $filtres = null;
        $erreur = null;
        if ($vueActive !== null) {
            $filtres = $vueActive['filtres'];
        } else {
            try {
                $filtres = $this->vues->compiler($parametres);
            } catch (RuntimeException $e) {
                $erreur = $e->getMessage();   // filtre incohéré : nommé à l'écran, jamais un écran muet
            }
        }
        $filtres ??= $this->filtresNeutres();

        $docs = Vues::filtrerDocs($tous, $filtres);

        // Panneau d'aperçu : le document demandé (?doc=), sinon le premier de la sélection.
        $doc = null;
        $demande = (int) ($parametres['doc'] ?? 0);
        if ($demande > 0) {
            foreach ($tous as $d) {
                if ($d['id'] === $demande) {
                    $doc = $d;
                }
            }
        }
        $doc ??= $docs[0] ?? $tous[0] ?? null;

        return [
            'ecran' => 'bibliotheque',
            'bilan' => \Ged\Ui\Maquette::bilan(),   // badge « à traiter » du rail : encore la maquette (branchement : secteur Ingestion)
            'docs' => $docs,
            'compte' => count($docs),
            'total' => count($tous),
            'vuesVolet' => $this->vuesDuVolet($tous),
            'vueActive' => $vueActive,
            'arbre' => $this->fonds->arbre($tous),
            'types' => $this->typesSansEchec(),
            'tousChamps' => $this->champsSansEchec(),
            'operateurs' => Champs::operateurs(),
            'filtres' => $filtres,
            'erreur' => $erreur,
            'apercu' => $this->apercu($doc),
            'csrf' => $csrf,
        ];
    }

    /**
     * Les vues du volet : « Tous les documents » (le fonds entier, sans filtre) puis chaque vue
     * enregistrée avec le nombre de documents qui la satisfont — comptés sur place, jamais cachés.
     *
     * @param list<array<string, mixed>> $tous
     * @return list<array{id:?int, nom:string, compte:int}>
     */
    private function vuesDuVolet(array $tous): array
    {
        $volet = [['id' => null, 'nom' => 'Tous les documents', 'compte' => count($tous)]];
        foreach ($this->vues->lister() as $vue) {
            $volet[] = [
                'id' => $vue['id'], 'nom' => $vue['nom'],
                'compte' => count(Vues::filtrerDocs($tous, $vue['filtres'])),
            ];
        }

        return $volet;
    }

    /**
     * Le panneau d'aperçu d'un document : la visionneuse du secteur Aperçu quand le fichier est
     * rendable, et le formulaire des champs personnalisés de SON type avec les valeurs posées.
     *
     * @param array<string, mixed>|null $doc
     * @return array<string, mixed>|null
     */
    private function apercu(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }
        $extension = strtolower(pathinfo((string) $doc['nom'], PATHINFO_EXTENSION));
        $rendable = $doc['id'] > 0 && in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif'], true);

        $champs = [];
        if ($doc['type'] !== null) {
            // Les valeurs affichées (et éditables) viennent du `.meta.json` À CÔTÉ DU FICHIER — le
            // dossier fait foi ; l'index n'en est que la copie. Un seul fichier relu, jamais le fonds.
            $valeurs = $doc['champs'];
            $metaAbsolue = $this->fonds->racine() . '/' . $doc['chemin'] . '.meta.json';
            if (is_file($metaAbsolue)) {
                $surPlace = json_decode((string) file_get_contents($metaAbsolue), true);
                if (is_array($surPlace) && isset($surPlace['champs']) && is_array($surPlace['champs'])) {
                    $valeurs = $surPlace['champs'];
                }
            }
            foreach ($this->champs->pourType($doc['type']) as $def) {
                $champs[] = $def + ['valeur' => $valeurs[$def['cle']] ?? null];
            }
        }

        return [
            'id' => $doc['id'], 'titre' => $doc['titre'], 'type' => $doc['type'], 'emetteur' => $doc['emetteur'],
            'date' => $doc['date'], 'chemin' => $doc['chemin'], 'champs' => $champs,
            'rendu' => $rendable
                ? ['url' => '/documents/' . $doc['id'] . '/apercu/fichier', 'categorie' => $extension === 'pdf' ? 'pdf' : 'image',
                    'pages' => 1, 'titre' => $doc['titre'], 'message' => '']
                : ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $doc['titre'],
                    'message' => $doc['id'] > 0 ? 'Aucun aperçu pour ce format.' : 'Document non indexé : aucun aperçu.'],
        ];
    }

    /** Filtres neutres (aucune contrainte) : la forme compilée vide, pour le gabarit toujours fournie. */
    private function filtresNeutres(): array
    {
        return ['type' => null, 'emetteur' => null, 'de' => null, 'a' => null, 'etiquettes' => [], 'champ' => null];
    }

    /**
     * La nomenclature vit dans le fonds (`.ged/`, V2-04) : sa lecture ne peut plus lever qu'une
     * RuntimeException (fichier illisible) — nommée dans les journaux, jamais un écran muet.
     * @return list<string>
     */
    private function typesSansEchec(): array
    {
        try {
            return $this->nomenclature->types();
        } catch (PDOException | RuntimeException) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    private function champsSansEchec(): array
    {
        try {
            return $this->champs->tous();
        } catch (PDOException | RuntimeException) {
            return [];
        }
    }
}
