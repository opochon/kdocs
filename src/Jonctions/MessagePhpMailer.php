<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PHPMailer\PHPMailer\Exception as ExceptionMail;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Construction commune d'un message MIME (en-têtes, texte, pièces jointes). Le corps et les pièces sont
 * encodés en base64 : le `.eml` ne dépend d'aucun réglage local et se relit tel quel.
 */
trait MessagePhpMailer
{
    /**
     * @param list<array{nom:string, type:string, contenu:string}> $pieces
     * @param array<string, string> $entetes
     */
    private function message(string $destinataire, string $objet, string $texte, array $pieces, array $entetes): PHPMailer
    {
        try {
            $mail = new PHPMailer(true);
        } catch (ExceptionMail $e) {
            throw new \RuntimeException('message MIME impossible à préparer : ' . $e->getMessage(), 0, $e);
        }
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = PHPMailer::ENCODING_BASE64;
        $mail->XMailer = 'GEDv2';
        $mail->setFrom($this->expediteur, $this->expediteurNom);
        $mail->addAddress($destinataire);
        $mail->Subject = $objet;
        $mail->Body = $texte;
        foreach ($entetes as $nom => $valeur) {
            $mail->addCustomHeader($nom, $valeur);
        }
        foreach ($pieces as $piece) {
            $mail->addStringAttachment($piece['contenu'], $piece['nom'], PHPMailer::ENCODING_BASE64, $piece['type']);
        }

        return $mail;
    }
}
