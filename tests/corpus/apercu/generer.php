<?php

declare(strict_types=1);

/**
 * Générateur du corpus d'aperçu — 100 % synthétique, aucun document réel, aucun nom de client.
 *
 * Le dossier `documents/` fait foi : chaque pièce est un fichier lisible à côté de son `<fichier>.meta.json`
 * (même convention que le secteur Socle). Relancer ce script reproduit le corpus à l'identique.
 *
 *   php tests/corpus/apercu/generer.php
 *
 * Les binaires produits sont versionnés (le harness doit tourner sur un dépôt fraîchement cloné) ;
 * la source de vérité reste ce script.
 */

$racine = __DIR__;
$documents = $racine . '/documents';
if (!is_dir($documents) && !mkdir($documents, 0777, true) && !is_dir($documents)) {
    fwrite(STDERR, "Impossible de créer $documents\n");
    exit(1);
}

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @param list<string> $pagesTextes */
function ecrirePdf(array $pagesTextes, string $fichier): void
{
    $objets = [];
    $kids = [];
    $numero = 3;
    foreach ($pagesTextes as $i => $texte) {
        $page = $numero++;
        $contenu = $numero++;
        $kids[] = "$page 0 R";
        $flux = "BT /F1 20 Tf 60 780 Td (" . pdfEchappe($texte) . ") Tj ET\n"
            . "1 w 60 740 m 535 740 l S\n"
            . "BT /F1 11 Tf 60 715 Td (" . pdfEchappe('Document fictif - page ' . ($i + 1) . ' sur ' . count($pagesTextes)) . ") Tj ET\n";
        $objets[$contenu] = "<< /Length " . strlen($flux) . " >>\nstream\n" . $flux . "endstream";
        $objets[$page] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 9 0 R >> >> /Contents $contenu 0 R >>";
    }
    $objets[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objets[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($pagesTextes) . " >>";
    $objets[9] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    ksort($objets);

    $sortie = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objets as $n => $corps) {
        $offsets[$n] = strlen($sortie);
        $sortie .= "$n 0 obj\n$corps\nendobj\n";
    }
    $xref = strlen($sortie);
    $max = max(array_keys($objets));
    $sortie .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= $max; $n++) {
        $sortie .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
    }
    $sortie .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($fichier, $sortie);
}

function pdfEchappe(string $texte): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texte);
}

function imageSynth(): \GdImage
{
    $img = imagecreatetruecolor(793, 1123);
    $blanc = imagecolorallocate($img, 255, 255, 255);
    $encre = imagecolorallocate($img, 35, 42, 54);
    $gris = imagecolorallocate($img, 205, 208, 214);
    imagefill($img, 0, 0, $blanc);
    imagestring($img, 5, 60, 70, 'FACTURE FICTIVE - ATELIER MODELE SARL', $encre);
    for ($i = 0; $i < 12; $i++) {
        imagefilledrectangle($img, 60, 130 + $i * 40, 60 + (360 - ($i % 4) * 70), 138 + $i * 40, $gris);
    }
    imagestring($img, 4, 60, 640, 'Total fictif : CHF 1 234.50', $encre);

    return $img;
}

function ecrirePng(string $fichier): void
{
    imagepng(imageSynth(), $fichier);
}

function ecrireJpg(string $fichier): void
{
    imagejpeg(imageSynth(), $fichier, 86);
}

function ecrireTiff(string $fichier): bool
{
    $magick = \Ged\Apercu\Outils::magick();
    if ($magick === null) {
        return false;
    }
    $temporaire = sys_get_temp_dir() . '/gedv2-corpus-' . bin2hex(random_bytes(4)) . '.png';
    ecrirePng($temporaire);
    exec(escapeshellarg($magick) . ' ' . escapeshellarg($temporaire)
        . ' -colorspace Gray -compress Group4 ' . escapeshellarg($fichier) . ' 2>&1', $o, $code);
    @unlink($temporaire);

    return $code === 0 && is_file($fichier);
}

/** @param array<string, string> $entrees */
function ecrireZip(array $entrees, string $fichier, bool $mimetypeEnPremier = false): void
{
    $zip = new ZipArchive();
    if ($zip->open($fichier, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Zip impossible : $fichier");
    }
    if ($mimetypeEnPremier) {
        $zip->addFromString('mimetype', $entrees['mimetype']);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        unset($entrees['mimetype']);
    }
    foreach ($entrees as $nom => $contenu) {
        $zip->addFromString($nom, $contenu);
    }
    $zip->close();
}

/** @param array{id:int, fichier:string, titre:string, type:string, categorie:string, pages:int} $meta */
function ecrireMeta(string $dossier, array $meta): void
{
    $fichier = $dossier . '/' . $meta['fichier'] . '.meta.json';
    file_put_contents($fichier, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

// ---------------------------------------------------------------------------
// PDF : un contrat de 3 pages (porte du lot) et une facture d'une page.
// ---------------------------------------------------------------------------
ecrirePdf([
    'CONTRAT DE MAINTENANCE CM-2026-04 - page 1',
    'Article 1. Objet - prestation informatique fictive.',
    'Article 2. Duree et resiliation.',
], $documents . '/204-contrat-maintenance.pdf');
ecrireMeta($documents, ['id' => 204, 'fichier' => '204-contrat-maintenance.pdf', 'titre' => 'Contrat de maintenance CM-2026-04', 'type' => 'Contrat', 'categorie' => 'pdf', 'pages' => 3]);

ecrirePdf(['FACTURE AN-5001 (fictive)'], $documents . '/201-facture-an-5001.pdf');
ecrireMeta($documents, ['id' => 201, 'fichier' => '201-facture-an-5001.pdf', 'titre' => 'Facture AN-5001', 'type' => 'Facture', 'categorie' => 'pdf', 'pages' => 1]);

// ---------------------------------------------------------------------------
// Images : png, jpg, tiff (scan).
// ---------------------------------------------------------------------------
ecrirePng($documents . '/206-facture-ps-102.png');
ecrireMeta($documents, ['id' => 206, 'fichier' => '206-facture-ps-102.png', 'titre' => 'Facture PS-2026-102', 'type' => 'Facture', 'categorie' => 'image', 'pages' => 1]);

ecrireJpg($documents . '/207-recu-r-2002.jpg');
ecrireMeta($documents, ['id' => 207, 'fichier' => '207-recu-r-2002.jpg', 'titre' => 'Reçu R-2002', 'type' => 'Reçu', 'categorie' => 'image', 'pages' => 1]);

if (ecrireTiff($documents . '/208-facture-energie.tiff')) {
    ecrireMeta($documents, ['id' => 208, 'fichier' => '208-facture-energie.tiff', 'titre' => 'Facture énergie EE-2026-0212', 'type' => 'Facture', 'categorie' => 'image', 'pages' => 1]);
    echo "  tiff       : 208-facture-energie.tiff\n";
} else {
    echo "  tiff       : ignoré (ImageMagick absent sur ce poste)\n";
}

// ---------------------------------------------------------------------------
// Bureautique : docx, xlsx, odt (conteneurs OOXML / ODF minimaux et valides).
// ---------------------------------------------------------------------------
ecrireZip([
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
    'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Attestation de travail (document fictif)</w:t></w:r></w:p></w:body></w:document>',
], $documents . '/209-attestation-travail.docx');
ecrireMeta($documents, ['id' => 209, 'fichier' => '209-attestation-travail.docx', 'titre' => 'Attestation de travail', 'type' => 'Document RH', 'categorie' => 'bureautique', 'pages' => 1]);

ecrireZip([
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Charges" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
    'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Décompte fictif</t></is></c></row></sheetData></worksheet>',
], $documents . '/213-tableau-charges.xlsx');
ecrireMeta($documents, ['id' => 213, 'fichier' => '213-tableau-charges.xlsx', 'titre' => 'Tableau de charges (fictif)', 'type' => 'Autre', 'categorie' => 'bureautique', 'pages' => 1]);

ecrireZip([
    'mimetype' => 'application/vnd.oasis.opendocument.text',
    'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/></manifest:manifest>',
    'content.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:p>Police RC — avenant 1 (document fictif)</text:p></office:text></office:body></office:document-content>',
], $documents . '/210-police-rc-avenant.odt', true);
ecrireMeta($documents, ['id' => 210, 'fichier' => '210-police-rc-avenant.odt', 'titre' => 'Police RC — avenant 1', 'type' => 'Contrat', 'categorie' => 'bureautique', 'pages' => 1]);

echo "Corpus d'aperçu régénéré dans tests/corpus/apercu/documents/\n";
