<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Config;
use Ged\Contrats\Classeur;
use Ged\Contrats\Depot;

/**
 * Un « tic » d'ingestion : relever les sources, découper les lots reçus, classer et ranger les pièces prêtes.
 * Budget de temps borné (hébergement mutualisé : jamais un appel PHP long) ; ce qui n'est pas fini l'est au tic
 * suivant. Chaque étape est reprise si une tâche est tuée : les verrous sont courts et expirent.
 */
final class Pilote
{
    /** @param list<Source> $sources ; $depot null = socle absent : les pièces prêtes attendent, rien ne se perd */
    public function __construct(
        private readonly Travaux $travaux,
        private readonly array $sources,
        private readonly Decoupeur $decoupeur,
        private readonly ?Depot $depot,
        private readonly ?Classeur $classeur,
        private readonly string $zoneTravail,
        private readonly ?Config $config = null,
    ) {
    }

    /** @return array{recus:int, doublons:int, decoupes:int, ranges:int, erreurs:int, alertes:list<string>} */
    public function tic(int $budgetS = 20): array
    {
        $fin = microtime(true) + $budgetS;
        $compte = ['recus' => 0, 'doublons' => 0, 'decoupes' => 0, 'ranges' => 0, 'erreurs' => 0, 'alertes' => []];

        foreach ($this->sources as $source) {
            try {
                $entrees = $source->relever();
            } catch (\Throwable $e) {
                // Une boîte injoignable n'arrête ni le dossier ni les découpes en cours.
                $compte['alertes'][] = (new \ReflectionClass($source))->getShortName() . ' : ' . $e->getMessage();
                continue;
            }
            foreach ($entrees as $entree) {
                if ($this->travaux->recevoir($entree) !== null) {
                    $compte['recus']++;
                } else {
                    // Déjà reçu : on garde le fichier de côté (jamais supprimé), on ne le traite pas deux fois.
                    $this->mettreDeCote($entree->chemin, 'doublons');
                    $compte['doublons']++;
                }
            }
        }

        // Ranger d'abord ce qui est prêt (rapide), puis découper un lot (long).
        while ($this->depot !== null && microtime(true) < $fin && ($t = $this->travaux->reserver('pret')) !== null) {
            try {
                $this->ranger($t);
                $compte['ranges']++;
            } catch (\Throwable $e) {
                $this->travaux->echouer((int) $t['id'], 'rangement : ' . $e->getMessage());
                $compte['erreurs']++;
            }
        }
        if (microtime(true) < $fin && ($t = $this->travaux->reserver('recu', 30)) !== null) {
            try {
                $compte['alertes'] = array_merge($compte['alertes'], $this->decouper($t));
                $compte['decoupes']++;
            } catch (\Throwable $e) {
                $this->travaux->echouer((int) $t['id'], 'découpe : ' . $e->getMessage());
                $compte['erreurs']++;
            }
        }

        return $compte;
    }

    /**
     * @param array<string, mixed> $lot
     * @return list<string> alertes d'invariant réparé
     */
    private function decouper(array $lot): array
    {
        $sortie = $this->zoneTravail . '/pieces/' . $lot['id'];
        $normalisee = $this->verifierDecoupe(
            $this->decoupeur->decouper((string) $lot['chemin'], $sortie),
            (string) $lot['chemin'],
        );
        $pieces = $normalisee['pieces'];
        if ($pieces === []) {
            throw new \RuntimeException('le moteur n’a rendu aucune pièce');
        }
        $base = pathinfo((string) $lot['nom_origine'], PATHINFO_FILENAME);
        // Origine CMM (jonctions) déposée à côté de la copie de travail : elle suit chaque pièce
        // jusqu'au `.meta.json` du document (ajout minimal V2-08b, signalé dans la fiche Ingestion).
        $origine = $this->origineCourriel($lot);
        foreach ($pieces as $p) {
            $nom = $base . (count($pieces) > 1 ? ' (p. ' . Travaux::plages($p['pages']) . ')' : '') . '.pdf';
            $analyse = $p['analyse'];
            if ($origine !== null) {
                $analyse['origine_courriel'] = $origine;
            }
            $this->travaux->ajouterPiece((int) $lot['id'], $p['fichier'], $nom, (string) $lot['source'], $p['pages'], $p['texte'], $analyse);
        }
        $this->travaux->passer((int) $lot['id'], 'decoupe');

        return $normalisee['alertes'];
    }

    /**
     * Origine CMM accompagnant la copie de travail (`<fichier>.depot.json`), ou null.
     *
     * @param array<string, mixed> $lot
     * @return array<string, mixed>|null
     */
    private function origineCourriel(array $lot): ?array
    {
        $fichier = (string) ($lot['chemin'] ?? '') . '.depot.json';
        if (!is_file($fichier)) {
            return null;
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);
        $origine = is_array($donnees) ? ($donnees['metadonnees']['origine'] ?? null) : null;

        return is_array($origine) ? $origine : null;
    }

    /** @param array<string, mixed> $piece */
    private function ranger(array $piece): void
    {
        $analyse = json_decode((string) $piece['analyse'], true) ?: [];
        $texte = (string) ($analyse['texte'] ?? '');
        unset($analyse['texte']);
        // Origine CMM posée à la découpe : elle repart dans le `.meta.json`, sous `origine.courriel`.
        $origineCourriel = is_array($analyse['origine_courriel'] ?? null) ? $analyse['origine_courriel'] : null;
        unset($analyse['origine_courriel']);

        $proposition = $this->classeur?->proposer($texte, $analyse, (string) $piece['nom_origine']);
        $meta = [
            'titre' => $proposition['titre'] ?? pathinfo((string) $piece['nom_origine'], PATHINFO_FILENAME),
            'source' => $piece['source'],
            'origine' => ['travail' => (int) $piece['id'], 'lot' => (int) $piece['parent_id'], 'pages' => $piece['pages']],
            'texte' => $texte,
            'statut' => ($proposition['valide'] ?? false) ? 'valide' : 'a_controler',
        ];
        if ($origineCourriel !== null) {
            $meta['origine']['courriel'] = $origineCourriel;
        }
        if ($proposition !== null) {
            foreach (['type', 'emetteur', 'date', 'montant', 'etiquettes', 'raison'] as $cle) {
                $meta[$cle] = $proposition[$cle] ?? null;
            }
            $meta['classement'] = ['source' => $proposition['source'], 'confiance' => $proposition['confiance'], 'preuve' => $proposition['preuve']];
        }
        $id = $this->depot?->deposer((string) $piece['chemin'], $meta);
        $this->travaux->passer((int) $piece['id'], 'range', $id);
        // Le dépôt a réussi : les workflows réagissent à la fiche canonique sans jamais bloquer l'ingestion.
        if ($this->config !== null && $id !== null && class_exists(\Ged\Workflows\Ecouteur::class)) {
            \Ged\Workflows\Ecouteur::prevenir($this->config, 'document_depose', $id, $meta);
        }
        // Copie d'archivage PDF/A-2b à côté du document rangé (lot ged-pdfa-1) : un plus pour la
        // consultation longue durée, jamais une condition du dépôt. Appel d'une ligne signalé.
        if ($id !== null) {
            $this->copieArchivage($id);
        }
        if ((int) $piece['parent_id'] > 0) {
            $this->travaux->clore((int) $piece['parent_id']);
        }
    }

    /**
     * Produit la copie d'archivage PDF/A-2b du document qui vient d'être rangé. Un échec est enregistré
     * dans le `.meta.json` (statut « echec » et raison) et tracé, jamais propagé : le document est déjà
     * ingéré et le reste. Sans configuration (oracles), la copie est laissée au rattrapage.
     */
    private function copieArchivage(int $idDocument): void
    {
        if ($this->config === null || $this->depot === null) {
            return;
        }
        try {
            $fiche = $this->depot->fiche($idDocument);
            $relatif = is_array($fiche) ? (string) ($fiche['chemin_relatif'] ?? '') : '';
            if ($relatif === '') {
                return;
            }
            CopieArchivage::depuis($this->config)->pour($this->depot->cheminAbsolu($relatif), $relatif, $idDocument);
        } catch (\Throwable $e) {
            error_log(sprintf('[GEDv2 pdfa] document %d : copie non produite — %s', $idDocument, $e->getMessage()));
        }
    }

    private function mettreDeCote(string $chemin, string $sousDossier): void
    {
        $cible = $this->zoneTravail . '/' . $sousDossier;
        if (!is_dir($cible)) {
            mkdir($cible, 0775, true);
        }
        @rename($chemin, $cible . '/' . basename($chemin));
    }

    /**
     * Vision peut exceptionnellement rendre une frontière deux fois. Le moteur ClearMyDocs reste la
     * source de découpe, mais la GED garantit son propre invariant : une page d'un lot n'entre qu'une
     * fois dans les métadonnées des pièces. Une pièce entièrement redondante est ignorée ; si elle porte
     * aussi des pages inédites, elles restent dans la chaîne (jamais de page perdue).
     *
     * @param list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string,mixed>}> $pieces
     * @return list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string,mixed>}>
     */
    private function dedoublonnerPages(array $pieces): array
    {
        // On décide d'abord l'affectation de chaque page. Un parcours « première pièce gagnante »
        // laisse une frontière Vision dans la mauvaise pièce lorsque la seconde est la seule dont le
        // texte porte réellement le marqueur de page.
        $gagnante = [];
        foreach ($pieces as $index => $piece) {
            foreach ($piece['pages'] as $page) {
                $page = (int) $page;
                if ($page <= 0) {
                    continue;
                }
                $score = $this->scoreLecturePage((string) $piece['texte'], $page);
                if (!isset($gagnante[$page]) || $score > $gagnante[$page]['score']) {
                    // À score égal, le premier rendu reste la solution déterministe de repli.
                    $gagnante[$page] = ['index' => $index, 'score' => $score];
                }
            }
        }

        $uniques = [];
        foreach ($pieces as $index => $piece) {
            $pages = [];
            foreach ($piece['pages'] as $page) {
                $page = (int) $page;
                if ($page > 0 && ($gagnante[$page]['index'] ?? null) === $index) {
                    $pages[] = $page;
                }
            }
            if ($pages === []) {
                continue;
            }
            sort($pages);
            $piece['pages'] = $pages;
            $uniques[] = $piece;
        }

        return $uniques;
    }

    /**
     * Porte R2g : après TOUTE sortie du moteur, l'inventaire des pages source est complet et disjoint.
     * La Vision ne décide donc jamais seule qu'une frontière existe : un doublon est attribué de façon
     * déterministe, une lacune est rattachée à la pièce qui prolonge sa séquence, et l'opérateur est averti.
     *
     * @param list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string,mixed>}> $pieces
     * @return array{pieces:list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string,mixed>}>, alertes:list<string>}
     */
    private function verifierDecoupe(array $pieces, string $fichierSource): array
    {
        $brutes = [];
        foreach ($pieces as $piece) {
            $brutes = array_merge($brutes, array_map('intval', $piece['pages']));
        }
        $brutes = array_values(array_filter($brutes, static fn (int $page): bool => $page > 0));
        $attendues = $this->compterPagesSource($fichierSource);
        if ($brutes === []) {
            throw new \RuntimeException('invariant de découpe : aucune page source vérifiable');
        }

        $uniques = $this->dedoublonnerPages($pieces);
        $vues = [];
        foreach ($uniques as $index => $piece) {
            foreach ($piece['pages'] as $page) {
                $vues[(int) $page] = $index;
            }
        }
        $manquantes = $attendues === null ? [] : array_values(array_diff(range(1, $attendues), array_keys($vues)));
        foreach ($manquantes as $page) {
            $index = $this->pieceQuiProlonge($uniques, $page);
            if ($index === null) {
                throw new \RuntimeException('invariant de découpe : page ' . $page . ' sans pièce à prolonger');
            }
            $uniques[$index]['pages'][] = $page;
            sort($uniques[$index]['pages']);
        }

        // Assertion finale, volontairement redondante : jamais un correctif partiel ne peut passer silencieusement.
        $finales = [];
        foreach ($uniques as $piece) {
            foreach ($piece['pages'] as $page) {
                $finales[] = (int) $page;
            }
        }
        sort($finales);
        $attenduFinal = $attendues === null ? array_values(array_unique($brutes)) : range(1, $attendues);
        sort($attenduFinal);
        if ($finales !== $attenduFinal) {
            throw new \RuntimeException('invariant de découpe violé après correction');
        }

        $doublons = count($brutes) - count(array_unique($brutes));
        $alertes = [];
        if ($doublons > 0 || $manquantes !== []) {
            $alertes[] = 'invariant de découpe réparé : ' . $doublons . ' page(s) double(s), '
                . implode(',', $manquantes ?: []) . ' page(s) rattachée(s) à la séquence adjacente';
        }

        return ['pieces' => $uniques, 'alertes' => $alertes];
    }

    /** Compte natif du PDF ; le repli lit les dictionnaires Page des petits PDF de test. */
    private function compterPagesSource(string $fichier): ?int
    {
        $commande = ['pdfinfo', $fichier];
        $processus = @proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux);
        if (is_resource($processus)) {
            $sortie = (string) stream_get_contents($tuyaux[1]);
            fclose($tuyaux[1]);
            fclose($tuyaux[2]);
            if (proc_close($processus) === 0 && preg_match('/^Pages:\s*(\d+)\s*$/mi', $sortie, $m) === 1) {
                return max(1, (int) $m[1]);
            }
        }
        $brut = @file_get_contents($fichier);
        if (is_string($brut) && preg_match_all('/\/Type\s*\/Page\b/', $brut, $pages) > 0) {
            return count($pages[0]);
        }

        return null;
    }

    /**
     * La page absente prolonge la séquence gauche ; sans gauche, la droite.
     *
     * @param list<array{fichier:string, pages:list<int>, texte:string, analyse:array<string,mixed>}> $pieces
     */
    private function pieceQuiProlonge(array $pieces, int $page): ?int
    {
        foreach ($pieces as $index => $piece) {
            if (in_array($page - 1, $piece['pages'], true)) {
                return $index;
            }
        }
        foreach ($pieces as $index => $piece) {
            if (in_array($page + 1, $piece['pages'], true)) {
                return $index;
            }
        }

        return null;
    }

    /** Le texte de sortie de CMD4 indique parfois explicitement la page OCR (« Page 86 », « p. 86 »). */
    private function scoreLecturePage(string $texte, int $page): int
    {
        return preg_match('/(?:^|\\R|\\b)(?:page|p\\.)\\s*[:#-]?\\s*' . preg_quote((string) $page, '/') . '\\b/iu', $texte) === 1
            ? 1 : 0;
    }
}
