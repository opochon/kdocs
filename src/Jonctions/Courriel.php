<?php

declare(strict_types=1);

namespace Ged\Jonctions;

use ZBateson\MailMimeParser\Message;

/**
 * Un message RFC 822 (`.eml`) lu comme un document : en-têtes + corps textuel pour l'index, rendu HTML
 * NETTOYÉ pour l'aperçu (aucun script, aucune ressource distante, aucun gestionnaire d'événement), et
 * la liste des pièces jointes. Le fichier d'origine fait foi : rien n'est réécrit.
 */
final class Courriel
{
    /** Extensions traitées comme un message. */
    public const EXTENSIONS = ['eml'];

    public static function estMessage(string $nom): bool
    {
        return in_array(strtolower(pathinfo($nom, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * Lit un `.eml`.
     *
     * @return array{entetes:array{de:string,a:string,objet:string,date:string,message_id:string},
     *               texte:string, html:string,
     *               pieces:list<array{nom:string,type:string}>}|null
     */
    public static function lire(string $chemin): ?array
    {
        if (!is_file($chemin)) {
            return null;
        }
        $contenu = (string) file_get_contents($chemin);
        try {
            $message = Message::from($contenu, false);
        } catch (\Throwable) {
            return null;
        }

        $entetes = [
            'de' => self::adresse((string) ($message->getHeaderValue('from') ?? '')),
            'a' => self::adresse((string) ($message->getHeaderValue('to') ?? '')),
            'objet' => trim((string) ($message->getSubject() ?? '')),
            'date' => trim((string) ($message->getHeaderValue('date') ?? '')),
            'message_id' => trim((string) ($message->getHeaderValue('message-id') ?? '')),
        ];

        $corpsTexte = $message->getTextContent();
        if ($corpsTexte === null || trim($corpsTexte) === '') {
            $corpsHtml = $message->getHtmlContent();
            $corpsTexte = $corpsHtml === null ? '' : trim(html_entity_decode(strip_tags($corpsHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $pieces = [];
        foreach ($message->getAllAttachmentParts() as $partie) {
            $nom = trim((string) ($partie->getFilename() ?? ''));
            $pieces[] = [
                'nom' => $nom === '' ? 'pièce-jointe' : $nom,
                'type' => (string) ($partie->getContentType('application/octet-stream') ?? 'application/octet-stream'),
            ];
        }

        $texte = "De : {$entetes['de']}\nÀ : {$entetes['a']}\nDate : {$entetes['date']}\nObjet : {$entetes['objet']}\n\n" . (string) $corpsTexte;

        return [
            'entetes' => $entetes,
            'texte' => $texte,
            'html' => self::rendu($entetes, (string) $corpsTexte, $message->getHtmlContent(), $pieces),
            'pieces' => $pieces,
        ];
    }

    /**
     * HTML nettoyé prêt à afficher : en-têtes lisibles, corps sans script ni ressource, pièces listées.
     *
     * @param array{de:string, a:string, objet:string, date:string, message_id:string} $entetes
     * @param list<array{nom:string, type:string}> $pieces
     */
    private static function rendu(array $entetes, string $corpsTexte, ?string $corpsHtml, array $pieces): string
    {
        $lignes = '';
        foreach ([['De', $entetes['de']], ['À', $entetes['a']], ['Date', $entetes['date']], ['Objet', $entetes['objet']]] as [$etiquette, $valeur]) {
            if ($valeur !== '') {
                $lignes .= '<dt>' . htmlspecialchars($etiquette, ENT_QUOTES | ENT_HTML5) . '</dt><dd>'
                    . htmlspecialchars($valeur, ENT_QUOTES | ENT_HTML5) . '</dd>';
            }
        }
        $entetesHtml = $lignes === '' ? '' : '<dl class="courriel-entetes" data-testid="courriel-entetes">' . $lignes . '</dl>';

        $corps = $corpsHtml !== null && trim($corpsHtml) !== ''
            ? self::nettoyer($corpsHtml)
            : nl2br(htmlspecialchars($corpsTexte, ENT_QUOTES | ENT_HTML5));

        $piecesHtml = '';
        if ($pieces !== []) {
            $piecesHtml = '<div class="courriel-pieces" data-testid="courriel-pieces"><h3>Pièces jointes</h3><ul>';
            foreach ($pieces as $piece) {
                $piecesHtml .= '<li>' . htmlspecialchars($piece['nom'], ENT_QUOTES | ENT_HTML5)
                    . ' <span class="muted">(' . htmlspecialchars($piece['type'], ENT_QUOTES | ENT_HTML5) . ')</span></li>';
            }
            $piecesHtml .= '</ul></div>';
        }

        return '<div class="courriel" data-testid="visionneuse-courriel">' . $entetesHtml
            . '<div class="courriel-corps">' . $corps . '</div>' . $piecesHtml . '</div>';
    }

    /**
     * Nettoyage strict : images remplacées par leur texte alternatif, éléments actifs ou distants retirés,
     * puis TOUS les attributs supprimés (donc aucun `on*`, `src`, `href`, `style`). Aucune ressource ne
     * peut être chargée par le navigateur.
     */
    private static function nettoyer(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $document = new \DOMDocument('1.0', 'UTF-8');
        $precedent = libxml_use_internal_errors(true);
        $charge = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        if ($charge === false) {
            return '';
        }
        $xpath = new \DOMXPath($document);

        // Les images ne sont jamais servies : leur texte alternatif les remplace.
        foreach (iterator_to_array($xpath->query('//img') ?: []) as $image) {
            if ($image instanceof \DOMElement) {
                $image->parentNode?->replaceChild($document->createTextNode($image->getAttribute('alt')), $image);
            }
        }

        $interdits = ['//script', '//style', '//iframe', '//object', '//embed', '//link', '//meta', '//base', '//form',
            '//input', '//button', '//textarea', '//select', '//svg', '//math', '//applet', '//video', '//audio',
            '//source', '//track', '//frame', '//frameset', '//noscript', '//template'];
        foreach ($interdits as $requete) {
            foreach (iterator_to_array($xpath->query($requete) ?: []) as $noeud) {
                $noeud->parentNode?->removeChild($noeud);
            }
        }

        // Suppression de tous les attributs restants (liste figée avant mutation).
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $noms = [];
            foreach ($element->attributes as $attribut) {
                $noms[] = $attribut->nodeName;
            }
            foreach ($noms as $nom) {
                $element->removeAttribute($nom);
            }
        }

        $corps = $document->getElementsByTagName('body')->item(0);
        if ($corps === null) {
            return '';
        }
        $propre = '';
        foreach ($corps->childNodes as $enfant) {
            $propre .= (string) $document->saveHTML($enfant);
        }

        return trim($propre);
    }

    /** Première adresse électronique d'un en-tête (« Alice <alice@exemple.test> » → « alice@exemple.test »). */
    private static function adresse(string $valeur): string
    {
        if (preg_match('/[^\s<>;,]+@[^\s<>;,]+/', $valeur, $trouve) === 1) {
            return $trouve[0];
        }

        return trim($valeur);
    }
}
