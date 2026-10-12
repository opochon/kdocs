<?php

declare(strict_types=1);

namespace Ged\Ingestion\Courrier;

/**
 * Faux serveur de courrier EN MÉMOIRE : c'est la boîte injectée en test (aucun réseau, aucun vrai serveur).
 * Il reproduit ce que le moteur doit observer : dossiers, drapeaux (lu, signalé), mots-clés, déplacement.
 * « Supprimer » range en corbeille, jamais d'effacement : GEDv2 ne perd aucun message.
 */
final class BoiteFausse implements PortBoite
{
    /** @var array<string, list<Message>> */
    private array $dossiers;

    /** @var list<array{dossier:string, uid:int, action:int, parametre:string}> */
    public array $actions = [];

    /** Simule une panne du serveur au moment d'appliquer l'action (oracle du statut FAILED). */
    public bool $panneApplication = false;

    /**
     * @param list<Message> $messages
     */
    public function __construct(private readonly string $nomBoite, array $messages = [], private readonly string $dossierReception = 'INBOX')
    {
        $this->dossiers = [$this->dossierReception => $messages];
    }

    /** Remplit la boîte depuis un fichier JSON : `{"messages": [...]}`. */
    public static function depuisFichier(string $chemin, ?string $nomBoite = null): self
    {
        $donnees = is_file($chemin) ? json_decode((string) file_get_contents($chemin), true) : null;
        $messages = [];
        $maintenant = new \DateTimeImmutable();
        if (is_array($donnees)) {
            foreach ((array) ($donnees['messages'] ?? []) as $brut) {
                if (is_array($brut)) {
                    $messages[] = Message::depuisTableau($brut, $maintenant);
                }
            }
        }

        return new self(
            $nomBoite ?? (is_array($donnees) ? (string) ($donnees['nom'] ?? 'boite@faux.test') : 'boite@faux.test'),
            $messages,
        );
    }

    public function nom(): string
    {
        return $this->nomBoite;
    }

    public function dossiers(): array
    {
        return array_keys($this->dossiers);
    }

    public function messages(string $dossier): array
    {
        return $this->dossiers[$dossier] ?? [];
    }

    public function appliquer(string $dossier, int $uid, int $action, string $parametre): void
    {
        $this->actions[] = ['dossier' => $dossier, 'uid' => $uid, 'action' => $action, 'parametre' => $parametre];
        $index = $this->index($dossier, $uid);
        if ($index === null) {
            return;
        }
        if ($this->panneApplication) {
            throw new ErreurCourrier('la boîte a refusé l’action (panne simulée)');
        }
        $message = $this->dossiers[$dossier][$index];

        switch ($action) {
            case Regle::ACTION_SUPPRIMER:
                $this->deplacer($dossier, $index, Regle::CORBEILLE);
                break;
            case Regle::ACTION_DEPLACER:
                if (trim($parametre) !== '') {
                    $this->deplacer($dossier, $index, $parametre);
                }
                break;
            case Regle::ACTION_MARQUER_LU:
                $message->vu = true;
                break;
            case Regle::ACTION_DRAPEAU:
                $message->signale = true;
                break;
            case Regle::ACTION_ETIQUETER:
                if (str_starts_with(strtolower($parametre), 'apple:')) {
                    $message->signale = true;
                } elseif ($parametre !== '' && !in_array($parametre, $message->motsCles, true)) {
                    $message->motsCles[] = $parametre;
                }
                break;
        }
    }

    private function index(string $dossier, int $uid): ?int
    {
        foreach ($this->dossiers[$dossier] ?? [] as $i => $message) {
            if ($message->uid === $uid) {
                return $i;
            }
        }

        return null;
    }

    private function deplacer(string $dossier, int $index, string $cible): void
    {
        $message = $this->dossiers[$dossier][$index];
        unset($this->dossiers[$dossier][$index]);
        $this->dossiers[$dossier] = array_values($this->dossiers[$dossier]);
        $this->dossiers[$cible] ??= [];
        $this->dossiers[$cible][] = $message;
    }

    /**
     * État observable de la boîte après traitement, mêmes compteurs que les assertions Paperless : nombre de
     * messages en réception et en spam, non lus, non signalés, et messages sans mot-clé (« étiquetables »).
     *
     * @return array{inbox:int, spam:int, non_lus:int, non_signales:int, etiquettes:int}
     */
    public function etat(string $dossierReception = 'INBOX', string $dossierSpam = 'spam'): array
    {
        $inbox = $this->messages($dossierReception);

        return [
            'inbox' => count($inbox),
            'spam' => count($this->messages($dossierSpam)),
            'non_lus' => count(array_filter($inbox, static fn (Message $m): bool => !$m->vu)),
            'non_signales' => count(array_filter($inbox, static fn (Message $m): bool => !$m->signale)),
            'etiquettes' => count(array_filter($inbox, static fn (Message $m): bool => $m->motsCles === [])),
        ];
    }

    public function tester(): void
    {
    }

    public function fermer(): void
    {
    }
}
