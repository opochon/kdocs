<?php

declare(strict_types=1);

namespace Ged\Ingestion\Imap;

/**
 * Client IMAP minimal (RFC 9051 / 3501), en PHP pur : l'extension imap n'existe plus en PHP 8.4 et un
 * hébergement mutualisé ne la fournit pas toujours. Seules les commandes utiles à l'adresse d'ingestion :
 * LOGIN, SELECT, UID SEARCH, UID FETCH (BODY.PEEK), UID STORE, UID MOVE (ou COPY si MOVE absent), CREATE.
 * Aucune commande d'effacement (EXPUNGE, \Deleted) n'est jamais envoyée.
 */
final class ImapBoite implements Boite
{
    /** @var resource */
    private $flux;
    private int $numero = 0;
    /** @var list<string> */
    private array $capacites = [];

    /**
     * @param string $hote       ex. mail.example.org
     * @param string $chiffrement ssl (port 993, défaut) | tcp (tests, serveur local)
     */
    public function __construct(
        private readonly string $hote,
        private readonly int $port,
        private readonly string $utilisateur,
        string $motDePasse,
        private readonly string $chiffrement = 'ssl',
        private readonly string $boiteReception = 'INBOX',
        int $delaiS = 30,
    ) {
        $contexte = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $schema = $this->chiffrement === 'starttls' ? 'tcp' : $this->chiffrement;
        $flux = @stream_socket_client("{$schema}://{$this->hote}:{$this->port}", $code, $message, $delaiS, STREAM_CLIENT_CONNECT, $contexte);
        if ($flux === false) {
            throw new ImapErreur("Connexion impossible à {$this->hote}:{$this->port} : $message ($code)");
        }
        stream_set_timeout($flux, $delaiS);
        $this->flux = $flux;

        $accueil = $this->ligne();
        if (!str_starts_with($accueil, '* OK')) {
            throw new ImapErreur('Serveur IMAP inattendu : ' . trim($accueil));
        }
        if ($this->chiffrement === 'starttls') {
            $this->commande('STARTTLS');
            if (stream_socket_enable_crypto($this->flux, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new ImapErreur('STARTTLS refusé par le serveur');
            }
        }
        $this->commande('LOGIN ' . self::chaine($utilisateur) . ' ' . self::chaine($motDePasse), '[identifiants masqués]');
        foreach ($this->commande('CAPABILITY')['lignes'] as $l) {
            if (str_starts_with($l, '* CAPABILITY ')) {
                $this->capacites = explode(' ', strtoupper(substr($l, 13)));
            }
        }
        $this->commande('SELECT ' . self::chaine($this->boiteReception));
    }

    public function nom(): string
    {
        return strtolower($this->utilisateur . '@' . $this->hote);
    }

    /** @return list<int> */
    public function nonLus(): array
    {
        $uids = [];
        foreach ($this->commande('UID SEARCH UNSEEN')['lignes'] as $l) {
            if (str_starts_with($l, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($l, 8))) ?: [] as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    public function lire(int $uid): string
    {
        $r = $this->commande("UID FETCH $uid (BODY.PEEK[])");
        if ($r['litteraux'] === []) {
            throw new ImapErreur("Message $uid introuvable");
        }

        return $r['litteraux'][0];
    }

    public function classer(int $uid, string $dossier): void
    {
        $nom = self::chaine($dossier);
        $this->commande("CREATE $nom", null, true);   // NO si le dossier existe déjà : sans importance
        $this->commande("UID STORE $uid +FLAGS.SILENT (\\Seen)");
        if (in_array('MOVE', $this->capacites, true)) {
            $this->commande("UID MOVE $uid $nom");
        } else {
            $this->commande("UID COPY $uid $nom");   // le message reste lu dans la réception : rien n'est effacé
        }
    }

    /** Sélectionne un dossier (boîte de réception, dossier de règle, corbeille). */
    public function select(string $dossier): void
    {
        $this->commande('SELECT ' . self::chaine($dossier));
    }

    /**
     * UID des messages du dossier courant correspondant au critère IMAP.
     *
     * @return list<int>
     */
    public function rechercher(string $critere = 'ALL'): array
    {
        $uids = [];
        foreach ($this->commande('UID SEARCH ' . $critere)['lignes'] as $ligne) {
            if (str_starts_with($ligne, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($ligne, 8))) ?: [] as $nombre) {
                    if (ctype_digit($nombre)) {
                        $uids[] = (int) $nombre;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /**
     * Drapeaux et mots-clés d'un message.
     *
     * @return array{vu:bool, signale:bool, mots_cles:list<string>}
     */
    public function drapeaux(int $uid): array
    {
        $vu = false;
        $signale = false;
        $motsCles = [];
        foreach ($this->commande("UID FETCH $uid (FLAGS)")['lignes'] as $ligne) {
            if (preg_match('/FLAGS \(([^)]*)\)/', $ligne, $trouve) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($trouve[1])) ?: [] as $drapeau) {
                if (strcasecmp($drapeau, '\\Seen') === 0) {
                    $vu = true;
                } elseif (strcasecmp($drapeau, '\\Flagged') === 0) {
                    $signale = true;
                } elseif ($drapeau !== '' && !str_starts_with($drapeau, '\\')) {
                    $motsCles[] = $drapeau;
                }
            }
        }

        return ['vu' => $vu, 'signale' => $signale, 'mots_cles' => $motsCles];
    }

    /** Pose ou retire un drapeau (jamais \Deleted : GEDv2 n'efface pas). */
    public function marquer(int $uid, string $drapeau, bool $valeur): void
    {
        $signe = $valeur ? '+' : '-';
        $this->commande('UID STORE ' . $uid . ' ' . $signe . 'FLAGS.SILENT (' . $drapeau . ')');
    }

    /** Déplace un message vers un dossier (CREATE puis MOVE, ou COPY si le serveur n'a pas MOVE). */
    public function deplacer(int $uid, string $dossier): void
    {
        $nom = self::chaine($dossier);
        $this->commande("CREATE $nom", null, true);
        if (in_array('MOVE', $this->capacites, true)) {
            $this->commande("UID MOVE $uid $nom");
        } else {
            $this->commande("UID COPY $uid $nom");
        }
    }

    /** Dossiers listés par le serveur (LIST "" "*").
     * @return list<string> */
    public function dossiers(): array
    {
        $dossiers = [];
        foreach ($this->commande('LIST "" "*"')['lignes'] as $ligne) {
            if (preg_match('/^\* LIST \([^)]*\)\s+(?:"[^"]*"|NIL)\s+"?([^"]*)"?$/', $ligne, $trouve) === 1) {
                $nom = rtrim(trim($trouve[1]), '"');
                if ($nom !== '') {
                    $dossiers[] = $nom;
                }
            }
        }

        return array_values(array_unique($dossiers));
    }

    public function fermer(): void
    {
        if (is_resource($this->flux)) {
            try {
                $this->commande('LOGOUT', null, true);
            } catch (ImapErreur) {
            }
            fclose($this->flux);
        }
    }

    public function __destruct()
    {
        $this->fermer();
    }

    /**
     * Envoie une commande étiquetée et lit la réponse jusqu'à l'étiquette, littéraux {n} compris.
     * @return array{lignes: list<string>, litteraux: list<string>}
     */
    private function commande(string $commande, ?string $journal = null, bool $toleree = false): array
    {
        $etiquette = 'G' . (++$this->numero);
        if (fwrite($this->flux, "$etiquette $commande\r\n") === false) {
            throw new ImapErreur('Écriture impossible vers le serveur IMAP');
        }
        $lignes = [];
        $litteraux = [];
        while (true) {
            $ligne = $this->ligne();
            while (preg_match('/\{(\d+)\}\r\n$/', $ligne, $m)) {
                $litteraux[] = $this->octets((int) $m[1]);
                $ligne = rtrim($ligne) . ' ' . $this->ligne();
            }
            if (str_starts_with($ligne, "$etiquette ")) {
                $etat = strtoupper(substr($ligne, strlen($etiquette) + 1, 2));
                if ($etat !== 'OK' && !$toleree) {
                    throw new ImapErreur(($journal ?? $commande) . ' : ' . trim($ligne));
                }

                return ['lignes' => $lignes, 'litteraux' => $litteraux];
            }
            $lignes[] = rtrim($ligne, "\r\n");
        }
    }

    private function ligne(): string
    {
        $l = fgets($this->flux);
        if ($l === false) {
            $meta = stream_get_meta_data($this->flux);
            throw new ImapErreur($meta['timed_out'] ? 'Délai IMAP dépassé' : 'Connexion IMAP fermée');
        }

        return $l;
    }

    private function octets(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $morceau = fread($this->flux, $n - strlen($data));
            if ($morceau === false || $morceau === '') {
                throw new ImapErreur('Message IMAP tronqué');
            }
            $data .= $morceau;
        }

        return $data;
    }

    /** Chaîne IMAP entre guillemets (les dossiers restent en ASCII : pas d'UTF-7 modifié à gérer). */
    private static function chaine(string $s): string
    {
        return '"' . addcslashes($s, "\\\"") . '"';
    }
}
