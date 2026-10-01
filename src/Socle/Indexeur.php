<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Base;
use Ged\Config;
use PDO;

/**
 * Un passage de l'indexeur sur le fonds : la base suit le dossier, jamais l'inverse.
 *
 * - un fichier modifié hors application (explorateur, logiciel tiers) → le nouveau contenu est archivé
 *   comme version N+1 dans `.versions/`, les précédentes restent intactes ;
 * - un dossier déplacé ou renommé hors application → chaque document est reconnu par son empreinte et la
 *   ligne d'index LE SUIT (même identifiant, déplacement tracé) ;
 * - un fichier posé à la main → indexé, avec son `.meta.json` s'il en a un ;
 * - un document disparu sans trace → la ligne reste, datée `absent_depuis` (zéro suppression).
 *
 * Comme le dossier surveillé d'Ingestion, un fichier n'est traité qu'une fois sa signature (taille, date)
 * stable sur deux relevés : un scanner qui écrit encore n'est jamais lu à moitié. Mémoire des relevés :
 * `.index/etat.json` sous la racine (dossier interne, jamais un document).
 */
final class Indexeur
{
    private readonly DepotFichiers $depot;
    private readonly PDO $pdo;
    private readonly string $racine;

    public function __construct(Config $config)
    {
        $this->depot = new DepotFichiers($config);
        $this->pdo = Base::pdo($config);
        $this->racine = $this->depot->racine();
    }

    /**
     * @param int $budgetS borné : en mutualisé, jamais un appel PHP long ; ce qui n'est pas fini l'est au
     *                    passage suivant (`complet` dit si le fonds a été vu en entier).
     * @return array{complet:bool, nouveaux:int, versions:int, deplaces:int, metas:int, intouches:int, absents:int, scelles_alteres:int}
     */
    public function passer(int $budgetS = 20): array
    {
        $fin = microtime(true) + $budgetS;
        $stats = ['complet' => true, 'nouveaux' => 0, 'versions' => 0, 'deplaces' => 0, 'metas' => 0, 'intouches' => 0, 'absents' => 0, 'scelles_alteres' => 0];

        [$vus, $stables] = $this->relever($fin);
        $stats['complet'] = microtime(true) <= $fin;

        // L'état actuel de l'index, en mémoire : un fonds tient dans une passe de l'ordre du millier de documents.
        $lignes = [];
        foreach ($this->pdo->query('SELECT id, chemin_relatif, empreinte, taille, mime, meta, supprime_le, absent_depuis'
            . ' FROM documents')->fetchAll() as $l) {
            $lignes[(int) $l['id']] = $l;
        }
        $parChemin = array_column($lignes, null, 'chemin_relatif');
        $consumes = [];

        ksort($stables);
        foreach ($stables as $relatif => [$absolu, $empreinte, $taille]) {
            $existant = $parChemin[$relatif] ?? null;

            // Le document est déjà indexé à cette place : rien, une version (contenu changé), ou des
            // métadonnées à suivre (`.meta.json` édité hors application, retour de corbeille à la main).
            if ($existant !== null) {
                $id = (int) $existant['id'];
                // Un document déjà indexé (fonds repris d'une version antérieure) doit lui aussi avoir
                // son dossier de droits : l'indexeur rattrape le rattachement manquant à chaque passage.
                $this->rattacherAuxDroits($relatif);
                $contenuChange = $existant['empreinte'] !== $empreinte;
                $metaFichier = $this->lireMeta($absolu);
                $metaChange = $metaFichier !== null && Fichiers::json($metaFichier) !== (string) $existant['meta'];
                $revient = $existant['supprime_le'] !== null || $existant['absent_depuis'] !== null;

                if ($contenuChange) {
                    // Archivage légal (V2-09) : un document scellé n'a JAMAIS de nouvelle version silencieuse.
                    // L'indexeur constate l'altération, écrit l'alerte dans le dossier et laisse l'index en place.
                    // Appel d'une ligne, signalé (docs/secteurs/archivage.md, docs/secteurs/socle.md).
                    if (class_exists(\Ged\Archivage\Garde::class)
                        && \Ged\Archivage\Garde::constaterAlteration($absolu, $empreinte, $id) !== null) {
                        $stats['scelles_alteres']++;
                        continue;
                    }
                    // Le hash a changé : c'est lui qui décide qu'une version existe (règle GEDv1 du 2026-08-09).
                    $this->depot->archiverVersion($id, $absolu, $empreinte, $taille);
                    $meta = $metaFichier ?? (json_decode((string) $existant['meta'], true) ?: []);
                    [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
                    $this->pdo->prepare('UPDATE documents SET empreinte = ?, taille = ?, mime = ?, meta = ?, absent_depuis = NULL, '
                        . implode(' = ?, ', $entetes) . ' = ? WHERE id = ?')
                        ->execute([$empreinte, $taille, Fichiers::mime($absolu), Fichiers::json($meta), ...$valeurs, $id]);
                    $stats['versions']++;
                } elseif ($metaChange || $revient) {
                    $meta = $metaFichier ?? (json_decode((string) $existant['meta'], true) ?: []);
                    [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
                    $this->pdo->prepare('UPDATE documents SET meta = ?, absent_depuis = NULL, supprime_le = NULL, supprime_par = NULL, '
                        . implode(' = ?, ', $entetes) . ' = ? WHERE id = ?')
                        ->execute([Fichiers::json($meta), ...$valeurs, $id]);
                    $stats['metas']++;
                } else {
                    $stats['intouches']++;
                }
                continue;
            }

            // Fichier à une nouvelle place : un document déplacé hors application (reconnu par empreinte,
            // son ancienne place est vide) ou un vrai nouveau venu.
            $deplace = null;
            foreach ($lignes as $l) {
                if ($l['empreinte'] === $empreinte && !isset($consumes[(int) $l['id']])
                    && !isset($vus[(string) $l['chemin_relatif']]) && $l['supprime_le'] === null) {
                    $deplace = $l;
                    break;
                }
            }
            if ($deplace !== null) {
                $consumes[(int) $deplace['id']] = true;
                $this->suivreDeplacement((int) $deplace['id'], (string) $deplace['chemin_relatif'], $relatif, $absolu);
                $stats['deplaces']++;
                continue;
            }

            $meta = $this->lireMeta($absolu) ?? Fichiers::metaMinimale(basename($relatif));
            $id = $this->depot->enregistrer($relatif, $meta);
            if ($this->versions($absolu) === []) {
                $this->depot->archiverVersion($id, $absolu, $empreinte, $taille);
            }
            $stats['nouveaux']++;
        }

        // Fin de passe complète : ce qui manque du fonds est DATÉ, jamais effacé. Un passage interrompu
        // (budget épuisé) ne marque rien : il n'a pas vu tout le dossier.
        if ($stats['complet']) {
            foreach ($lignes as $l) {
                if ($l['supprime_le'] === null && $l['absent_depuis'] === null
                    && !isset($vus[(string) $l['chemin_relatif']]) && !isset($consumes[(int) $l['id']])) {
                    $this->pdo->exec('UPDATE documents SET absent_depuis = NOW() WHERE id = ' . (int) $l['id']);
                    $stats['absents']++;
                }
            }
        }

        return $stats;
    }

    /** @return array{0:array<string,true>, 1:array<string, array{string, string, int}>} vus, stables */
    private function relever(float $fin): array
    {
        $fichierEtat = $this->racine . '/.index/etat.json';
        $precedent = is_file($fichierEtat) ? (json_decode((string) file_get_contents($fichierEtat), true) ?: []) : [];
        $actuel = [];
        $vus = [];
        $stables = [];

        if (is_dir($this->racine)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->racine, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => !Fichiers::estInterne($f->getFilename()),
            ));
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                if (!$f->isFile()) {
                    continue;
                }
                $nom = $f->getFilename();
                if (str_ends_with($nom, '.meta.json')) {
                    continue;   // les métadonnées accompagnent leur fichier, elles ne sont pas un document
                }
                $absolu = str_replace('\\', '/', $f->getPathname());
                $relatif = substr($absolu, strlen($this->racine) + 1);
                $signature = $f->getSize() . ':' . $f->getMTime();
                $vus[$relatif] = true;
                $actuel[$absolu] = $signature;
                if (($precedent[$absolu] ?? null) !== $signature) {
                    continue;   // vu pour la première fois ou encore en écriture : on attend le relevé suivant
                }
                $stables[$relatif] = [$absolu, Fichiers::empreinte($absolu), (int) $f->getSize()];
                if (count($stables) % 50 === 0 && microtime(true) > $fin) {
                    break;
                }
            }
        }

        if (!is_dir(dirname($fichierEtat))) {
            mkdir(dirname($fichierEtat), 0775, true);
            Fichiers::cacher(dirname($fichierEtat));
        }
        file_put_contents($fichierEtat, Fichiers::json($actuel));

        return [$vus, $stables];
    }

    /** Le `.meta.json` d'un fichier, s'il existe et se décode ; null sinon. */
    private function lireMeta(string $absolu): ?array
    {
        if (!is_file("$absolu.meta.json")) {
            return null;
        }
        $decode = json_decode((string) file_get_contents("$absolu.meta.json"), true);

        return is_array($decode) ? $decode : null;
    }

    /** Les noms d'archives déjà présentes dans `.versions/<fichier>/` (sans les lignes d'index). */
    private function versions(string $absoluDocument): array
    {
        return array_values(array_filter(glob(Fichiers::dossierVersions($absoluDocument) . '/*') ?: [],
            static fn (string $f) => (bool) preg_match('#/v\d{3}_#', $f)));
    }

    /** La ligne suit le dossier : nouveau chemin, annexes `.versions` rapatriées si le fichier seul a bougé, trace posée. */
    private function suivreDeplacement(int $id, string $avant, string $apres, string $absoluApres): void
    {
        $absoluAvant = $this->depot->cheminAbsolu($avant);
        $versionsAvant = Fichiers::dossierVersions($absoluAvant);
        $versionsApres = Fichiers::dossierVersions($absoluApres);
        if (is_dir($versionsAvant) && !is_dir($versionsApres)) {
            // Le fichier a été renommé sans son dossier : les archives le rejoignent.
            if (!is_dir(dirname($versionsApres)) && !mkdir(dirname($versionsApres), 0775, true)) {
                throw new \RuntimeException('suivi de déplacement : impossible de créer le dossier .versions');
            }
            @rename($versionsAvant, $versionsApres);
        }
        $meta = $this->lireMeta($absoluApres) ?? null;
        $this->pdo->prepare('UPDATE documents SET chemin_relatif = ?, nom = ?, absent_depuis = NULL WHERE id = ?')
            ->execute([$apres, basename($apres), $id]);
        if ($meta !== null) {
            [$entetes, $valeurs] = Fichiers::colonnesIndex($meta);
            $this->pdo->prepare('UPDATE documents SET meta = ?, ' . implode(' = ?, ', $entetes) . ' = ? WHERE id = ?')
                ->execute([Fichiers::json($meta), ...$valeurs, $id]);
        }
        $this->pdo->prepare('INSERT INTO document_deplacements (document_id, chemin_avant, chemin_apres, cause, auteur)'
            . ' VALUES (?, ?, ?, ?, ?)')->execute([$id, $avant, $apres, 'indexeur', 'indexeur']);
        // Le document a changé de place : la nouvelle place doit avoir son dossier de droits.
        $this->rattacherAuxDroits($apres);
    }

    /**
     * Rattache un document à son dossier de droits (secteur Droits/Sécurité, lot R1) depuis son chemin
     * du fonds. Garde `class_exists` : le Socle ne dépend pas du secteur Droits, et un schéma d'ACL
     * absent ne fait jamais échouer l'indexation (le rattachement sera repris au passage suivant).
     */
    private function rattacherAuxDroits(string $cheminRelatif): void
    {
        if (!class_exists(\Ged\Securite\DroitsDossiers::class)) {
            return;
        }
        try {
            (new \Ged\Securite\DroitsDossiers($this->pdo))->assurerDossierPourChemin($cheminRelatif);
        } catch (\PDOException) {
            // Schéma d'ACL absent : le dossier fait foi, la base s'y rattrapera.
        }
    }
}
