<?php

declare(strict_types=1);

namespace Ged\Apercu;

use Ged\Config;
use Ged\Ui\Vue;
use Psr\Http\Message\ResponseInterface as Reponse;
use Psr\Http\Message\ServerRequestInterface as Requete;
use Slim\App;
use Slim\Exception\HttpNotFoundException;

/**
 * Routes du secteur Recherche & aperçu. Chaque route déclare `document.voir` (middleware Permission) :
 * le secteur Droits refusera tout ce qui n'a pas de permission, et l'aperçu n'est pas une exception.
 *
 * Enregistré automatiquement par `src/Kernel.php` : aucune modification du Kernel n'est nécessaire.
 */
final class Module
{
    /** @param App<\Psr\Container\ContainerInterface> $app */
    public static function enregistrer(App $app, Config $config, Vue $vue): void
    {
        $source = Source::depuis($config);
        $cache = Miniature::dossier($source);

        // Écran d'aperçu : le vrai document remplace la page de papier des maquettes quand un fichier existe.
        $app->get('/documents/{id:[0-9]+}/apercu', function (Requete $req, Reponse $rep, array $args) use ($source, $vue): Reponse {
            $piece = self::piece($req, $source, $args);

            return $vue->rendre($rep, 'partiels/visionneuse.twig', [
                'autonome' => true,
                'piece' => self::vuePiece($piece),
                'rendu' => self::rendu($piece),
            ]);
        })->add(new Permission());

        // Contenu affichable par la visionneuse : le PDF, l'image, ou leur conversion hors requête.
        $app->get('/documents/{id:[0-9]+}/apercu/fichier', function (Requete $req, Reponse $rep, array $args) use ($source, $cache): Reponse {
            return self::contenu($rep, self::piece($req, $source, $args), $cache);
        })->add(new Permission());

        // Téléchargement du fichier original, jamais transformé.
        $app->get('/documents/{id:[0-9]+}/fichier', function (Requete $req, Reponse $rep, array $args) use ($source): Reponse {
            $piece = self::piece($req, $source, $args);

            return self::servir($rep, $piece->chemin, self::mime($piece), 'attachment; filename="' . addslashes($piece->nom()) . '"');
        })->add(new Permission());

        // Vignette de première page : servie telle quelle, jamais générée dans la requête.
        $app->get('/documents/{id:[0-9]+}/miniature', function (Requete $req, Reponse $rep, array $args) use ($source, $cache): Reponse {
            $piece = self::piece($req, $source, $args);
            $vignette = Miniature::cheminVignette($piece, $cache);
            if (!is_file($vignette)) {
                return self::erreur($rep, 404, Outils::MSG_VIGNETTE);
            }

            return self::servir($rep, $vignette, 'image/png');
        })->add(new Permission());
    }

    /** @param array<string, string> $args */
    private static function piece(Requete $req, Source $source, array $args): Piece
    {
        $piece = $source->parId((int) ($args['id'] ?? 0));
        if ($piece === null || !$piece->existe()) {
            throw new HttpNotFoundException($req, 'Aperçu : document ' . (string) ($args['id'] ?? '?') . ' introuvable.');
        }

        return $piece;
    }

    /**
     * Ce que la visionneuse doit charger. Un format non rendable donne un message nommé, jamais un cadre vide.
     *
     * @return array{url:string, categorie:string, pages:int, titre:string, message:string}
     */
    private static function rendu(Piece $piece): array
    {
        $url = '/documents/' . $piece->id . '/apercu/fichier';
        if ($piece->estPdf()) {
            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $piece->pages, 'titre' => $piece->titre, 'message' => ''];
        }
        if ($piece->estImage()) {
            return ['url' => $url, 'categorie' => 'image', 'pages' => 1, 'titre' => $piece->titre, 'message' => ''];
        }
        if ($piece->estMessage()) {
            // Le message n'est pas une ressource à charger : le HTML est nettoyé ici et rendu en ligne.
            return ['url' => '', 'categorie' => 'message', 'pages' => 1, 'titre' => $piece->titre, 'message' => '', 'html' => self::messageHtml($piece)];
        }
        if ($piece->estBureautique()) {
            if (Outils::libreOffice() === null) {
                return ['url' => '', 'categorie' => 'bureautique', 'pages' => $piece->pages, 'titre' => $piece->titre, 'message' => Outils::MSG_LIBREOFFICE];
            }

            return ['url' => $url, 'categorie' => 'pdf', 'pages' => $piece->pages, 'titre' => $piece->titre, 'message' => ''];
        }

        return ['url' => '', 'categorie' => 'inconnu', 'pages' => 0, 'titre' => $piece->titre, 'message' => Outils::MSG_FORMAT];
    }

    private static function contenu(Reponse $rep, Piece $piece, string $cache): Reponse
    {
        if ($piece->estPdf()) {
            return self::servir($rep, $piece->chemin, 'application/pdf');
        }
        if ($piece->estImage()) {
            if ($piece->estTiff()) {
                // Un navigateur n'affiche pas le TIFF : on sert la conversion PNG faite hors requête.
                $png = Miniature::cheminApercuImage($piece, $cache);
                if (!is_file($png)) {
                    $png = Miniature::cheminVignette($piece, $cache);
                }
                if (!is_file($png)) {
                    return self::erreur($rep, 501, Outils::MSG_VIGNETTE);
                }

                return self::servir($rep, $png, 'image/png');
            }

            return self::servir($rep, $piece->chemin, self::mime($piece));
        }
        if ($piece->estMessage()) {
            $rep->getBody()->write(self::messageHtml($piece));

            return $rep->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        }
        if ($piece->estBureautique()) {
            if (Outils::libreOffice() === null) {
                return self::erreur($rep, 501, Outils::MSG_LIBREOFFICE);
            }
            $pdf = Miniature::cheminApercuPdf($piece, $cache);
            if (!is_file($pdf)) {
                $raison = Outils::versPdf($piece->chemin, $pdf);
                if ($raison !== null) {
                    return self::erreur($rep, 501, $raison);
                }
            }

            return self::servir($rep, $pdf, 'application/pdf');
        }

        return self::erreur($rep, 415, Outils::MSG_FORMAT);
    }

    private static function servir(Reponse $rep, string $chemin, string $type, ?string $disposition = null): Reponse
    {
        $rep->getBody()->write((string) file_get_contents($chemin));
        $rep = $rep->withHeader('Content-Type', $type)->withHeader('Cache-Control', 'private, max-age=0, must-revalidate');
        if ($disposition !== null) {
            $rep = $rep->withHeader('Content-Disposition', $disposition);
        }

        return $rep;
    }

    private static function erreur(Reponse $rep, int $code, string $cause): Reponse
    {
        $reference = substr(hash('sha256', 'apercu:' . $cause . microtime()), 0, 10);
        $rep->getBody()->write((string) json_encode([
            'etape' => 'GET /documents/{id}/…',
            'cause' => $cause,
            'reference' => $reference,
        ], JSON_UNESCAPED_UNICODE));

        return $rep->withStatus($code)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private static function mime(Piece $piece): string
    {
        return match ($piece->extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            'tif', 'tiff' => 'image/tiff',
            'eml' => 'message/rfc822',
            default => 'application/octet-stream',
        };
    }

    /**
     * Rendu HTML NETTOYÉ d'un message `.eml` : le lecteur vit dans le secteur Jonctions (source de la
     * jonction ClearMyMails) ; l'aperçu n'en affiche que le HTML assaini (aucun script, aucune ressource
     * distante). Branchage minimal signalé dans la fiche Aperçu.
     */
    private static function messageHtml(Piece $piece): string
    {
        if (!class_exists(\Ged\Jonctions\Courriel::class)) {
            return '<p class="visionneuse-message" data-testid="visionneuse-message">aperçu indisponible : lecture du message impossible</p>';
        }
        $courriel = \Ged\Jonctions\Courriel::lire($piece->chemin);
        if ($courriel === null || (string) $courriel['html'] === '') {
            return '<p class="visionneuse-message" data-testid="visionneuse-message">aperçu indisponible : message illisible</p>';
        }

        return (string) $courriel['html'];
    }

    /** @return array{id:int, titre:string, categorie:string, pages:int} */
    private static function vuePiece(Piece $piece): array
    {
        return ['id' => $piece->id, 'titre' => $piece->titre, 'categorie' => $piece->categorie, 'pages' => $piece->pages];
    }
}
