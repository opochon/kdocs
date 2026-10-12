<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Préférences d'interface par utilisateur (modèle Paperless `UiSettings` : `user` + `settings`).
 *
 * Paperless stocke un dictionnaire libre par utilisateur. GEDv2 garde la même forme (`settings` est un
 * dictionnaire) mais borne les CLÉS connues : un réglage inconnu est refusé, jamais avalé en silence. Comme
 * les vues, les préférences vivent dans le fonds (`.ged/preferences.json`) et suivent le dossier.
 */
final class Preferences
{
    private const FICHIER = 'preferences';

    /** Réglages d'interface admis (clé → type attendu). */
    public const CLEFS = [
        'mode_affichage' => 'string',
        'taille_page' => 'int',
        'colonnes' => 'array',
        'langue' => 'string',
        'theme' => 'string',
        'modele_document' => 'string',
        'tri' => 'string',
        'tri_inverse' => 'bool',
        // Lot ged-parite-ecrans-1 (Interface) : réglages de notification de l'utilisateur, bornés comme
        // les autres (modèle Paperless `UiSettings.notifications_*`). Lignes hors secteur, signalées.
        'notifications_nouveau' => 'bool',
        'notifications_succes' => 'bool',
        'notifications_echec' => 'bool',
        'notifications_masquer' => 'bool',
    ];

    private readonly Registre $registre;

    public function __construct(Config $config)
    {
        $this->registre = new Registre($config);
    }

    /**
     * Enregistre (ou remplace) les réglages d'un utilisateur. `settings` doit être un dictionnaire.
     *
     * @param array<string, mixed> $reglages
     */
    public function enregistrer(string $utilisateur, array $reglages): void
    {
        $utilisateur = $this->validerUtilisateur($utilisateur);
        $propres = [];
        foreach ($reglages as $cle => $valeur) {
            $cle = (string) $cle;
            if (!isset(self::CLEFS[$cle])) {
                throw new RuntimeException("préférences : réglage inconnu « $cle »");
            }
            $propres[$cle] = $this->typer($cle, $valeur);
        }
        $this->registre->modifier(self::FICHIER, static function (array $etat) use ($utilisateur, $propres): array {
            $etat = (array) $etat;
            $etat[$utilisateur] = $propres;

            return $etat;
        });
    }

    /**
     * Les réglages d'un utilisateur (défauts compris), jamais null : un écran lit toujours une forme complète.
     *
     * @return array<string, mixed>
     */
    public function lire(string $utilisateur): array
    {
        $utilisateur = trim($utilisateur);
        $etat = $this->registre->lire(self::FICHIER);
        $brut = is_array($etat[$utilisateur] ?? null) ? $etat[$utilisateur] : [];

        return [
            'mode_affichage' => (string) ($brut['mode_affichage'] ?? 'table'),
            'taille_page' => (int) ($brut['taille_page'] ?? 50),
            'colonnes' => is_array($brut['colonnes'] ?? null) ? array_values(array_map('strval', $brut['colonnes'])) : Vues::AFFICHAGES,
            'langue' => (string) ($brut['langue'] ?? 'fr'),
            'theme' => (string) ($brut['theme'] ?? 'clair'),
            'modele_document' => (string) ($brut['modele_document'] ?? 'complet'),
            'tri' => (string) ($brut['tri'] ?? 'created'),
            'tri_inverse' => (bool) ($brut['tri_inverse'] ?? false),
            'notifications_nouveau' => (bool) ($brut['notifications_nouveau'] ?? false),
            'notifications_succes' => (bool) ($brut['notifications_succes'] ?? false),
            'notifications_echec' => (bool) ($brut['notifications_echec'] ?? false),
            'notifications_masquer' => (bool) ($brut['notifications_masquer'] ?? false),
        ];
    }

    private function validerUtilisateur(string $utilisateur): string
    {
        $utilisateur = trim($utilisateur);
        if ($utilisateur === '' || mb_strlen($utilisateur) > 128) {
            throw new RuntimeException('préférences : utilisateur vide ou trop long (128 caractères au plus)');
        }

        return $utilisateur;
    }

    private function typer(string $cle, mixed $valeur): mixed
    {
        return match (self::CLEFS[$cle]) {
            'int' => is_numeric($valeur) ? (int) $valeur : throw new RuntimeException("préférences : « $cle » attend un entier"),
            'bool' => (bool) $valeur,
            'array' => is_array($valeur) ? array_values(array_map('strval', $valeur)) : throw new RuntimeException("préférences : « $cle » attend une liste"),
            default => is_scalar($valeur) ? (string) $valeur : throw new RuntimeException("préférences : « $cle » attend un texte"),
        };
    }
}
