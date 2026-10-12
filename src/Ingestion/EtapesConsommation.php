<?php

declare(strict_types=1);

namespace Ged\Ingestion;

/**
 * Base commune des étapes de consommation d'un fichier.
 *
 * Équivalent GEDv2 de `ConsumerPluginMixin` : l'étape connaît le NOM du fichier qu'elle traite et sait
 * émettre une progression ou échouer en le nommant. GEDv2 n'a pas de greffons Django empilés : les
 * étapes sont des méthodes explicites d'une même passe (`ControleAvant`, puis découpe, OCR, classement),
 * mais elles partagent ce socle pour qu'une erreur dise toujours quel fichier et quelle cause.
 */
class EtapesConsommation
{
    public function __construct(private readonly string $fichier)
    {
    }

    public function nomFichier(): string
    {
        return $this->fichier;
    }

    /**
     * Événement de progression : statut stable (MessageStatut) et avancement borné.
     *
     * @return array{statut:string, message:string, courant:int, total:int}
     */
    public function progression(int $courant, int $total, string $statut, ?string $message = null): array
    {
        $total = max(1, $total);

        return [
            'statut' => $statut,
            'message' => $message ?? $statut,
            'courant' => max(0, min($courant, $total)),
            'total' => $total,
        ];
    }

    /**
     * Échoue en nommant le fichier : la cause est portée par l'exception, jamais avalée.
     */
    public function echouer(string $statut, string $cause): never
    {
        throw new ErreurConsommation($this->fichier, $cause . ' (' . $statut . ')');
    }
}
