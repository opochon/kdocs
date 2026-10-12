<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;
use RuntimeException;

/**
 * Configuration applicative INSCRIPTIBLE (modèle Paperless `ApplicationConfiguration`) : le titre de
 * l'application et la langue d'interface, superposés au fichier `.env` (qui reste la valeur par défaut
 * et la source des réglages sensibles). Elle vit dans le fonds (`.ged/configuration.json`) : le dossier
 * fait foi, l'écran `/application` et `PATCH /api/v1/configuration` écrivent la MÊME surcharge.
 *
 * Zéro suppression (règle 4) : `reinitialiser` ne supprime aucune donnée utilisateur, elle retire la
 * surcharge pour revenir aux valeurs du `.env`.
 */
final class ConfigurationApp
{
    public const FICHIER = 'configuration';

    /** Clés modifiables par l'API et l'écran : jamais un secret, jamais un chemin de serveur. */
    public const CLES = ['titre', 'langue'];

    private readonly Registre $registre;

    public function __construct(private readonly Config $config)
    {
        $this->registre = new Registre($config);
    }

    /** @return array{titre:string, langue:string, source:string} */
    public function effective(): array
    {
        $surcharge = $this->surcharge();
        $titre = isset($surcharge['titre']) ? (string) $surcharge['titre'] : $this->config->get('GED_NOM', 'GEDv2');
        $langue = isset($surcharge['langue']) ? (string) $surcharge['langue'] : $this->config->get('GED_LANGUE', 'fr');

        return ['titre' => $titre, 'langue' => $langue, 'source' => $surcharge === [] ? 'env' : 'fonds'];
    }

    /** @return array<string, mixed> la surcharge seule (clés présentes seulement si surchargées) */
    public function surcharge(): array
    {
        $etat = $this->registre->lire(self::FICHIER);

        return is_array($etat['reglages'] ?? null) ? (array) $etat['reglages'] : [];
    }

    /**
     * @param array<string, mixed> $valeurs titre et/ou langue
     */
    public function modifier(array $valeurs): void
    {
        $nettoyees = [];
        foreach (self::CLES as $cle) {
            if (!array_key_exists($cle, $valeurs) || !is_scalar($valeurs[$cle])) {
                continue;
            }
            $valeur = trim((string) $valeurs[$cle]);
            if ($valeur === '') {
                continue;
            }
            $nettoyees[$cle] = $valeur;
        }
        if ($nettoyees === []) {
            throw new RuntimeException('configuration : aucun réglage modifiable fourni (titre, langue)');
        }
        $this->registre->modifier(self::FICHIER, function (array $etat) use ($nettoyees): array {
            $reglages = (array) ($etat['reglages'] ?? []);
            foreach ($nettoyees as $cle => $valeur) {
                $reglages[$cle] = $valeur;
            }

            return ['reglages' => $reglages];
        });
    }

    /** Retire la surcharge : l'application repart des valeurs du `.env`. Rien n'est supprimé du fonds. */
    public function reinitialiser(): void
    {
        $this->registre->modifier(self::FICHIER, static fn (array $etat): array => ['reglages' => []]);
    }
}
