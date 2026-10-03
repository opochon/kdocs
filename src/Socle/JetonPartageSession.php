<?php

declare(strict_types=1);

namespace Ged\Socle;

use Ged\Config;

/** Flash à usage unique d'un lien externe, rattaché à la session HTTP sans jamais passer dans une URL. */
final class JetonPartageSession
{
    private readonly string $dossier;

    public function __construct(Config $config)
    {
        $this->dossier = $config->chemin('SECURITE_DOSSIER', 'var/securite') . '/partages-session';
        if (!is_dir($this->dossier)) {
            mkdir($this->dossier, 0700, true);
        }
    }

    public function poser(string $session, string $jeton): void
    {
        if ($session === '' || $jeton === '') {
            return;
        }
        file_put_contents($this->fichier($session), $jeton, LOCK_EX);
    }

    public function consommer(string $session): string
    {
        if ($session === '') {
            return '';
        }
        $fichier = $this->fichier($session);
        // Le renommage dans le même dossier est atomique : un seul lecteur peut prendre le flash.
        // La lecture ne touche à aucune table SQL et ne laisse pas de
        // secret vide derrière lui.
        $lecture = $fichier . '.' . bin2hex(random_bytes(8)) . '.lecture';
        if (!@rename($fichier, $lecture)) {
            return '';
        }
        try {
            $jeton = file_get_contents($lecture);

            return is_string($jeton) ? trim($jeton) : '';
        } finally {
            @unlink($lecture);
        }
    }

    private function fichier(string $session): string
    {
        return $this->dossier . '/' . hash('sha256', $session) . '.flash';
    }
}
