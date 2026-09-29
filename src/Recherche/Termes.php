<?php

declare(strict_types=1);

namespace Ged\Recherche;

/**
 * Découpe d'une saisie en termes cherchables. Portage resserré de l'idée GEDv1 : opérateurs naturels,
 * guillemets pour une expression, signe `-` pour exclure. Un terme d'un seul caractère n'est pas une
 * requête : il fait basculer sur le repli mot à mot plutôt que de produire une expression FULLTEXT vide
 * (SQLSTATE 42000 / 1064).
 */
final class Termes
{
    /** @param list<string> $positifs @param list<string> $negatifs */
    private function __construct(
        private readonly array $positifs,
        private readonly array $negatifs,
    ) {
    }

    public static function depuis(string $requete): self
    {
        $phrases = [];
        if (preg_match_all('/"([^"]{2,})"/u', $requete, $trouvees) === 1 || !empty($trouvees[1])) {
            foreach ($trouvees[1] as $phrase) {
                $phrase = trim(preg_replace('/\s+/u', ' ', (string) $phrase) ?? '');
                if (mb_strlen($phrase) >= 2) {
                    $phrases[] = $phrase;
                }
            }
        }
        $reste = (string) preg_replace('/"[^"]*"/u', ' ', $requete);

        $positifs = $phrases;
        $negatifs = [];
        foreach (preg_split('/\s+/u', $reste, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $bloc) {
            $exclu = str_starts_with($bloc, '-');
            if ($exclu) {
                $bloc = ltrim($bloc, '-');
            }
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $bloc, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $mot) {
                if (mb_strlen($mot) < 2) {
                    continue;
                }
                $exclu ? $negatifs[] = $mot : $positifs[] = $mot;
            }
        }

        return new self(array_values(array_unique($positifs)), array_values(array_unique($negatifs)));
    }

    /** @return list<string> */
    public function positifs(): array
    {
        return $this->positifs;
    }

    /** @return list<string> */
    public function negatifs(): array
    {
        return $this->negatifs;
    }

    /**
     * Expression FULLTEXT en mode booléen. Vide quand aucun terme positif exploitable n'existe : c'est
     * le signal du repli LIKE (expression FULLTEXT vide = erreur 1064 côté MySQL).
     */
    public function expressionFulltext(): string
    {
        if ($this->positifs === []) {
            return '';
        }
        $parties = [];
        foreach ($this->positifs as $mot) {
            $parties[] = '+' . self::motBoolean($mot);
        }
        foreach ($this->negatifs as $mot) {
            $parties[] = '-' . self::motBoolean($mot);
        }

        return implode(' ', $parties);
    }

    /** @return list<string> motifs `%…%` des termes positifs, échappés pour LIKE */
    public function motifsLike(): array
    {
        return array_map(self::motifLike(...), $this->positifs);
    }

    /** @return list<string> motifs `%…%` des termes exclus */
    public function motifsExclus(): array
    {
        return array_map(self::motifLike(...), $this->negatifs);
    }

    private static function motBoolean(string $mot): string
    {
        return str_contains($mot, ' ') ? '"' . $mot . '"' : $mot . '*';
    }

    private static function motifLike(string $mot): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mot) . '%';
    }
}
