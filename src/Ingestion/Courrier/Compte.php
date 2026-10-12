<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Compte de messagerie (modele Paperless `MailAccount`), rangé dans le dossier de configuration `var/courrier`
 * — le dossier fait foi, la base n'en garde rien. Le mot de passe n'existe QU'EN CHIFFRÉ (`secret_chiffre`) :
 * `pourEcran()` ne rend jamais le clair, seulement un masque, comme Paperless.
 */
final class Compte
{
    public const SECURITE_NON = 1;
    public const SECURITE_SSL = 2;
    public const SECURITE_STARTTLS = 3;

    public const TYPE_IMAP = 1;
    public const TYPE_GMAIL_OAUTH = 2;
    public const TYPE_OUTLOOK_OAUTH = 3;

    /** Masque rendu par l'API et l'écran : jamais le secret, même tronqué. */
    public const MASQUE = '**********';

    public function __construct(
        public readonly int $id,
        public readonly string $nom,
        public readonly string $serveur,
        public readonly ?int $port,
        public readonly int $securite,
        public readonly string $utilisateur,
        public readonly string $secretChiffre,
        public readonly bool $jeton,
        public readonly string $jeuCaracteres,
        public readonly int $typeCompte,
        public readonly ?string $jetonRafraichissement,
        public readonly ?string $expiration,
        public readonly string $proprietaire,
        public readonly bool $actif,
    ) {
    }

    /** @param array<string, mixed> $donnees */
    public static function depuisTableau(array $donnees): self
    {
        return new self(
            (int) ($donnees['id'] ?? 0),
            (string) ($donnees['nom'] ?? ''),
            (string) ($donnees['serveur'] ?? ''),
            isset($donnees['port']) ? (int) $donnees['port'] : null,
            (int) ($donnees['securite'] ?? self::SECURITE_SSL),
            (string) ($donnees['utilisateur'] ?? ''),
            (string) ($donnees['secret_chiffre'] ?? ''),
            (bool) ($donnees['jeton'] ?? false),
            (string) ($donnees['jeu_caracteres'] ?? 'UTF-8'),
            (int) ($donnees['type_compte'] ?? self::TYPE_IMAP),
            isset($donnees['jeton_rafraichissement']) ? (string) $donnees['jeton_rafraichissement'] : null,
            isset($donnees['expiration']) ? (string) $donnees['expiration'] : null,
            (string) ($donnees['proprietaire'] ?? ''),
            (bool) ($donnees['actif'] ?? true),
        );
    }

    /**
     * Représentation persistée : le secret chiffré y figure, jamais le clair.
     *
     * @return array<string, mixed>
     */
    public function pourTableau(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'serveur' => $this->serveur,
            'port' => $this->port,
            'securite' => $this->securite,
            'utilisateur' => $this->utilisateur,
            'secret_chiffre' => $this->secretChiffre,
            'jeton' => $this->jeton,
            'jeu_caracteres' => $this->jeuCaracteres,
            'type_compte' => $this->typeCompte,
            'jeton_rafraichissement' => $this->jetonRafraichissement,
            'expiration' => $this->expiration,
            'proprietaire' => $this->proprietaire,
            'actif' => $this->actif,
        ];
    }

    /**
     * Représentation d'écran / d'API : aucun secret, seulement le masque. Les autres champs Paperless
     * (`account_type`, `imap_security`, `character_set`, `is_token`, `expiration`) sont restitués tels quels.
     *
     * @return array<string, mixed>
     */
    public function pourEcran(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->nom,
            'imap_server' => $this->serveur,
            'imap_port' => $this->port,
            'imap_security' => $this->securite,
            'username' => $this->utilisateur,
            'password' => self::MASQUE,
            'is_token' => $this->jeton,
            'character_set' => $this->jeuCaracteres,
            'account_type' => $this->typeCompte,
            'refresh_token' => $this->jetonRafraichissement !== null && $this->jetonRafraichissement !== '' ? self::MASQUE : null,
            'expiration' => $this->expiration,
            'owner' => $this->proprietaire,
            'actif' => $this->actif,
        ];
    }

    public static function libelleSecurite(int $securite): string
    {
        return match ($securite) {
            self::SECURITE_NON => 'Aucun chiffrement',
            self::SECURITE_SSL => 'SSL',
            self::SECURITE_STARTTLS => 'STARTTLS',
            default => '?',
        };
    }

    public static function libelleType(int $type): string
    {
        return match ($type) {
            self::TYPE_IMAP => 'IMAP',
            self::TYPE_GMAIL_OAUTH => 'Gmail OAuth',
            self::TYPE_OUTLOOK_OAUTH => 'Outlook OAuth',
            default => '?',
        };
    }
}
