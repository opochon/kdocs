<?php

declare(strict_types=1);

namespace Ged\Ingestion;

use Ged\Base;
use Ged\Config;
use Ged\Socle\Fonds;
use Ged\Socle\Registre;
use PDO;
use RuntimeException;

/**
 * Rattrapage du fonds déjà présent : produire la copie d'archivage PDF/A-2b des documents PDF et images
 * qui n'en ont pas encore. même esprit que la reconstruction d'index du Socle — une tâche courte,
 * reprenable, idempotente :
 *
 *  - le périmètre ordonné est figé au lancement (les chemins relatifs), donc une reprise repart EXACTEMENT
 *    au même document après une coupure ;
 *  - `traite` est le point de reprise ; `ecart` compte les documents disparus du fonds en cours de route
 *    (jamais tus) ; la tâche se termine d'elle-même, elle ne reste pas « en cours » pour toujours ;
 *  - rejouer une passe ne refait aucune copie déjà juste (le service est idempotent) : 0 doublon.
 *
 * L'état vit dans le registre `.ged/pdfa-rattrapage.json` du fonds (le dossier fait foi, comme la
 * reconstruction) ; aucun démon, aucun appel long : chaque tranche est bornée.
 */
final class RattrapagePdfA
{
    private const REGISTRE = 'pdfa-rattrapage';
    private const TRANCHE_MAX = 100;

    private readonly Registre $registre;

    public function __construct(private readonly Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Fige le périmètre et ouvre une tâche. Refuse un second lancement tant qu'une tâche est en cours.
     *
     * @return array<string, mixed>
     */
    public function lancer(string $auteur): array
    {
        return $this->registre->modifier(self::REGISTRE, function (array $etat) use ($auteur): array {
            if (($etat['etat'] ?? null) === 'en_cours') {
                throw new RuntimeException('rattrapage PDF/A : une tâche est déjà en cours');
            }
            $perimetre = $this->perimetre();

            return ['id' => (int) ($etat['id'] ?? 0) + 1, 'etat' => 'en_cours', 'total' => count($perimetre),
                'traite' => 0, 'ecart' => 0, 'auteur' => $auteur, 'erreur' => null, 'perimetre' => $perimetre];
        });
    }

    /**
     * Traite la tranche suivante (bornée). Rend l'état publié.
     *
     * @return array<string, mixed>
     */
    public function avancer(int $id, int $lot = 25): array
    {
        try {
            return $this->registre->modifier(self::REGISTRE, function (array $etat) use ($id, $lot): array {
                if (($etat['id'] ?? null) !== $id) {
                    throw new RuntimeException('rattrapage PDF/A : tâche introuvable');
                }
                if (($etat['etat'] ?? null) !== 'en_cours') {
                    return $etat;
                }
                $perimetre = array_values(array_map('strval', (array) ($etat['perimetre'] ?? [])));
                $total = (int) ($etat['total'] ?? count($perimetre));
                $traite = (int) ($etat['traite'] ?? 0);
                $ecart = (int) ($etat['ecart'] ?? 0);
                $service = CopieArchivage::depuis($this->config);
                $racine = rtrim(str_replace('\\', '/', $this->config->chemin('STORAGE_ROOT', 'storage/documents')), '/');
                $borne = max(1, min(self::TRANCHE_MAX, $lot));
                $faits = 0;

                while ($faits < $borne && $traite < $total) {
                    $relatif = $perimetre[$traite] ?? null;
                    $traite++;
                    $faits++;
                    if (!is_string($relatif) || $relatif === '') {
                        $ecart++;
                        continue;
                    }
                    $absolu = $racine . '/' . $relatif;
                    if (!is_file($absolu)) {
                        $ecart++;   // disparu entre le lancement et la tranche : compté, jamais tu
                        continue;
                    }
                    try {
                        $service->pour($absolu, $relatif, $this->idDocument($relatif));
                    } catch (\Throwable $e) {
                        // Un document récalcitrant ne doit pas arrêter le fonds : il est tracé et la tranche continue.
                        error_log('pdfa : copie non produite pour ' . $relatif . ' — ' . $e->getMessage());
                    }
                }

                $etat['traite'] = $traite;
                $etat['ecart'] = $ecart;
                $etat['etat'] = $traite >= $total ? 'termine' : 'en_cours';

                return $etat;
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->registre->modifier(self::REGISTRE, static function (array $etat) use ($id, $e): array {
                if (($etat['id'] ?? null) === $id) {
                    $etat['etat'] = 'echec';
                    $etat['erreur'] = mb_substr($e->getMessage(), 0, 512);
                }

                return $etat;
            });

            return $this->etat($id) ?? throw new RuntimeException('rattrapage PDF/A : tâche perdue');
        }
    }

    /**
     * @return array{id:int, etat:string, total:int, traite:int, auteur:string, erreur:?string, ecart:int}|null
     */
    public function etat(?int $id = null): ?array
    {
        $etat = $this->registre->lire(self::REGISTRE);
        if (!isset($etat['id']) || ($id !== null && (int) $etat['id'] !== $id)) {
            return null;
        }

        return ['id' => (int) $etat['id'], 'etat' => (string) $etat['etat'], 'total' => (int) $etat['total'],
            'traite' => (int) $etat['traite'], 'auteur' => (string) ($etat['auteur'] ?? ''),
            'erreur' => ($etat['erreur'] ?? null) === null ? null : (string) $etat['erreur'],
            'ecart' => (int) ($etat['ecart'] ?? 0)];
    }

    /** @return list<string> chemins relatifs des documents éligibles, ordre stable */
    private function perimetre(): array
    {
        $fonds = new Fonds($this->config);
        $perimetre = [];
        foreach ($fonds->tous($this->pdo()) as $document) {
            $relatif = str_replace('\\', '/', (string) $document['chemin']);
            if (CopieArchivage::estEligible($fonds->racine() . '/' . $relatif)) {
                $perimetre[] = $relatif;
            }
        }
        sort($perimetre);

        return $perimetre;
    }

    private function idDocument(string $relatif): ?int
    {
        try {
            $requete = $this->pdo()->prepare('SELECT id FROM documents WHERE chemin_relatif = ? LIMIT 1');
            $requete->execute([$relatif]);
            $id = $requete->fetchColumn();

            return $id === false ? null : (int) $id;
        } catch (\Throwable) {
            return null;   // la Garde retrouve un scellement par le nom du fichier même sans identifiant
        }
    }

    private function pdo(): PDO
    {
        return Base::pdo($this->config);
    }
}
