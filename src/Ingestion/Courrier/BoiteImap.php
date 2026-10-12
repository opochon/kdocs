<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

use Ged\Ingestion\Imap\ImapBoite;
use Ged\Ingestion\Imap\ImapErreur;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Adaptateur IMAP du port de boîte : la production seulement. Il compose le client IMAP pur de GEDv2
 * (`ImapBoite`, RFC 9051) et lit le RFC 822 avec le même parseur que l'adresse d'ingestion. Aucun réseau en
 * test : les oracles injectent `BoiteFausse`. « Supprimer » range en corbeille, jamais d'effacement.
 */
final class BoiteImap implements PortBoite
{
    private ?ImapBoite $boite = null;
    private ?MailMimeParser $parseur = null;

    public function __construct(
        private readonly Compte $compte,
        private readonly Coffre $coffre,
        private readonly int $maximum = 200,
    ) {
    }

    public function nom(): string
    {
        return strtolower($this->compte->utilisateur . '@' . $this->compte->serveur);
    }

    public function dossiers(): array
    {
        return $this->ouvrir()->dossiers();
    }

    public function messages(string $dossier): array
    {
        $boite = $this->ouvrir();
        $boite->select($dossier);
        $parseur = $this->parseur ??= new MailMimeParser();
        $messages = [];
        foreach (array_slice($boite->rechercher('ALL'), 0, $this->maximum) as $uid) {
            $brut = $boite->lire($uid);
            $drapeaux = $boite->drapeaux($uid);
            $messages[] = $this->analyser($brut, $uid, $drapeaux, $parseur);
        }

        return $messages;
    }

    public function appliquer(string $dossier, int $uid, int $action, string $parametre): void
    {
        $boite = $this->ouvrir();
        $boite->select($dossier);
        switch ($action) {
            case Regle::ACTION_SUPPRIMER:
                $boite->deplacer($uid, Regle::CORBEILLE);
                break;
            case Regle::ACTION_DEPLACER:
                if (trim($parametre) !== '') {
                    $boite->deplacer($uid, $parametre);
                }
                break;
            case Regle::ACTION_MARQUER_LU:
                $boite->marquer($uid, '\\Seen', true);
                break;
            case Regle::ACTION_DRAPEAU:
                $boite->marquer($uid, '\\Flagged', true);
                break;
            case Regle::ACTION_ETIQUETER:
                if (str_starts_with(strtolower($parametre), 'apple:')) {
                    $boite->marquer($uid, '\\Flagged', true);
                } elseif ($parametre !== '') {
                    $boite->marquer($uid, $parametre, true);
                }
                break;
        }
    }

    public function tester(): void
    {
        try {
            $this->ouvrir();
        } catch (ImapErreur $e) {
            throw new ErreurCourrier('connexion au compte « ' . $this->compte->nom . ' » refusée : ' . $e->getMessage());
        }
    }

    public function fermer(): void
    {
        if ($this->boite !== null) {
            $this->boite->fermer();
            $this->boite = null;
        }
    }

    private function ouvrir(): ImapBoite
    {
        if ($this->boite !== null) {
            return $this->boite;
        }
        if ($this->compte->secretChiffre === '') {
            throw new ErreurCourrier('compte « ' . $this->compte->nom . ' » : aucun mot de passe enregistré');
        }
        try {
            $mot = $this->coffre->dechiffrer($this->compte->secretChiffre);
        } catch (ErreurCourrier $e) {
            throw new ErreurCourrier('compte « ' . $this->compte->nom . ' » : secret illisible (' . $e->getMessage() . ')');
        }
        $chiffrement = match ($this->compte->securite) {
            Compte::SECURITE_SSL => 'ssl',
            Compte::SECURITE_STARTTLS => 'starttls',
            default => 'tcp',
        };

        $this->boite = new ImapBoite(
            $this->compte->serveur,
            $this->compte->port ?? 993,
            $this->compte->utilisateur,
            $mot,
            $chiffrement,
            'INBOX',
            30,
        );

        return $this->boite;
    }

    /**
     * @param array{vu:bool, signale:bool, mots_cles:list<string>} $drapeaux
     */
    private function analyser(string $brut, int $uid, array $drapeaux, MailMimeParser $parseur): Message
    {
        $message = $parseur->parse($brut, false);
        $pieces = [];
        foreach ($message->getAllAttachmentParts() as $partie) {
            $contenu = $partie->getContent();
            if ($contenu === null) {
                continue;
            }
            $pieces[] = new Piece(
                (string) ($partie->getFilename() ?? ''),
                strtolower((string) $partie->getContentType()),
                (string) ($partie->getContentDisposition() ?? 'attachment'),
                $contenu,
            );
        }
        $de = strtolower((string) $message->getHeaderValue(HeaderConsts::FROM));
        $deNom = '';
        $entete = $message->getHeader(HeaderConsts::FROM);
        if ($entete instanceof \ZBateson\MailMimeParser\Header\AddressHeader) {
            $adresses = $entete->getAddresses();
            $de = strtolower((string) $adresses[0]->getEmail());
            $deNom = (string) $adresses[0]->getName();
        }
        $destinataires = [];
        $enteteA = $message->getHeader(HeaderConsts::TO);
        if ($enteteA instanceof \ZBateson\MailMimeParser\Header\AddressHeader) {
            foreach ($enteteA->getAddresses() as $adresse) {
                $destinataires[] = strtolower((string) $adresse->getEmail());
            }
        }
        $date = new \DateTimeImmutable();
        $brutDate = (string) $message->getHeaderValue(HeaderConsts::DATE);
        if ($brutDate !== '') {
            try {
                $date = new \DateTimeImmutable($brutDate);
            } catch (\Exception) {
                // En-tête Date illisible : repli nommé sur l'instant de lecture (le message reste traité,
                // le filtre d'âge s'applique alors sur cette date ; ce n'est pas une erreur silencieuse).
                $date = new \DateTimeImmutable();
            }
        }

        return new Message(
            $uid,
            (string) $message->getHeaderValue(HeaderConsts::SUBJECT),
            $de,
            $deNom,
            $destinataires,
            (string) $message->getTextContent(),
            $date,
            $brut,
            $pieces,
            $drapeaux['vu'],
            $drapeaux['signale'],
            $drapeaux['mots_cles'],
        );
    }
}
