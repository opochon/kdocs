<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Classement\Correspondance;
use Ged\Config;
use RuntimeException;

/**
 * Registre des ÉMETTEURS (modèle Paperless `Correspondent`) : un nom, une règle d'appariement
 * (`Matching` : algorithme, motif, sensibilité à la casse) et un état actif. Comme les étiquettes et
 * la nomenclature, il vit dans le fonds (`.ged/emetteurs.json`) : le dossier fait foi, une
 * reconstruction l'emporte.
 *
 * Le registre ne remplace pas l'historique : `Catalogue::emetteurs` continue de rendre les émetteurs
 * RÉELLEMENT portés par les documents lisibles. La liste exposée est l'union des deux, triée ; le
 * registre seul est ce que les verbes REST modifient.
 *
 * Zéro suppression (règle 4) : un émetteur ne se supprime pas, il se met en veille (`actif = false`) et
 * reste lisible pour les documents qui le portent.
 */
final class Emetteurs
{
    public const FICHIER = 'emetteurs';

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * @return array{id:int, nom:string, algo:string, motif:string, insensible:bool, actif:bool}
     */
    public function ajouter(string $nom, string $algo = 'aucun', string $motif = '', bool $insensible = true): array
    {
        $matching = new Matching($nom, $motif, self::normaliserAlgo($algo), $insensible);
        foreach ($this->lister(false) as $e) {
            if (mb_strtolower($e['nom']) === mb_strtolower($matching->nom)) {
                throw new RuntimeException("émetteur : « {$matching->nom} » existe déjà");
            }
        }
        $id = 0;
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($matching, &$id): array {
            $entrees = (array) ($etat['emetteurs'] ?? []);
            $id = self::prochainId($entrees);
            $entrees[] = ['id' => $id] + self::ligne($matching) + ['actif' => true];

            return ['emetteurs' => $entrees];
        });

        return $this->lire($id) ?? throw new RuntimeException('émetteur : création non relue');
    }

    public function renommer(int $id, string $nom): void
    {
        $matching = new Matching($nom, '', Correspondance::AUCUN);
        $this->changer($id, ['nom' => $matching->nom]);
    }

    public function definirRegle(int $id, string $algo, string $motif, bool $insensible): void
    {
        $matching = new Matching($this->exiger($id)['nom'], $motif, self::normaliserAlgo($algo), $insensible);
        $this->changer($id, self::ligne($matching));
    }

    /**
     * Algorithme d'appariement : accepte la constante GEDv2 (`MATCH_NONE`…) ou son alias français
     * (`aucun`, `tous`, `un`, `litteral`, `regex`, `flou`, `auto`). Un nom inconnu est refusé par
     * `Matching` ; le défaut GEDv2 est `MATCH_NONE`, un émetteur sans règle.
     */
    private static function normaliserAlgo(string $algo): string
    {
        $alias = [
            '' => Correspondance::AUCUN, 'aucun' => Correspondance::AUCUN, 'none' => Correspondance::AUCUN,
            'tous' => Correspondance::TOUS, 'all' => Correspondance::TOUS,
            'un' => Correspondance::UN, 'any' => Correspondance::UN,
            'litteral' => Correspondance::LITTERAL, 'literal' => Correspondance::LITTERAL,
            'regex' => Correspondance::REGEX, 'flou' => Correspondance::FLOU, 'fuzzy' => Correspondance::FLOU,
            'auto' => Correspondance::AUTO,
        ];

        return $alias[mb_strtolower(trim($algo))] ?? $algo;
    }

    public function definirActif(int $id, bool $actif): void
    {
        $this->changer($id, ['actif' => $actif]);
    }

    /** @return array{id:int, nom:string, algo:string, motif:string, insensible:bool, actif:bool}|null */
    public function lire(int $id): ?array
    {
        foreach ($this->lister(false) as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /**
     * @return list<array{id:int, nom:string, algo:string, motif:string, insensible:bool, actif:bool}>
     */
    public function lister(bool $actifsSeuls = true): array
    {
        $entrees = [];
        foreach ((array) ($this->registre->lire(self::FICHIER)['emetteurs'] ?? []) as $e) {
            $e = (array) $e;
            $entrees[] = [
                'id' => (int) $e['id'], 'nom' => (string) $e['nom'],
                'algo' => (string) ($e['algo'] ?? 'aucun'), 'motif' => (string) ($e['motif'] ?? ''),
                'insensible' => (bool) ($e['insensible'] ?? true), 'actif' => (bool) ($e['actif'] ?? true),
            ];
        }

        return $actifsSeuls ? array_values(array_filter($entrees, static fn (array $e): bool => $e['actif'])) : $entrees;
    }

    /** @return array{id:int, nom:string, algo:string, motif:string, insensible:bool, actif:bool} */
    private function exiger(int $id): array
    {
        return $this->lire($id) ?? throw new RuntimeException("émetteur : aucun émetteur nº $id");
    }

    /** @return array{nom:string, algo:string, motif:string, insensible:bool} */
    private static function ligne(Matching $matching): array
    {
        return ['nom' => $matching->nom, 'algo' => $matching->algorithme,
            'motif' => $matching->motif, 'insensible' => $matching->insensible];
    }

    /** @param array<string, mixed> $champs */
    private function changer(int $id, array $champs): void
    {
        if ($this->lire($id) === null) {
            throw new RuntimeException("émetteur : aucun émetteur nº $id");
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($id, $champs): array {
            $entrees = (array) ($etat['emetteurs'] ?? []);
            foreach ($entrees as &$e) {
                $e = (array) $e;
                if ((int) ($e['id'] ?? 0) === $id) {
                    foreach ($champs as $cle => $valeur) {
                        $e[$cle] = $valeur;
                    }
                }
            }

            return ['emetteurs' => $entrees];
        });
    }

    /** @param array<mixed> $entrees */
    private static function prochainId(array $entrees): int
    {
        $max = 0;
        foreach ($entrees as $e) {
            $max = max($max, (int) (((array) $e)['id'] ?? 0));
        }

        return $max + 1;
    }
}
