<?php

declare(strict_types=1);

namespace Ged\Jonctions;

/**
 * Transport SMTP : paramètres dans `.env` (`GED_SMTP_*`). Aucun démon : une connexion par courriel, ce qui
 * convient à un hébergement mutualisé.
 */
final class TransportSmtp implements Transport
{
    use MessagePhpMailer;

    public function __construct(
        private readonly string $hote,
        private readonly int $port,
        private readonly string $utilisateur,
        private readonly string $motDePasse,
        private readonly string $chiffrement,
        private readonly string $expediteur,
        private readonly string $expediteurNom,
    ) {
    }

    public function envoyer(string $destinataire, string $objet, string $texte, array $pieces = [], array $entetes = []): void
    {
        if (trim($this->hote) === '') {
            throw new \RuntimeException('serveur SMTP non configuré (GED_SMTP_HOTE)');
        }
        $mail = $this->message($destinataire, $objet, $texte, $pieces, $entetes);
        try {
            $mail->isSMTP();
            $mail->Host = $this->hote;
            $mail->Port = $this->port;
            $mail->SMTPAuth = $this->utilisateur !== '';
            $mail->Username = $this->utilisateur;
            $mail->Password = $this->motDePasse;
            $chiffrement = strtolower(trim($this->chiffrement));
            if (in_array($chiffrement, ['tls', 'ssl'], true)) {
                $mail->SMTPSecure = $chiffrement === 'ssl'
                    ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->send();
        } catch (\Throwable $e) {
            throw new \RuntimeException('courriel non remis par le serveur SMTP : ' . $e->getMessage(), 0, $e);
        }
    }
}
