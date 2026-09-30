<?php

declare(strict_types=1);

namespace Ged\Jonctions;

/**
 * Transport « fichier » : le message est préparé puis écrit en `.eml` dans un dossier. Utilisé en test
 * (jamais d'envoi réel) et utilisable en démonstration pour inspecter un courriel avant de brancher SMTP.
 */
final class TransportFichier implements Transport
{
    use MessagePhpMailer;

    public function __construct(
        private readonly string $dossier,
        private readonly string $expediteur,
        private readonly string $expediteurNom,
    ) {
    }

    public function envoyer(string $destinataire, string $objet, string $texte, array $pieces = [], array $entetes = []): void
    {
        $mail = $this->message($destinataire, $objet, $texte, $pieces, $entetes);
        try {
            $mail->preSend();
        } catch (\Throwable $e) {
            throw new \RuntimeException('courriel impossible à préparer : ' . $e->getMessage(), 0, $e);
        }
        $brut = $mail->getSentMIMEMessage();

        if (!is_dir($this->dossier) && !mkdir($this->dossier, 0775, true) && !is_dir($this->dossier)) {
            throw new \RuntimeException('dossier des courriels impossible à créer : ' . $this->dossier);
        }
        $fichier = rtrim($this->dossier, '/\\') . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        if (file_put_contents($fichier, $brut) === false) {
            throw new \RuntimeException('courriel impossible à écrire : ' . $fichier);
        }
    }
}
