<?php

declare(strict_types=1);

namespace Ged\DepotFileserver;

use Ged\Config;

/**
 * VEILLE du dépôt file-server : compare le dernier manifeste d'empreintes au scan courant et
 * détecte ce qui a été fait HORS GED (source de vérité : le dossier).
 *
 * - un fichier MODIFIÉ hors application → son nouveau contenu est versionné (dossier masqué
 *   `.<fichier>` ET dépôt séparé) ; les contenus précédents restent intacts ;
 * - un fichier DÉPLACÉ ou RENOMMÉ hors application → reconnu par son empreinte (on retrouve où il
 *   est parti) et tracé dans le journal ;
 * - un fichier SUPPRIMÉ hors application → la ligne reste au manifeste, datée `absent_depuis`
 *   (zéro suppression), et son contenu reste garanti dans le dépôt séparé ;
 * - un fichier NOUVEAU → garanti et versionné (v001).
 *
 * Tout est écrit dans `.depot-fileserver/` du dépôt (manifeste, journal, dernier relevé) : le
 * dossier fait foi, aucun index en base. Portable Windows/Linux.
 */
final class Veille
{
    public function __construct(
        private readonly string $racine,
        private readonly DepotSepare $depotSepare,
        private readonly Horloge $horloge,
    ) {
    }

    public static function depuisConfig(Config $config): self
    {
        return new self(
            $config->chemin('STORAGE_ROOT', 'storage/documents'),
            new DepotSepare($config->chemin('DEPOT_SEPARE', 'var/depot-separe')),
            new HorlogeSysteme(),
        );
    }

    public function racine(): string
    {
        return rtrim(str_replace('\\', '/', $this->racine), '/');
    }

    /**
     * Un passage : scan, garantie, versions, journal. Court et reprenable (aucun état bloquant).
     *
     * @return array{fichiers:int, nouveaux:int, modifies:int, deplaces:int, supprimes:int, restaures:int,
     *               versions:int, garantis:int, instant:string}
     */
    public function passer(): array
    {
        $manifeste = new Manifeste($this->racine());
        $precedent = $manifeste->lire()['fichiers'];
        $scan = (new Scanner($this->racine()))->scan();
        $instant = $this->horloge->maintenant();
        $versions = new Versions();

        $stats = ['fichiers' => count($scan), 'nouveaux' => 0, 'modifies' => 0, 'deplaces' => 0,
            'supprimes' => 0, 'restaures' => 0, 'versions' => 0, 'garantis' => 0, 'instant' => $instant];
        $evenements = [];
        /** @var array<string, array<string,mixed>> $fichiers */
        $fichiers = [];
        $consommes = [];

        $parEmpreinte = [];
        foreach ($precedent as $relatifAncien => $ancien) {
            $empreinteAncienne = (string) ($ancien['empreinte'] ?? '');
            if ($empreinteAncienne !== '') {
                $parEmpreinte[$empreinteAncienne][] = $relatifAncien;
            }
        }

        // 1. Fichiers présents : garantir, versionner, classer (nouveau / déplacé / modifié / intact).
        foreach ($scan as $relatif => $info) {
            $empreinte = $info['empreinte'];
            $ancien = $precedent[$relatif] ?? null;
            $etaitAbsent = is_array($ancien) && ($ancien['etat'] ?? 'present') === 'absent';

            if (!$versions->contient($info['absolu'], $empreinte)) {
                $versions->archiver($info['absolu'], $versions->numeroSuivant($info['absolu']), $empreinte);
                ++$stats['versions'];
            }
            $this->depotSepare->conserver($info['absolu'], $empreinte, $relatif, $this->meta($info['absolu']), $instant);
            ++$stats['garantis'];

            if ($ancien === null) {
                $source = $this->sourceDeplacee($parEmpreinte, $empreinte, $relatif, $scan, $consommes);
                if ($source !== null) {
                    $consommes[$source] = true;
                    $evenements[] = $this->evenement('deplace', $relatif, $empreinte, $instant, $source);
                    ++$stats['deplaces'];
                } else {
                    $evenements[] = $this->evenement('nouveau', $relatif, $empreinte, $instant, null);
                    ++$stats['nouveaux'];
                }
            } elseif ($etaitAbsent) {
                $evenements[] = $this->evenement('restaure', $relatif, $empreinte, $instant, null);
                ++$stats['restaures'];
            } elseif ((string) ($ancien['empreinte'] ?? '') !== $empreinte) {
                $evenements[] = $this->evenement('modifie', $relatif, $empreinte, $instant, null, (string) ($ancien['empreinte'] ?? ''));
                ++$stats['modifies'];
            }

            $fichiers[$relatif] = ['empreinte' => $empreinte, 'taille' => $info['taille'],
                'vu_le' => $instant, 'etat' => 'present', 'absent_depuis' => null];
        }

        // 2. Fichiers disparus : DATÉS, jamais effacés (et jamais re-signalés au passage suivant).
        foreach ($precedent as $relatif => $ancien) {
            if (isset($scan[$relatif]) || isset($consommes[$relatif])) {
                continue;
            }
            if (($ancien['etat'] ?? 'present') === 'absent') {
                $fichiers[$relatif] = $ancien;
                continue;
            }
            $evenements[] = $this->evenement('supprime', $relatif, (string) ($ancien['empreinte'] ?? ''), $instant, null);
            ++$stats['supprimes'];
            $fichiers[$relatif] = array_merge($ancien, ['etat' => 'absent', 'absent_depuis' => $instant]);
        }

        // 3. Sources d'un déplacement : gardées au manifeste, datées absentes.
        foreach (array_keys($consommes) as $relatifSource) {
            $fichiers[$relatifSource] = array_merge($precedent[$relatifSource] ?? [], ['etat' => 'absent', 'absent_depuis' => $instant]);
        }

        $manifeste->ecrire($fichiers, $instant);
        $this->journaliser($evenements, $instant);

        return $stats;
    }

    /**
     * Vérifie la garantie : chaque empreinte du manifeste a-t-elle une copie dans le dépôt séparé ?
     *
     * @return array{verifies:int, manquants:list<string>}
     */
    public function verifierGarantie(): array
    {
        $fichiers = (new Manifeste($this->racine()))->lire()['fichiers'];
        $manquants = [];
        foreach ($fichiers as $relatif => $entree) {
            $empreinte = (string) ($entree['empreinte'] ?? '');
            if ($empreinte !== '' && !$this->depotSepare->aCopie($empreinte)) {
                $manquants[] = (string) $relatif;
            }
        }

        return ['verifies' => count($fichiers), 'manquants' => $manquants];
    }

    /**
     * Derniers événements du journal, du plus récent au plus ancien.
     *
     * @return list<array<string,mixed>>
     */
    public function journal(int $limite = 100): array
    {
        $fichier = $this->racine() . '/' . Arborescence::INTERNE . '/journal.ndjson';
        if (!is_file($fichier)) {
            return [];
        }
        $lignes = file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $evenements = [];
        foreach (array_slice($lignes, -$limite) as $ligne) {
            $decode = json_decode($ligne, true);
            if (is_array($decode)) {
                $evenements[] = $decode;
            }
        }

        return array_reverse($evenements);
    }

    /** @return array<string,mixed> */
    public function dernierReleve(): array
    {
        $fichier = $this->racine() . '/' . Arborescence::INTERNE . '/dernier-releve.json';
        if (!is_file($fichier)) {
            return [];
        }
        $donnees = json_decode((string) file_get_contents($fichier), true);

        return is_array($donnees) ? $donnees : [];
    }

    /**
     * @param array<string, list<string>> $parEmpreinte
     * @param array<string, array{absolu:string, taille:int, empreinte:string}> $scan
     * @param array<string, bool> $consommes
     */
    private function sourceDeplacee(array $parEmpreinte, string $empreinte, string $relatif, array $scan, array $consommes): ?string
    {
        foreach ($parEmpreinte[$empreinte] ?? [] as $source) {
            if ($source !== $relatif && !isset($scan[$source]) && !isset($consommes[$source])) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function evenement(string $type, string $chemin, string $empreinte, string $instant, ?string $ancienChemin, ?string $ancienneEmpreinte = null): array
    {
        $evenement = ['instant' => $instant, 'type' => $type, 'chemin' => $chemin, 'empreinte' => $empreinte];
        if ($ancienChemin !== null) {
            $evenement['ancien_chemin'] = $ancienChemin;
        }
        if ($ancienneEmpreinte !== null) {
            $evenement['ancienne_empreinte'] = $ancienneEmpreinte;
        }

        return $evenement;
    }

    /**
     * @param list<array<string,mixed>> $evenements
     */
    private function journaliser(array $evenements, string $instant): void
    {
        $dossier = $this->racine() . '/' . Arborescence::INTERNE;
        Arborescence::assurerDossier($dossier);
        if ($evenements !== []) {
            $lignes = '';
            foreach ($evenements as $evenement) {
                $lignes .= json_encode($evenement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            if (file_put_contents($dossier . '/journal.ndjson', $lignes, FILE_APPEND) === false) {
                throw new \RuntimeException('veille : journal non écrit dans ' . $dossier);
            }
        }
        $resume = ['instant' => $instant, 'evenements' => count($evenements)];
        if (file_put_contents($dossier . '/dernier-releve.json', Arborescence::json($resume, true) . "\n") === false) {
            throw new \RuntimeException('veille : dernier relevé non écrit dans ' . $dossier);
        }
    }

    /** @return array<string,mixed> métadonnées du `.meta.json` voisin, ou le nom en titre. */
    private function meta(string $absolu): array
    {
        $fichier = $absolu . '.meta.json';
        if (is_file($fichier)) {
            $decode = json_decode((string) file_get_contents($fichier), true);
            if (is_array($decode)) {
                return $decode;
            }
        }

        return ['titre' => pathinfo($absolu, PATHINFO_FILENAME)];
    }
}
