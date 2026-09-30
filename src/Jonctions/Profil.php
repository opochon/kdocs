<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Adresse mail du profil d'un utilisateur (section 1 v2 du contrat CMM). Une adresse n'est utilisable
 * pour relier une application qu'une fois VÉRIFIÉE par un lien envoyé à cette adresse : la GED ne fait
 * jamais confiance à une adresse simplement saisie.
 *
 * Le jeton du lien n'est gardé qu'en empreinte sha256 ; il est à usage unique et expire une heure.
 * La table `jonctions_profils` porte aussi l'appairage en attente (voir `Appairage`) : le budget de
 * tables est serré (plafond V2-04) et ces objets partagent le même compte.
 */
final class Profil
{
    public const DUREE_VERIFICATION_S = 3600;

    public function __construct(private readonly PDO $pdo, private readonly Mailer $mailer)
    {
    }

    /** @return array{adresse:?string, verifiee:bool} */
    public function etat(?int $compteId, string $identifiant): array
    {
        $ligne = $this->ligne($compteId, $identifiant);

        return [
            'adresse' => $ligne === null || $ligne['adresse'] === null ? null : (string) $ligne['adresse'],
            'verifiee' => $ligne !== null && $ligne['verifie_le'] !== null,
        ];
    }

    public function estVerifiee(?int $compteId, string $identifiant): bool
    {
        return $this->etat($compteId, $identifiant)['verifiee'];
    }

    /**
     * Enregistre l'adresse (elle repasse non vérifiée si elle change) et envoie le lien de vérification.
     * Un nouveau lien remplace le précédent : un seul est en attente.
     *
     * @throws \InvalidArgumentException adresse absente ou invalide
     * @throws \RuntimeException le courriel de vérification n'a pas pu être remis
     */
    public function definirAdresse(?int $compteId, string $identifiant, string $adresse, ?int $maintenant = null): string
    {
        $identifiant = trim($identifiant);
        if ($identifiant === '') {
            throw new \InvalidArgumentException('identifiant de profil requis');
        }
        $adresse = trim($adresse);
        if ($adresse === '' || filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('adresse électronique invalide');
        }
        $maintenant ??= time();

        $jeton = bin2hex(random_bytes(32));
        $expire = date('Y-m-d H:i:s', $maintenant + self::DUREE_VERIFICATION_S);
        $this->pdo->prepare('INSERT INTO jonctions_profils (compte_id, identifiant, adresse, verifie_le, verif_empreinte, verif_expire_le, verif_utilise_le)'
            . ' VALUES (?, ?, ?, NULL, ?, ?, NULL)'
            . ' ON DUPLICATE KEY UPDATE compte_id = VALUES(compte_id), adresse = VALUES(adresse), verifie_le = NULL,'
            . ' verif_empreinte = VALUES(verif_empreinte), verif_expire_le = VALUES(verif_expire_le), verif_utilise_le = NULL')
            ->execute([$compteId, $identifiant, $adresse, hash('sha256', $jeton), $expire]);

        $this->mailer->envoyerVerification(
            $adresse,
            $this->mailer->gedUrl() . '/profil/mail/verifier?jeton=' . $jeton,
            date(DATE_ATOM, (int) strtotime($expire)),
        );

        return $adresse;
    }

    /**
     * Consomme le lien de vérification et marque l'adresse du profil comme vérifiée. Rend l'adresse.
     *
     * @throws ErreurApi 400 nommé (lien absent, inconnu, déjà utilisé ou expiré)
     */
    public function verifier(string $jeton, ?int $maintenant = null): string
    {
        $etape = 'GET /profil/mail/verifier';
        $jeton = trim($jeton);
        if ($jeton === '') {
            throw new ErreurApi(400, 'lien de vérification requis', $etape);
        }
        $maintenant ??= time();
        $date = date('Y-m-d H:i:s', $maintenant);
        $empreinte = hash('sha256', $jeton);

        $requete = $this->pdo->prepare('SELECT identifiant, adresse, verif_utilise_le, verif_expire_le'
            . ' FROM jonctions_profils WHERE verif_empreinte = ? LIMIT 1');
        $requete->execute([$empreinte]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);
        if ($ligne === false || $ligne['adresse'] === null) {
            throw new ErreurApi(400, 'lien de vérification inconnu', $etape);
        }
        if ($ligne['verif_utilise_le'] !== null) {
            throw new ErreurApi(400, 'lien de vérification déjà utilisé', $etape);
        }
        if ($ligne['verif_expire_le'] === null || (string) $ligne['verif_expire_le'] < $date) {
            throw new ErreurApi(400, 'lien de vérification expiré', $etape);
        }

        // Revendication atomique : un seul clic peut valider l'adresse.
        $revendique = $this->pdo->prepare('UPDATE jonctions_profils SET verif_utilise_le = ?, verifie_le = ?'
            . ' WHERE verif_empreinte = ? AND verif_utilise_le IS NULL');
        $revendique->execute([$date, $date, $empreinte]);
        if ($revendique->rowCount() !== 1) {
            throw new ErreurApi(400, 'lien de vérification déjà utilisé', $etape);
        }

        return (string) $ligne['adresse'];
    }

    /** @return array<string, mixed>|null */
    private function ligne(?int $compteId, string $identifiant): ?array
    {
        if ($compteId !== null) {
            $requete = $this->pdo->prepare('SELECT adresse, verifie_le FROM jonctions_profils WHERE compte_id = ? OR identifiant = ? LIMIT 1');
            $requete->execute([$compteId, trim($identifiant)]);
        } else {
            $requete = $this->pdo->prepare('SELECT adresse, verifie_le FROM jonctions_profils WHERE identifiant = ? LIMIT 1');
            $requete->execute([trim($identifiant)]);
        }
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $ligne;
    }
}
