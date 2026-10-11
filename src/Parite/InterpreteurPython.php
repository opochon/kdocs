<?php

declare(strict_types=1);

namespace Ged\Parite;

/**
 * Resolveur d'interpreteur Python 3 pour la couche 9 du harness.
 *
 * Le nom `python` du PATH peut tomber sur un talon (Microsoft Store) qui n'execute rien.
 * On valide donc chaque candidat en executant reellement un test d'import, et on ne rend
 * une commande que si elle repond « 3 ». Sans interpreteur, le juge se nomme ROUGE.
 */
final class InterpreteurPython
{
    /**
     * @return list<string> commande a executer (avec ses arguments), vide si introuvable
     */
    public static function resoudre(): array
    {
        $candidats = [];
        $env = getenv('GED_PYTHON');
        if (is_string($env) && $env !== '') {
            $candidats[] = [$env];
        }
        $candidats[] = ['python3'];
        $candidats[] = ['python'];
        $candidats[] = ['py', '-3'];
        foreach (self::emplacementsSupposes() as $chemin) {
            $candidats[] = [$chemin];
        }

        foreach ($candidats as $commande) {
            if (self::repondTrois($commande)) {
                return $commande;
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function emplacementsSupposes(): array
    {
        $motifs = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $motifs[] = 'C:\\Python3*\\python.exe';
            $local = getenv('LOCALAPPDATA');
            if (is_string($local) && $local !== '') {
                $motifs[] = $local . '\\Programs\\Python\\Python3*\\python.exe';
            }
        } else {
            $motifs[] = '/usr/bin/python3';
            $motifs[] = '/usr/local/bin/python3';
        }

        $trouves = [];
        foreach ($motifs as $motif) {
            if (str_contains($motif, '*')) {
                $correspondances = glob($motif);
                if (is_array($correspondances)) {
                    $trouves = array_merge($trouves, $correspondances);
                }
            } elseif (is_file($motif)) {
                $trouves[] = $motif;
            }
        }
        rsort($trouves);

        return array_values(array_unique($trouves));
    }

    /** @param list<string> $commande */
    private static function repondTrois(array $commande): bool
    {
        $morceaux = array_merge($commande, ['-c', 'import sys; print(sys.version_info[0])']);
        $descripteurs = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $tuyaux = [];
        $processus = @proc_open($morceaux, $descripteurs, $tuyaux);
        if (!is_resource($processus)) {
            return false;
        }
        $sortie = stream_get_contents($tuyaux[1]);
        $erreur = stream_get_contents($tuyaux[2]);
        fclose($tuyaux[1]);
        fclose($tuyaux[2]);
        $code = proc_close($processus);

        return $code === 0 && trim((string) $sortie) === '3' && trim((string) $erreur) === '';
    }
}
