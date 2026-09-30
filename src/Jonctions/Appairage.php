<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Appairage d'une application tierce (ClearMyMails) à UN COMPTE — section 1 v2 du contrat : la liaison
 * part de la GED.
 *
 * La GED crée un appairage EN ATTENTE 24 h : un code d'échange à usage unique (aléa 256 bits, seule son
 * empreinte est gardée) et un code de vérification court, affiché à l'écran et repris dans le courriel
 * d'auto-configuration. CMM valide en renvoyant {code, verification, poste} ; un code de vérification faux
 * est refusé 400 sans consommer le code, et un code inconnu, expiré ou rejoué est refusé 400, nommé.
 *
 * Le profil porte au plus UN appairage en attente (`jonctions_profils`) : un nouvel appairage remplace le
 * précédent. La table des jetons (`jonctions_jetons`) reste seule à porter les jetons réellement remis.
 */
final class Appairage
{
    public const DUREE_APPAIRAGE_S = 86400;
    /** Alphabet sans caractère ambigu (ni O/0, ni I/1, ni L). */
    public const ALPHABET_VERIFICATION = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private readonly Jetons $jetons,
        private readonly PDO $pdo,
        private readonly Dossiers $dossiers,
        private readonly Profil $profil,
        private readonly Mailer $mailer,
        private readonly string $gedNom = 'GEDv2',
    ) {
    }

    /** Normalise un code de vérification : majuscules, séparateurs retirés (« k7m 4qx » → « K7M4QX »). */
    public static function normaliserVerification(string $verification): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $verification));
    }

    /**
     * Ouvre un appairage EN ATTENTE pour le compte, à partir de l'adresse VÉRIFIÉE du profil, envoie le
     * courriel d'auto-configuration et rend le code de vérification à afficher à l'écran.
     *
     * @return array{id:int, verification:string, expire_le:string, adresse:string}
     * @throws \InvalidArgumentException profil sans adresse vérifiée
     * @throws ErreurApi le courriel n'a pas pu être remis
     */
    public function ouvrir(?int $compteId, string $compteIdentifiant, ?int $maintenant = null): array
    {
        $compteIdentifiant = trim($compteIdentifiant);
        if ($compteIdentifiant === '') {
            throw new \InvalidArgumentException('compte d’appairage requis');
        }
        $etat = $this->profil->etat($compteId, $compteIdentifiant);
        $adresse = $etat['adresse'];
        if ($adresse === null || !$etat['verifiee']) {
            throw new \InvalidArgumentException('adresse électronique du profil à vérifier avant de relier ClearMyMails');
        }
        $maintenant ??= time();

        $code = bin2hex(random_bytes(32));
        $verification = self::verificationAleatoire();
        $expire = $maintenant + self::DUREE_APPAIRAGE_S;

        // L'appairage en attente vit sur le profil, qui porte déjà l'adresse vérifiée : un nouvel
        // appairage remplace le précédent (une seule demande valable à la fois).
        $pose = $this->pdo->prepare('UPDATE jonctions_profils SET appairage_application = ?, appairage_code = ?,'
            . ' appairage_verification = ?, appairage_adresse = ?, appairage_poste = NULL, appairage_cree_le = ?,'
            . ' appairage_expire_le = ?, appairage_utilise_le = NULL, appairage_jeton_id = NULL'
            . ' WHERE ' . ($compteId !== null ? 'compte_id = ?' : 'compte_id IS NULL AND identifiant = ?') . ' AND verifie_le IS NOT NULL');
        $pose->execute(['cmm', hash('sha256', $code), $verification, $adresse, date('Y-m-d H:i:s', $maintenant),
            date('Y-m-d H:i:s', $expire), $compteId ?? $compteIdentifiant]);
        if ($pose->rowCount() !== 1) {
            throw new \InvalidArgumentException('adresse électronique du profil à vérifier avant de relier ClearMyMails');
        }

        try {
            $this->mailer->envoyerAppairage($adresse, $compteIdentifiant, $code, $verification, date(DATE_ATOM, $expire));
        } catch (\Throwable $e) {
            // Le courriel n'est jamais parti : l'appairage n'atteint pas l'utilisateur. On le retire de
            // l'écran SANS supprimer la ligne (règle « zéro suppression ») : il est marqué utilisé.
            $this->pdo->prepare('UPDATE jonctions_profils SET appairage_utilise_le = NOW() WHERE appairage_code = ?')
                ->execute([hash('sha256', $code)]);
            throw new ErreurApi(502, 'le courriel d’auto-configuration n’a pas pu être envoyé', 'POST /profil/appairage');
        }

        $idRequete = $this->pdo->prepare('SELECT id FROM jonctions_profils WHERE appairage_code = ? LIMIT 1');
        $idRequete->execute([hash('sha256', $code)]);

        return ['id' => (int) $idRequete->fetchColumn(), 'verification' => $verification, 'expire_le' => date('Y-m-d H:i:s', $expire), 'adresse' => $adresse];
    }

    /**
     * Échange {code, verification, poste} contre un jeton lié au compte. Le code est à usage unique ; un
     * code de vérification faux ne le consomme pas.
     *
     * @return array{jeton:string, compte:string, ged_nom:string, expire_le:null}
     * @throws ErreurApi 400 nommé
     */
    public function echanger(string $code, string $verification, string $poste, ?int $maintenant = null): array
    {
        $etape = 'POST /api/v1/appairage/jeton';
        $code = trim($code);
        if ($code === '') {
            throw new ErreurApi(400, 'code d’appairage requis', $etape);
        }
        $poste = mb_substr(trim($poste), 0, 255);
        if ($poste === '') {
            throw new ErreurApi(400, 'poste requis', $etape);
        }
        $verification = trim($verification);
        if ($verification === '') {
            throw new ErreurApi(400, 'code de vérification requis', $etape);
        }
        $maintenant ??= time();
        $date = date('Y-m-d H:i:s', $maintenant);
        $empreinte = hash('sha256', $code);

        $ligne = $this->ligneAppairage($empreinte);
        if ($ligne === null) {
            throw new ErreurApi(400, 'code d’appairage inconnu', $etape);
        }
        if ($ligne['appairage_utilise_le'] !== null) {
            throw new ErreurApi(400, 'code d’appairage déjà utilisé', $etape);
        }
        if ($ligne['appairage_expire_le'] === null || (string) $ligne['appairage_expire_le'] < $date) {
            throw new ErreurApi(400, 'code d’appairage expiré', $etape);
        }
        if (!hash_equals(self::normaliserVerification((string) $ligne['appairage_verification']), self::normaliserVerification($verification))) {
            throw new ErreurApi(400, 'code de vérification invalide', $etape);
        }

        // Revendication atomique : un seul échange peut passer, une course est refusée comme un rejeu.
        $revendique = $this->pdo->prepare('UPDATE jonctions_profils SET appairage_utilise_le = ?, appairage_poste = ?'
            . ' WHERE appairage_code = ? AND appairage_utilise_le IS NULL');
        $revendique->execute([$date, $poste, $empreinte]);
        if ($revendique->rowCount() !== 1) {
            throw new ErreurApi(400, 'code d’appairage déjà utilisé', $etape);
        }

        $identifiant = (string) $ligne['identifiant'];
        $compteId = $ligne['compte_id'] === null ? null : (int) $ligne['compte_id'];
        $jeton = $this->jetons->creerAppairage((string) $ligne['appairage_application'], $poste, $compteId, $identifiant);
        $this->pdo->prepare('UPDATE jonctions_profils SET appairage_jeton_id = (SELECT id FROM jonctions_jetons WHERE empreinte = ?) WHERE appairage_code = ?')
            ->execute([hash('sha256', $jeton), $empreinte]);

        return ['jeton' => $jeton, 'compte' => $identifiant, 'ged_nom' => $this->gedNom, 'expire_le' => null];
    }

    /**
     * Appairages en attente (non expirés, non échangés) du compte : ce que l'écran Mon profil affiche.
     *
     * @return list<array{id:int, verification:string, adresse:string, expire_le:string}>
     */
    public function enAttente(?int $compteId, string $compteIdentifiant, ?int $maintenant = null): array
    {
        $maintenant ??= time();
        $requete = $this->pdo->prepare('SELECT id, appairage_verification, appairage_adresse, appairage_expire_le'
            . ' FROM jonctions_profils WHERE appairage_code IS NOT NULL AND appairage_utilise_le IS NULL AND appairage_expire_le >= ?'
            . ' AND ' . ($compteId !== null ? 'compte_id = ?' : 'compte_id IS NULL AND identifiant = ?')
            . ' ORDER BY id DESC');
        $requete->execute([date('Y-m-d H:i:s', $maintenant), $compteId ?? trim($compteIdentifiant)]);

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'verification' => (string) $l['appairage_verification'],
            'adresse' => (string) $l['appairage_adresse'],
            'expire_le' => (string) $l['appairage_expire_le'],
        ], $requete->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Ce que CMM voit de sa liaison : identité, droit d'ingestion et nombre de dossiers où écrire.
     *
     * @param array<string, mixed> $jeton ligne résolue par `Jetons::resoudre`
     * @return array{compte:string, appareil:?string, ged_nom:string, droits:array{ingestion:bool}, dossiers_ecriture:int}
     */
    public function moi(array $jeton): array
    {
        $portee = (string) ($jeton['portee'] ?? '');
        $compteId = isset($jeton['compte_id']) && is_int($jeton['compte_id']) ? $jeton['compte_id'] : null;

        return [
            'compte' => (string) ($jeton['compte_identifiant'] ?? ''),
            'appareil' => $jeton['appareil'] ?? null,
            'ged_nom' => $this->gedNom,
            'droits' => ['ingestion' => in_array($portee, [Jetons::PORTEE_DEPOT, '*'], true)],
            'dossiers_ecriture' => $this->dossiers->compterEcriture($compteId),
        ];
    }

    /** Un code de vérification court, lisible et sans caractère ambigu, du type `K7M-4QX`. */
    private static function verificationAleatoire(): string
    {
        $alphabet = self::ALPHABET_VERIFICATION;
        $bloc = '';
        for ($i = 0; $i < 6; ++$i) {
            $bloc .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($bloc, 0, 3) . '-' . substr($bloc, 3);
    }

    /** @return array<string, mixed>|null */
    private function ligneAppairage(string $empreinte): ?array
    {
        $requete = $this->pdo->prepare('SELECT identifiant, compte_id, appairage_application, appairage_verification,'
            . ' appairage_utilise_le, appairage_expire_le FROM jonctions_profils WHERE appairage_code = ? LIMIT 1');
        $requete->execute([$empreinte]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $ligne;
    }
}
