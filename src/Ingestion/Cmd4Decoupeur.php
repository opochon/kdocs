<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Moteur de découpe partagé avec CMD v4 (`cmd4_ingest`, dépôt ClearMyDocs). Mesuré sur le lot de 100 pages :
 * 53/53 pièces exactes, 0 fausse coupe, ~96 s sans vision (28-09-2026).
 *
 * Leçon GEDv1 (27-09) : stdout et stderr lus en tubes s'interbloquent quand le moteur écrit beaucoup sur stderr ;
 * stderr va donc dans un fichier, stdout seul est lu.
 */
final class Cmd4Decoupeur implements Decoupeur
{
    public function __construct(
        private readonly string $python,        // python du venv du produit ClearMyDocs
        private readonly string $dossierProduit, // …/ClearMyDocs/v4/product
        private readonly int $delaiS = 900,
        private readonly bool $vision = false,
    ) {
    }

    public function decouper(string $fichier, string $dossierSortie): array
    {
        if (!is_dir($dossierSortie) && !mkdir($dossierSortie, 0775, true) && !is_dir($dossierSortie)) {
            throw new \RuntimeException("Dossier de sortie impossible à créer : $dossierSortie");
        }
        $commande = array_merge([$this->python, '-X', 'utf8', '-m', 'cmd4_ingest'], $this->vision ? [] : ['--no-vision'],
            ['--out-dir', $dossierSortie, 'ingest', $fichier]);
        $erreurs = tempnam(sys_get_temp_dir(), 'cmd4');
        $proc = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['file', $erreurs, 'w']], $tubes, $this->dossierProduit);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Moteur de découpe introuvable : ' . $this->python);
        }
        stream_set_blocking($tubes[1], false);
        $sortie = '';
        $debut = time();
        while (true) {
            $sortie .= (string) stream_get_contents($tubes[1]);
            $etat = proc_get_status($proc);
            if (!$etat['running']) {
                $sortie .= (string) stream_get_contents($tubes[1]);
                break;
            }
            if (time() - $debut > $this->delaiS) {
                proc_terminate($proc);
                throw new \RuntimeException("Découpe interrompue après {$this->delaiS} s : $fichier");
            }
            usleep(100_000);
        }
        fclose($tubes[1]);
        $code = proc_close($proc);
        $code = $etat['exitcode'] ?? $code;
        $stderr = (string) @file_get_contents($erreurs);
        @unlink($erreurs);

        $json = self::dernierObjet($sortie);
        if (!is_array($json) || !isset($json['documents']) || !is_array($json['documents'])) {
            throw new \RuntimeException('Réponse du moteur illisible (code ' . $code . ') : ' . mb_substr(trim($stderr ?: $sortie), -400));
        }

        $pieces = [];
        foreach ($json['documents'] as $d) {
            $pieces[] = [
                'fichier' => (string) ($d['file'] ?? ''),
                'pages' => array_map('intval', (array) ($d['pages'] ?? [])),
                'texte' => (string) ($d['text'] ?? ''),
                'analyse' => ['facture' => $d['invoice'] ?? null, 'origine' => $d['origin'] ?? null],
            ];
        }

        return $pieces;
    }

    /**
     * Le moteur écrit son JSON sur stdout, mais certains extracteurs (Tesseract) y ajoutent du bruit :
     * on garde le dernier objet JSON complet qui commence en début de ligne.
     *
     * @return array<string, mixed>|null
     */
    private static function dernierObjet(string $sortie): ?array
    {
        $direct = json_decode($sortie, true);
        if (is_array($direct)) {
            return $direct;
        }
        preg_match_all('/^\{/m', $sortie, $m, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($m[0]) as [, $pos]) {
            $fin = strrpos($sortie, '}');
            while ($fin !== false && $fin > $pos) {
                $obj = json_decode(substr($sortie, $pos, $fin - $pos + 1), true);
                if (is_array($obj)) {
                    return $obj;
                }
                $fin = strrpos(substr($sortie, 0, $fin), '}');
            }
        }

        return null;
    }
}