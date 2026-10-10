<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Contrats\Classeur;
use Ged\Socle\DepotFichiers;
use Ged\Archivage\Scellement;

/**
 * Retouches de pièces avant validation. Les PDF déposés sont les seuls modifiés : le lot de travail
 * d'origine n'est jamais ouvert en écriture. Chaque geste prend un instantané récupérable dans `.ged/`
 * et laisse une entrée lisible dans le `.meta.json` de la pièce.
 */
final class EditionPieces
{
    private readonly Classeur $classeur;
    private readonly EditeurPdf $pdf;

    public function __construct(
        private readonly Config $config,
        private readonly DepotFichiers $depot,
        ?Classeur $classeur = null,
        private readonly ?int $compte = null,
        private readonly bool $administrateur = false,
    ) {
        $this->classeur = $classeur ?? Fabrique::classeur($config, Base::pdo($config));
        $this->pdf = EditeurPdf::depuisConfig($config);
    }

    public function tourner(int $id, int $page, int $degres, string $auteur): void
    {
        $piece = $this->editable($id);
        $instantane = $this->instantane($id, [$piece]);
        $sortie = $this->temporaire('tourne');
        try {
            $this->pdf->executer(['action' => 'turn', 'source' => $this->chemin($piece), 'output' => $sortie,
                'page' => $page, 'degrees' => $degres]);
            $this->appliquer($piece, $sortie, $this->pages($piece['meta']), 'rotation', $auteur,
                'page ' . $page . ' tournée de ' . $degres . '°');
            $this->finir($id, $instantane, 'rotation', []);
        } finally {
            @unlink($sortie);
        }
    }

    /** Scinde après la page locale demandée et rend l'identifiant de la seconde pièce. */
    public function scinder(int $id, int $apres, string $auteur): int
    {
        $piece = $this->editable($id);
        $pages = $this->pages($piece['meta']);
        if ($apres < 1 || $apres >= count($pages)) {
            throw new \RuntimeException('scission impossible : choisissez une page intérieure à la pièce');
        }
        $instantane = $this->instantane($id, [$piece]);
        [$gauche, $droite] = [$this->temporaire('gauche'), $this->temporaire('droite')];
        try {
            $this->pdf->executer(['action' => 'split', 'source' => $this->chemin($piece), 'left' => $gauche,
                'right' => $droite, 'after' => $apres]);
            $pagesGauche = array_slice($pages, 0, $apres);
            $pagesDroite = array_slice($pages, $apres);
            $this->appliquer($piece, $gauche, $pagesGauche, 'scission', $auteur, 'scindée après la page ' . $apres);
            $metaDroite = $this->metaEditee($piece['meta'], $pagesDroite, 'scission', $auteur,
                'créée après la page ' . $apres);
            $nouvelle = $this->depot->deposer($droite, $metaDroite);
            $this->verifierPages($pages, array_merge($pagesGauche, $pagesDroite));
            $this->finir($id, $instantane, 'scission', [$nouvelle]);

            return $nouvelle;
        } finally {
            @unlink($gauche);
            @unlink($droite);
        }
    }

    /** Fusionne la pièce courante puis celle choisie, du même lot, dans cet ordre. */
    public function fusionner(int $id, int $avec, string $auteur): void
    {
        if ($id === $avec) {
            throw new \RuntimeException('fusion impossible : choisissez une autre pièce du lot');
        }
        $courante = $this->editable($id);
        $suivante = $this->editable($avec);
        $lot = (int) ($courante['meta']['origine']['lot'] ?? 0);
        if ($lot <= 0 || $lot !== (int) ($suivante['meta']['origine']['lot'] ?? 0)) {
            throw new \RuntimeException('fusion impossible : les deux pièces doivent provenir du même lot');
        }
        $pagesAvant = array_merge($this->pages($courante['meta']), $this->pages($suivante['meta']));
        if (count($pagesAvant) !== count(array_unique($pagesAvant))) {
            throw new \RuntimeException('fusion impossible : invariant de découpe déjà invalide');
        }
        $instantane = $this->instantane($id, [$courante, $suivante]);
        $sortie = $this->temporaire('fusion');
        try {
            $this->pdf->executer(['action' => 'merge', 'sources' => [$this->chemin($courante), $this->chemin($suivante)],
                'output' => $sortie]);
            $this->appliquer($courante, $sortie, $pagesAvant, 'fusion', $auteur,
                'fusionnée avec la pièce ' . $avec);
            // La deuxième pièce reste récupérable avec ses annexes, mais n'est plus une pièce vivante.
            $this->depot->miseALaCorbeille($avec, $auteur);
            $this->verifierPages($pagesAvant, $pagesAvant);
            $this->finir($id, $instantane, 'fusion', []);
        } finally {
            @unlink($sortie);
        }
    }

    /** Retire une page dans une pièce PDF et retourne l'id de la page mise à la corbeille. */
    public function retirer(int $id, int $page, string $auteur): int
    {
        $piece = $this->editable($id);
        $pages = $this->pages($piece['meta']);
        if ($page < 1 || $page > count($pages)) {
            throw new \RuntimeException('retrait impossible : numéro de page hors de la pièce');
        }
        $instantane = $this->instantane($id, [$piece]);
        [$sortie, $retiree] = [$this->temporaire('sans-page'), $this->temporaire('page-retiree')];
        try {
            $this->pdf->executer(['action' => 'remove', 'source' => $this->chemin($piece), 'output' => $sortie,
                'removed' => $retiree, 'page' => $page]);
            $pageSource = $pages[$page - 1];
            $pagesRestantes = $pages;
            array_splice($pagesRestantes, $page - 1, 1);
            $this->appliquer($piece, $sortie, $pagesRestantes, 'retrait', $auteur, 'page ' . $page . ' retirée');
            $metaRetiree = $this->metaEditee($piece['meta'], [$pageSource], 'retrait', $auteur,
                'page retirée de la pièce ' . $id);
            $metaRetiree['titre'] = 'Page retirée — ' . (string) ($piece['meta']['titre'] ?? 'pièce');
            $pageId = $this->depot->deposer($retiree, $metaRetiree);
            $this->depot->miseALaCorbeille($pageId, $auteur);
            $this->verifierPages($pages, array_merge($pagesRestantes, [$pageSource]));
            $this->finir($id, $instantane, 'retrait', [$pageId]);

            return $pageId;
        } finally {
            @unlink($sortie);
            @unlink($retiree);
        }
    }

    /** Annule uniquement la dernière opération non validée de cette pièce. */
    public function annuler(int $id, string $auteur): void
    {
        $this->editable($id);
        $pile = $this->lirePile($id);
        $operation = array_pop($pile);
        if (!is_array($operation) || !is_array($operation['instantane']['documents'] ?? null)) {
            throw new \RuntimeException('annulation impossible : aucune opération de page à annuler');
        }
        foreach ($operation['instantane']['documents'] as $instantane) {
            if (!is_array($instantane)) {
                continue;
            }
            $document = $this->depot->fiche((int) ($instantane['id'] ?? 0));
            if ($document === null) {
                throw new \RuntimeException('annulation impossible : pièce d’origine introuvable');
            }
            if ($document['supprime_le'] !== null) {
                $this->depot->restaurer((int) $document['id']);
                $document = $this->depot->fiche((int) $document['id']);
            }
            $fichier = (string) ($instantane['fichier'] ?? '');
            if (!is_file($fichier)) {
                throw new \RuntimeException('annulation impossible : instantané de page introuvable');
            }
            $meta = is_array($instantane['meta'] ?? null) ? $instantane['meta'] : [];
            $meta['historique_pages'][] = $this->entreeHistorique('annulation', $auteur,
                'annulation de ' . (string) ($operation['type'] ?? 'l’opération'));
            // Le rangement (et son contrôle d'arrivée, F10) est posé AVANT de toucher au contenu : un refus
            // de droits ne laisse ni fichier remplacé ni version archivée.
            $this->depot->mettreAJour((int) $document['id'], $meta, $auteur, $this->compte, $this->administrateur);
            $this->depot->remplacerContenu((int) $document['id'], $fichier, $auteur);
        }
        // Une scission crée une seconde pièce : après remise de l'original, elle ne peut pas rester
        // vivante, sinon ses pages seraient comptées deux fois. Elle reste, elle aussi, récupérable.
        foreach ((array) ($operation['crees'] ?? []) as $cree) {
            $document = $this->depot->fiche((int) $cree);
            if ($document !== null && $document['supprime_le'] === null) {
                $this->depot->miseALaCorbeille((int) $document['id'], $auteur);
            }
        }
        $this->ecrirePile($id, $pile);
    }

    /** @return array<string, mixed> */
    private function editable(int $id): array
    {
        $piece = $this->depot->fiche($id);
        if ($piece === null || $piece['supprime_le'] !== null) {
            throw new \RuntimeException('édition impossible : pièce introuvable ou à la corbeille');
        }
        if ((string) ($piece['statut'] ?? '') !== 'a_controler') {
            throw new \RuntimeException('édition impossible : la pièce est déjà validée');
        }
        $chemin = $this->chemin($piece);
        if (!is_file($chemin) || strtolower(pathinfo($chemin, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \RuntimeException('édition impossible : seule une pièce PDF peut être retouchée');
        }
        if (Scellement::estActif(Scellement::lire($chemin, $id))) {
            throw new \RuntimeException('édition impossible : la pièce est scellée');
        }

        return $piece;
    }

    /** @param array<string, mixed> $piece */
    private function chemin(array $piece): string
    {
        return $this->depot->cheminAbsolu((string) $piece['chemin_relatif']);
    }

    /**
     * @param array<string, mixed> $meta
     * @return list<int>
     */
    private function pages(array $meta): array
    {
        $liste = $meta['origine']['pages_liste'] ?? null;
        if (is_array($liste) && $liste !== []) {
            return array_values(array_map('intval', $liste));
        }
        $pages = [];
        foreach (explode(',', (string) ($meta['origine']['pages'] ?? '')) as $plage) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', trim($plage), $m) !== 1) {
                continue;
            }
            $debut = (int) $m[1];
            $fin = isset($m[2]) ? (int) $m[2] : $debut;
            foreach (range($debut, $fin) as $numero) {
                $pages[] = $numero;
            }
        }
        if ($pages === []) {
            throw new \RuntimeException('édition impossible : origine des pages absente');
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $piece
     * @param list<int> $pages
     */
    private function appliquer(array $piece, string $fichier, array $pages, string $operation, string $auteur, string $detail): void
    {
        // F10 : le rangement est posé AVANT le contenu. Si le compte n'a pas le droit de déposer dans le
        // dossier d'arrivée, le service refuse et NE TOUCHE À RIEN (ni fichier, ni version, ni meta).
        $this->depot->mettreAJour((int) $piece['id'], $this->metaEditee($piece['meta'], $pages, $operation, $auteur, $detail),
            $auteur, $this->compte, $this->administrateur);
        $this->depot->remplacerContenu((int) $piece['id'], $fichier, $auteur);
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<int> $pages
     * @return array<string, mixed>
     */
    private function metaEditee(array $meta, array $pages, string $operation, string $auteur, string $detail): array
    {
        $origine = is_array($meta['origine'] ?? null) ? $meta['origine'] : [];
        $origine['pages_liste'] = $pages;   // déjà une list<int> (paramètre annoté) : array_values était neutre
        $origine['pages'] = Travaux::plages($pages);
        $proposition = $this->classeur->proposer((string) ($meta['texte'] ?? ''), [], (string) ($meta['titre'] ?? 'piece.pdf'));
        foreach (['titre', 'type', 'emetteur', 'date', 'montant', 'etiquettes', 'raison'] as $cle) {
            $meta[$cle] = $proposition[$cle] ?? null;
        }
        // Le contrat Classeur garantit `source` (string) et `confiance` (float) : aucun repli nécessaire.
        $meta['classement'] = ['source' => $proposition['source'], 'confiance' => $proposition['confiance'],
            'preuve' => $proposition['preuve'] ?? null];
        $meta['statut'] = 'a_controler';
        $meta['origine'] = $origine;
        $meta['historique_pages'][] = $this->entreeHistorique($operation, $auteur, $detail);

        return $meta;
    }

    /** @return array{operation:string,le:string,auteur:string,detail:string} */
    private function entreeHistorique(string $operation, string $auteur, string $detail): array
    {
        return ['operation' => $operation, 'le' => date('c'), 'auteur' => $auteur, 'detail' => $detail];
    }

    /**
     * @param list<int> $attendues
     * @param list<int> $obtenues
     */
    private function verifierPages(array $attendues, array $obtenues): void
    {
        sort($attendues);
        sort($obtenues);
        if ($attendues !== $obtenues || count($obtenues) !== count(array_unique($obtenues))) {
            throw new \RuntimeException('édition impossible : invariant de découpe (page perdue ou dupliquée)');
        }
    }

    /**
     * @param list<array<string, mixed>> $pieces
     * @return array<string, mixed>
     */
    private function instantane(int $id, array $pieces): array
    {
        $dossier = $this->dossier($id) . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('édition impossible : instantané non inscriptible');
        }
        $documents = [];
        foreach ($pieces as $piece) {
            $copie = $dossier . '/' . (int) $piece['id'] . '.pdf';
            if (!copy($this->chemin($piece), $copie)) {
                throw new \RuntimeException('édition impossible : copie de sécurité impossible');
            }
            $documents[] = ['id' => (int) $piece['id'], 'fichier' => $copie, 'meta' => $piece['meta']];
        }

        return ['documents' => $documents];
    }

    /**
     * @param array<string, mixed> $instantane
     * @param list<int> $crees
     */
    private function finir(int $id, array $instantane, string $type, array $crees): void
    {
        $pile = $this->lirePile($id);
        $pile[] = ['type' => $type, 'instantane' => $instantane, 'crees' => $crees];
        $this->ecrirePile($id, $pile);
    }

    /** @return list<array<string, mixed>> */
    private function lirePile(int $id): array
    {
        $fichier = $this->dossier($id) . '/pile.json';
        if (!is_file($fichier)) {
            return [];
        }
        $pile = json_decode((string) file_get_contents($fichier), true);

        return is_array($pile) ? $pile : [];
    }

    /** @param list<array<string, mixed>> $pile */
    private function ecrirePile(int $id, array $pile): void
    {
        $dossier = $this->dossier($id);
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('édition impossible : journal non inscriptible');
        }
        file_put_contents($dossier . '/pile.json', json_encode($pile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    private function dossier(int $id): string
    {
        return $this->depot->racine() . '/.ged/editions-pages/' . $id;
    }

    private function temporaire(string $nom): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'gedv2-' . $nom . '-');
        if ($fichier === false) {
            throw new \RuntimeException('édition impossible : espace temporaire indisponible');
        }

        $sortie = $fichier . '.pdf';
        @unlink($fichier); // seul le fichier temporaire vide est retiré, jamais une donnée utilisateur

        return $sortie;
    }
}
