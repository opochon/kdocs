<?php
/**
 * Portail client — lecture seule (GAP-042).
 *
 * GET /portal/{client} affiche la liste des documents du correspondant.
 * Aucun bouton/lien/formulaire d'édition, suppression ou upload n'est présent :
 * le HTML produit ne contient pas les chaînes edit, delete, supprimer, modifier
 * ni aucune balise <form>.
 *
 * Service injectable via makePortalService() pour les tests hermétiques.
 */

namespace KDocs\Apps\Portal\Controllers;

use KDocs\Apps\Portal\Services\PortalService;

class PortalController
{
    /**
     * Factory PortalService — surchargeable dans les tests pour injecter SQLite.
     */
    protected function makePortalService(): PortalService
    {
        return new PortalService();
    }

    /**
     * GET /portal/{client} — liste des documents du correspondant.
     *
     * @param object      $request  Requête PSR-7 (ou mock compatible)
     * @param object      $response Réponse PSR-7 (ou mock compatible)
     * @param array       $args     Paramètres de route (clé 'client')
     * @return object Réponse : 200 HTML liste, 404 si correspondant inconnu
     */
    public function show(object $request, object $response, array $args = []): object
    {
        $clientSlug = $args['client'] ?? '';
        $service    = $this->makePortalService();

        $client = $service->getClientByName($clientSlug);
        if ($client === null) {
            $r = $response->withStatus(404);
            $r->getBody()->write(
                '<!DOCTYPE html><html><body><p>Client introuvable.</p></body></html>'
            );
            return $r;
        }

        $documents = $service->getDocumentsForClient((int) $client['id']);
        $html      = $this->buildHtml($client, $documents);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Construit le HTML minimal — lecture seule, sans action d'édition.
     *
     * @param array<string, mixed>                           $client
     * @param list<array{id: int, title: string, created_at: string}> $documents
     */
    private function buildHtml(array $client, array $documents): string
    {
        $clientName = htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($documents as $doc) {
            $title = htmlspecialchars($doc['title'] ?? '(sans titre)', ENT_QUOTES, 'UTF-8');
            $date  = htmlspecialchars($doc['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
            $rows .= "<tr><td>{$title}</td><td>{$date}</td></tr>\n";
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="2">Aucun document.</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Portail — {$clientName}</title>
</head>
<body>
<h1>Documents — {$clientName}</h1>
<table>
  <thead><tr><th>Titre</th><th>Date</th></tr></thead>
  <tbody>
{$rows}  </tbody>
</table>
</body>
</html>
HTML;
    }
}
