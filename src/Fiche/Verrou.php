<?php

declare(strict_types=1);

namespace Ged\Fiche;

use Ged\Base;
use Ged\Config;
use Ged\Socle\DepotFichiers;
use Ged\Socle\Fichiers;
use RuntimeException;
use Throwable;

/**
 * VERROUILLAGE / CHECK-OUT d'un objet : un compte réserve un objet en cours d'édition, le libère (ou un
 * administrateur le force). Équivalent GEDv2 de
 * `GET|PUT /objects/{type}/{objectid}/{version}/checkedout`.
 *
 * L'état vit dans le `.meta.json` à côté du fichier (le dossier fait foi) : il survit à une
 * reconstruction de l'index, aucun moteur parallèle. Le verrou a un délai (défaut 30 min) : expiré, il ne
 * bloque plus, ce qui évite un objet définitivement figé. Chaque geste est tracé dans
 * `document_collaboration` (type `evenement`) et visible dans l'historique de la fiche ; aucune donnée
 * n'est supprimée.
 */
final class Verrou
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{verrouille:bool, par:string, depuis:string, expire_le:string}
     *
     * @throws RuntimeException document inconnu
     */
    public function etat(int $idDocument): array
    {
        [$meta] = $this->lire($idDocument);

        return $this->etatDepuisMeta($meta);
    }

    /**
     * Pose (ou prolonge) le verrou au nom de `$auteur`. Rend l'état résultant.
     *
     * @return array{verrouille:bool, par:string, depuis:string, expire_le:string}
     *
     * @throws RuntimeException document inconnu / à la corbeille, verrou tenu par un autre compte
     */
    public function poser(int $idDocument, string $auteur, int $minutes = 30): array
    {
        $auteur = trim($auteur);
        if ($auteur === '') {
            throw new RuntimeException('verrou : l’auteur du verrouillage est requis');
        }
        $minutes = max(1, min(1440, $minutes));

        [$meta, $relatif, $absolu] = $this->lire($idDocument);
        if (class_exists(\Ged\Archivage\Garde::class)) {
            \Ged\Archivage\Garde::interdireMutation($absolu, 'verrouiller (check-out)', $idDocument);
        }

        $etat = $this->etatDepuisMeta($meta);
        if ($etat['verrouille'] && $etat['par'] !== $auteur) {
            throw new RuntimeException(sprintf(
                'verrou : document déjà verrouillé par %s depuis %s (check-out en cours)',
                $etat['par'],
                $etat['depuis']
            ));
        }

        $maintenant = new \DateTimeImmutable('now');
        $meta['verrou'] = [
            'par' => $auteur,
            'depuis' => $maintenant->format(\DateTimeInterface::ATOM),
            'expire_le' => $maintenant->modify('+' . $minutes . ' minutes')->format(\DateTimeInterface::ATOM),
        ];
        $this->ecrire($relatif, $meta);
        $this->tracer($idDocument, 'verrou posé', $auteur);

        return $this->etatDepuisMeta($meta);
    }

    /**
     * Libère le verrou. `$force` (administrateur) lève le verrou d'un autre compte ; sinon seul le
     * détenteur peut le faire. Sans verrou actif, l'appel est sans effet (idempotent).
     *
     * @throws RuntimeException document inconnu, verrou tenu par un autre compte sans force
     */
    public function retirer(int $idDocument, string $auteur, bool $force = false): void
    {
        [$meta, $relatif] = $this->lire($idDocument);
        $etat = $this->etatDepuisMeta($meta);
        if (!$etat['verrouille']) {
            return;
        }
        if ($etat['par'] !== $auteur && !$force) {
            throw new RuntimeException(sprintf(
                'verrou : document verrouillé par %s ; seul ce compte peut le libérer (ou un administrateur)',
                $etat['par']
            ));
        }

        unset($meta['verrou']);
        $this->ecrire($relatif, $meta);
        $this->tracer($idDocument, 'verrou levé', $auteur);
    }

    /**
     * Lit le document et son `.meta.json` canonique. Rend `[meta, chemin relatif, chemin absolu]`.
     *
     * @return array{0:array<string,mixed>, 1:string, 2:string}
     */
    private function lire(int $idDocument): array
    {
        $depot = new DepotFichiers($this->config);
        $ligne = $depot->fiche($idDocument);
        if ($ligne === null) {
            throw new RuntimeException("verrou : document $idDocument inconnu");
        }
        if ($ligne['supprime_le'] !== null) {
            throw new RuntimeException("verrou : le document $idDocument est à la corbeille, le restaurer d’abord");
        }
        $relatif = (string) $ligne['chemin_relatif'];
        $absolu = $depot->cheminAbsolu($relatif);

        $meta = [];
        if (is_file($absolu . '.meta.json')) {
            $decode = json_decode((string) file_get_contents($absolu . '.meta.json'), true);
            $meta = is_array($decode) ? $decode : [];
        }

        return [$meta, $relatif, $absolu];
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{verrouille:bool, par:string, depuis:string, expire_le:string}
     */
    private function etatDepuisMeta(array $meta): array
    {
        $verrou = is_array($meta['verrou'] ?? null) ? $meta['verrou'] : [];
        $par = (string) ($verrou['par'] ?? '');
        $depuis = (string) ($verrou['depuis'] ?? '');
        $expire = (string) ($verrou['expire_le'] ?? '');
        $verrouille = $par !== '' && $this->actif($expire);

        return ['verrouille' => $verrouille, 'par' => $verrouille ? $par : '', 'depuis' => $verrouille ? $depuis : '', 'expire_le' => $verrouille ? $expire : ''];
    }

    /** Un verrou sans échéance lisible tient (jamais de libération silencieuse) ; un verrou daté passé ne tient plus. */
    private function actif(string $expireLe): bool
    {
        if ($expireLe === '') {
            return true;
        }
        try {
            return new \DateTimeImmutable($expireLe) > new \DateTimeImmutable('now');
        } catch (Throwable $e) {
            error_log('verrou : échéance illisible « ' . $expireLe . ' » — ' . $e->getMessage());

            return true;
        }
    }

    /** @param array<string, mixed> $meta */
    private function ecrire(string $relatif, array $meta): void
    {
        $depot = new DepotFichiers($this->config);
        $absolu = $depot->cheminAbsolu($relatif);
        $fichier = $absolu . '.meta.json';
        $temporaire = $fichier . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporaire, Fichiers::json($meta, true)) === false) {
            @unlink($temporaire);
            throw new RuntimeException('verrou : écriture des métadonnées impossible');
        }
        if (!rename($temporaire, $fichier)) {
            @unlink($temporaire);
            throw new RuntimeException('verrou : remplacement des métadonnées impossible');
        }
        $depot->rapporterMeta($relatif, $meta);
    }

    private function tracer(int $idDocument, string $action, string $auteur): void
    {
        try {
            Base::pdo($this->config)
                ->prepare("INSERT INTO document_collaboration (document_id, type, action, auteur) VALUES (?, 'evenement', ?, ?)")
                ->execute([$idDocument, $action, $auteur]);
        } catch (\PDOException $e) {
            // Le verrou reste valide (il vit dans le dossier) ; la trace d'index manque, la cause est journalisée.
            error_log(sprintf('[GEDv2 fiche] verrou : trace « %s » impossible pour le document %d : %s', $action, $idDocument, $e->getMessage()));
        }
    }
}
