<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use Ged\Config;

/**
 * Courriels du secteur Jonctions. Le transport est décidé par la configuration : SMTP (`GED_SMTP_*`) en
 * production, « fichier » (`var/mails`) en test — jamais d'envoi réel dans une suite de tests.
 *
 * Le courriel d'auto-configuration suit le contrat CMM v2 : objet « Relier votre GED à ClearMyMails »,
 * en-tête `X-GED-Appairage: v1`, texte humain et pièce `ged-appairage.json`
 * `{version, ged_url, ged_nom, compte, code, verification, expire_le}`.
 */
final class Mailer
{
    public const OBJET_APPAIRAGE = 'Relier votre GED à ClearMyMails';
    public const OBJET_VERIFICATION = 'Vérifiez votre adresse mail — GED';
    public const ENTETE_APPAIRAGE = 'X-GED-Appairage';

    public function __construct(
        private readonly Transport $transport,
        private readonly string $gedUrl,
        private readonly string $gedNom,
    ) {
    }

    public static function depuisConfig(Config $config): self
    {
        $nom = $config->get('GED_NOM', 'GEDv2');
        $choix = strtolower($config->get('GED_MAIL_TRANSPORT', $config->get('APP_ENV', 'dev') === 'test' ? 'fichier' : 'smtp'));
        $expediteur = $config->get('GED_MAIL_EXPEDITEUR', 'ged@exemple.ch');
        $expediteurNom = $config->get('GED_MAIL_EXPEDITEUR_NOM', $nom);
        $transport = $choix === 'fichier'
            ? new TransportFichier($config->chemin('GED_MAIL_DOSSIER', 'var/mails'), $expediteur, $expediteurNom)
            : new TransportSmtp(
                $config->get('GED_SMTP_HOTE'),
                (int) $config->get('GED_SMTP_PORT', '587'),
                $config->get('GED_SMTP_UTILISATEUR'),
                $config->get('GED_SMTP_MOT_DE_PASSE'),
                $config->get('GED_SMTP_CHIFFREMENT', 'tls'),
                $expediteur,
                $expediteurNom,
            );

        return new self($transport, rtrim($config->get('APP_URL', 'http://127.0.0.1:8870'), '/'), $nom);
    }

    public function gedUrl(): string
    {
        return $this->gedUrl;
    }

    public function gedNom(): string
    {
        return $this->gedNom;
    }

    /** Courriel d'auto-configuration lu par le plugin « Ged » de ClearMyMails. */
    public function envoyerAppairage(string $adresse, string $compte, string $code, string $verification, string $expireLeIso): void
    {
        $piece = json_encode([
            'version' => 1,
            'ged_url' => $this->gedUrl,
            'ged_nom' => $this->gedNom,
            'compte' => $compte,
            'code' => $code,
            'verification' => $verification,
            'expire_le' => $expireLeIso,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $texte = "Bonjour,\n\n"
            . "Votre GED « {$this->gedNom} » ({$this->gedUrl}) a reçu une demande pour être reliée à ClearMyMails,\n"
            . "pour le compte {$compte}.\n\n"
            . "Code de vérification à comparer dans ClearMyMails : {$verification}\n"
            . "Cette demande expire le {$expireLeIso} (24 heures).\n\n"
            . "La pièce jointe ged-appairage.json n'est pas un programme : elle ne sert qu'à configurer le plugin\n"
            . "« Ged » déjà installé dans ClearMyMails. Le plugin vous demandera de confirmer que le code de\n"
            . "vérification affiché par la GED est identique à celui-ci. Si vous n'êtes pas à l'origine de cette\n"
            . "demande, ignorez ce message : sans votre validation, aucune liaison n'est créée et la GED ne lit\n"
            . "jamais votre boîte mail.\n";

        $this->transport->envoyer($adresse, self::OBJET_APPAIRAGE, $texte,
            [['nom' => 'ged-appairage.json', 'type' => 'application/json', 'contenu' => (string) $piece]],
            [self::ENTETE_APPAIRAGE => 'v1']);
    }

    /** Courriel de vérification d'une adresse de profil : il ne porte qu'un lien à usage unique. */
    public function envoyerVerification(string $adresse, string $lien, string $expireLeIso): void
    {
        $texte = "Bonjour,\n\n"
            . "Pour vérifier cette adresse dans votre GED « {$this->gedNom} », ouvrez ce lien :\n\n"
            . "{$lien}\n\n"
            . "Ce lien est à usage unique et expire le {$expireLeIso}. Si vous n'êtes pas à l'origine de cette\n"
            . "demande, ignorez ce message.\n";

        $this->transport->envoyer($adresse, self::OBJET_VERIFICATION, $texte);
    }

    /**
     * Courriel portant des documents (action `email_document` / `email_documents` de l'API). Le transport
     * est celui de la GED : « fichier » en test (jamais d'envoi réel), SMTP en production.
     *
     * @param list<array{nom:string, type:string, contenu:string}> $pieces
     */
    public function envoyerAvecPieces(string $adresse, string $objet, string $texte, array $pieces = []): void
    {
        $this->transport->envoyer(trim($adresse), $objet, $texte, $pieces);
    }
}
