<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use PDO;

/**
 * Appairage d'une application tierce (ClearMyMails) à UN COMPTE (section 1 du contrat CMM).
 *
 * La GED remet un code à usage unique, valable cinq minutes, par le retour de l'application. Le code
 * s'échange une fois contre un jeton lié au compte (`Jetons::creerAppairage`). Un code inconnu, déjà
 * utilisé ou expiré est refusé 400, nommé.
 */
final class Appairage
{
    public const DUREE_CODE_S = 300;

    public function __construct(
        private readonly Jetons $jetons,
        private readonly PDO $pdo,
        private readonly Dossiers $dossiers,
        private readonly string $gedNom = 'GEDv2',
    ) {
    }

    /** Le retour CMM est-il une URL absolue http(s) ? (évite une redirection non contrôlée) */
    public static function estRetourValide(string $retour): bool
    {
        $retour = trim($retour);
        if (filter_var($retour, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($retour, PHP_URL_SCHEME)), ['http', 'https'], true)
            && (string) parse_url($retour, PHP_URL_HOST) !== '';
    }

    /**
     * Ouvre une demande d'appairage : crée le code et rend l'URL de retour à suivre (état + code).
     *
     * @throws \InvalidArgumentException retour ou application invalide
     */
    public function ouvrir(string $application, string $appareil, string $retour, string $etat, ?int $compteId, string $compteIdentifiant, ?int $maintenant = null): string
    {
        $application = trim($application);
        if ($application === '' || mb_strlen($application) > 100) {
            throw new \InvalidArgumentException('application d’appairage requise (1 à 100 caractères)');
        }
        $compteIdentifiant = trim($compteIdentifiant);
        if ($compteIdentifiant === '') {
            throw new \InvalidArgumentException('compte d’appairage requis');
        }
        $retour = trim($retour);
        if (!self::estRetourValide($retour)) {
            throw new \InvalidArgumentException('adresse de retour invalide (URL absolue http(s) attendue)');
        }
        $maintenant ??= time();

        $code = bin2hex(random_bytes(24));
        $this->pdo->prepare('INSERT INTO jonctions_codes_appairage (empreinte, compte_id, compte_identifiant, application, appareil, portee, cree_le, expire_le)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                hash('sha256', $code), $compteId, $compteIdentifiant, $application,
                mb_substr(trim($appareil), 0, 255), Jetons::PORTEE_DEPOT,
                date('Y-m-d H:i:s', $maintenant), date('Y-m-d H:i:s', $maintenant + self::DUREE_CODE_S),
            ]);

        return self::avecParametres($retour, ['etat' => $etat, 'code' => $code]);
    }

    /**
     * Échange un code contre un jeton lié au compte. Usage unique.
     *
     * @return array{jeton:string, compte:string, ged_nom:string, expire_le:null}
     * @throws ErreurApi 400 nommé
     */
    public function echanger(string $code, ?int $maintenant = null): array
    {
        $etape = 'POST /api/v1/appairage/jeton';
        $code = trim($code);
        if ($code === '') {
            throw new ErreurApi(400, 'code d’appairage requis', $etape);
        }
        $maintenant ??= time();
        $date = date('Y-m-d H:i:s', $maintenant);
        $empreinte = hash('sha256', $code);

        // Revendication atomique : un seul échange peut passer.
        $revendique = $this->pdo->prepare('UPDATE jonctions_codes_appairage SET utilise_le = ? WHERE empreinte = ? AND utilise_le IS NULL AND expire_le >= ?');
        $revendique->execute([$date, $empreinte, $date]);
        $ligne = $this->ligneCode($empreinte);
        if ($revendique->rowCount() !== 1) {
            if ($ligne === null) {
                throw new ErreurApi(400, 'code d’appairage inconnu', $etape);
            }
            if ($ligne['utilise_le'] !== null) {
                throw new ErreurApi(400, 'code d’appairage déjà utilisé', $etape);
            }
            throw new ErreurApi(400, 'code d’appairage expiré', $etape);
        }
        if ($ligne === null) {
            throw new ErreurApi(400, 'code d’appairage inconnu', $etape);
        }

        $jeton = $this->jetons->creerAppairage(
            (string) $ligne['application'], (string) $ligne['appareil'],
            $ligne['compte_id'] === null ? null : (int) $ligne['compte_id'], (string) $ligne['compte_identifiant'],
        );

        return ['jeton' => $jeton, 'compte' => (string) $ligne['compte_identifiant'], 'ged_nom' => $this->gedNom, 'expire_le' => null];
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

    /** @return array<string, mixed>|null */
    private function ligneCode(string $empreinte): ?array
    {
        $requete = $this->pdo->prepare('SELECT application, appareil, compte_id, compte_identifiant, utilise_le, expire_le'
            . ' FROM jonctions_codes_appairage WHERE empreinte = ? LIMIT 1');
        $requete->execute([$empreinte]);
        $ligne = $requete->fetch(PDO::FETCH_ASSOC);

        return $ligne === false ? null : $ligne;
    }

    /** @param array<string, string> $parametres */
    private static function avecParametres(string $url, array $parametres): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . http_build_query($parametres);
    }
}
