<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Courriel lu depuis une boîte (modele Paperless `MailMessage`), réduit à ce que le moteur de règles regarde :
 * en-têtes, corps, date, brut RFC 822, pièces jointes et l'état des drapeaux (lu, signalé, mots-clés). Les
 * drapeaux et mots-clés sont mutables : le faux serveur les fait évoluer quand le moteur applique une action.
 */
final class Message
{
    /**
     * @param list<string> $destinataires
     * @param list<Piece> $pieces
     * @param list<string> $motsCles
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $sujet,
        public readonly string $de,
        public readonly string $deNom,
        public readonly array $destinataires,
        public readonly string $corps,
        public readonly \DateTimeImmutable $date,
        public readonly string $brut,
        public readonly array $pieces,
        public bool $vu,
        public bool $signale,
        public array $motsCles,
    ) {
    }

    /**
     * @param array<string, mixed> $donnees
     */
    public static function depuisTableau(array $donnees, \DateTimeImmutable $dateDefaut): self
    {
        $pieces = [];
        foreach ((array) ($donnees['pieces'] ?? []) as $piece) {
            if (is_array($piece)) {
                $pieces[] = Piece::depuisTableau($piece);
            }
        }
        $date = isset($donnees['date']) && is_string($donnees['date']) ? new \DateTimeImmutable($donnees['date']) : $dateDefaut;
        $destinataires = [];
        foreach ((array) ($donnees['a'] ?? []) as $destinataire) {
            if (is_string($destinataire)) {
                $destinataires[] = $destinataire;
            }
        }
        $motsCles = [];
        foreach ((array) ($donnees['mots_cles'] ?? []) as $mot) {
            if (is_string($mot)) {
                $motsCles[] = $mot;
            }
        }

        return new self(
            (int) ($donnees['uid'] ?? 0),
            (string) ($donnees['sujet'] ?? ''),
            (string) ($donnees['de'] ?? ''),
            (string) ($donnees['de_nom'] ?? ''),
            $destinataires,
            (string) ($donnees['corps'] ?? ''),
            $date,
            (string) ($donnees['brut'] ?? ''),
            $pieces,
            (bool) ($donnees['vu'] ?? false),
            (bool) ($donnees['signale'] ?? false),
            $motsCles,
        );
    }
}
