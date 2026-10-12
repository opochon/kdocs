<?php

declare(strict_types=1);

namespace Ged\Ui;

use Ged\Config;
use Ged\Socle\Preferences;
use Ged\Socle\Vues;
use RuntimeException;

/**
 * Écran « Réglages » de l'utilisateur (modèle Paperless `SettingsComponent`, lot ged-parite-ecrans-1).
 *
 * Paperless range les préférences d'interface par utilisateur dans un dictionnaire `UiSettings` ; GEDv2
 * les range dans le fonds (`.ged/preferences.json`, `Ged\Socle\Preferences`) : elles suivent le dossier.
 *
 * Quatre sections, servies par la même page et par une adresse de section :
 *   - général (langue, thème, mode d'affichage, taille de page, colonnes) ;
 *   - notifications (nouveau document, traitement terminé, échec, masquer au tableau de bord) ;
 *   - permissions (les droits EFFECTIFS du compte, en lecture, et le lien vers les comptes/groupes) ;
 *   - vues (les vues enregistrées du fonds et le lien vers leur écran).
 *
 * Une saisie refusée lève une erreur NOMMÉE (`Preferences` borne les clés et les types) ; un GET n'écrit
 * jamais rien.
 */
final class Reglages
{
    /** Sections admises, dans l'ordre d'affichage. */
    public const SECTIONS = [
        'reglages-generaux' => 'Réglages généraux',
        'notifications' => 'Notifications',
        'permissions' => 'Permissions',
        'vues' => 'Vues enregistrées',
    ];

    public const LANGUES = ['fr', 'en', 'de', 'it'];
    public const THEMES = ['clair', 'sombre'];

    /** Clés de notification (bornées par `Preferences::CLEFS`). */
    public const NOTIFICATIONS = ['notifications_nouveau', 'notifications_succes', 'notifications_echec', 'notifications_masquer'];

    public function __construct(private readonly Config $config)
    {
    }

    public static function depuisRacine(?string $racine = null): self
    {
        return new self(new Config($racine ?? dirname(__DIR__, 2)));
    }

    public function sectionValide(string $section): string
    {
        return isset(self::SECTIONS[$section]) ? $section : (string) array_key_first(self::SECTIONS);
    }

    /**
     * @param list<string> $permissions permissions effectives de la session
     * @param array<string, mixed> $parametres
     * @return array<string, mixed>
     */
    public function donnees(string $utilisateur, string $section, array $permissions, array $parametres = []): array
    {
        $section = $this->sectionValide($section);

        return [
            'ecran' => 'reglages',
            'sections' => self::SECTIONS,
            'section' => $section,
            'reglages' => (new Preferences($this->config))->lire($utilisateur),
            'affichages' => Vues::AFFICHAGES,
            'modes' => Vues::MODES,
            'tris' => Vues::TRIS,
            'notifications' => self::NOTIFICATIONS,
            'langues' => self::LANGUES,
            'themes' => self::THEMES,
            'utilisateur' => $utilisateur,
            'permissions' => $permissions,
            'vues' => $this->vues(),
            'message' => (string) ($parametres['message'] ?? ''),
        ];
    }

    /**
     * Enregistre la section soumise, en FUSIONNANT avec les réglages existants : régler une section ne
     * perd jamais les autres. Chaque clé est bornée par `Preferences`.
     *
     * @param array<string, mixed> $corps
     */
    public function enregistrer(string $utilisateur, string $section, array $corps): void
    {
        $section = $this->sectionValide($section);
        $preferences = new Preferences($this->config);
        $fusion = $preferences->lire($utilisateur);
        foreach ($this->valeurs($section, $corps) as $cle => $valeur) {
            $fusion[$cle] = $valeur;
        }
        $preferences->enregistrer($utilisateur, $fusion);
    }

    /**
     * Valeurs admises pour une section. Toute valeur hors liste lève une erreur nommée.
     *
     * @param array<string, mixed> $corps
     * @return array<string, mixed>
     */
    private function valeurs(string $section, array $corps): array
    {
        if ($section === 'notifications') {
            $valeurs = [];
            foreach (self::NOTIFICATIONS as $cle) {
                $valeurs[$cle] = isset($corps[$cle]) && (string) $corps[$cle] === '1';
            }

            return $valeurs;
        }

        if (in_array($section, ['permissions', 'vues'], true)) {
            return [];   // sections en lecture seule : rien à écrire
        }

        $valeurs = [];
        $choix = [
            'langue' => self::LANGUES,
            'theme' => self::THEMES,
            'mode_affichage' => Vues::MODES,
            'tri' => Vues::TRIS,
        ];
        foreach ($choix as $cle => $admises) {
            $valeur = trim((string) ($corps[$cle] ?? ''));
            if ($valeur === '') {
                continue;
            }
            if (!in_array($valeur, $admises, true)) {
                throw new RuntimeException("réglages : valeur inconnue pour « $cle » (« $valeur »)");
            }
            $valeurs[$cle] = $valeur;
        }

        if (array_key_exists('taille_page', $corps)) {
            $valeurs['taille_page'] = (int) $corps['taille_page'];
        }
        $valeurs['colonnes'] = array_values(array_filter(
            array_map('strval', (array) ($corps['colonnes'] ?? [])),
            static fn (string $c): bool => in_array($c, Vues::AFFICHAGES, true),
        ));

        return $valeurs;
    }

    /** @return list<array{id:int, nom:string}> */
    private function vues(): array
    {
        try {
            $vues = [];
            foreach (array_slice((new Vues($this->config))->lister(), 0, 20) as $vue) {
                $vues[] = ['id' => (int) $vue['id'], 'nom' => (string) $vue['nom']];
            }

            return $vues;
        } catch (\Throwable) {
            return [];
        }
    }
}
