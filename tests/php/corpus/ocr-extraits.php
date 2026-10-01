<?php

declare(strict_types=1);

/**
 * V2-10d — corpus synthétique de sorties OCR de papier scanné, pour l'heuristique d'extraits lisibles.
 *
 * Chaque entrée décrit une fenêtre d'extrait telle que la recherche la découpe autour d'un terme trouvé :
 *
 *   nom        : ce que la forme représente (en-tête, adresse, montant, tampon…)
 *   categorie  : « propre »  = un vrai texte, doit rester affiché tel quel ;
 *                « mixte »   = bruit PUIS texte lisible, doit être rogné au premier segment propre ;
 *                « bruit »   = pas de texte lisible, rien ne doit être affiché ;
 *   texte      : la sortie OCR brute (fenêtre d'extrait) ;
 *   attendu    : ce que l'écran doit montrer (null = aucun extrait) ;
 *   interdits  : fragments/signes qui ne doivent JAMAIS apparaître dans l'extrait affiché.
 *
 * Aucune donnée réelle : noms, adresses, références et montants sont inventés.
 *
 * @return list<array{nom:string, categorie:string, texte:string, attendu:?string, interdits:list<string>}>
 */
return [
    // ------------------------------------------------------------------ textes propres (doivent rester)
    [
        'nom' => 'en-tête de facture français',
        'categorie' => 'propre',
        'texte' => 'Facture n° 2026-0142 du 12.03.2026 — Atelier Vermeil Sàrl, montant 4 244,74 CHF, échéance à 30 jours.',
        'attendu' => 'Facture n° 2026-0142 du 12.03.2026 — Atelier Vermeil Sàrl, montant 4 244,74 CHF, échéance à 30 jours.',
        'interdits' => [],
    ],
    [
        'nom' => 'adresse d’expéditeur et objet',
        'categorie' => 'propre',
        'texte' => 'Madame Sophie Meyrin, avenue de la Gare 14, 1920 Martigny. Objet : renouvellement du contrat de bail.',
        'attendu' => 'Madame Sophie Meyrin, avenue de la Gare 14, 1920 Martigny. Objet : renouvellement du contrat de bail.',
        'interdits' => [],
    ],
    [
        'nom' => 'lettre allemande',
        'categorie' => 'propre',
        'texte' => 'Sehr geehrte Frau Baumann, wir bestätigen den Eingang Ihrer Lieferung vom 4. Mai 2026.',
        'attendu' => 'Sehr geehrte Frau Baumann, wir bestätigen den Eingang Ihrer Lieferung vom 4. Mai 2026.',
        'interdits' => [],
    ],
    [
        'nom' => 'lettre italienne',
        'categorie' => 'propre',
        'texte' => 'Spettabile Ditta Rossi SA, via dei Fiori 8, 6900 Lugano. Oggetto: fattura per la fornitura di cancelleria.',
        'attendu' => 'Spettabile Ditta Rossi SA, via dei Fiori 8, 6900 Lugano. Oggetto: fattura per la fornitura di cancelleria.',
        'interdits' => [],
    ],
    [
        'nom' => 'adresse seule (petit mot de trois lettres en tête)',
        'categorie' => 'propre',
        'texte' => 'Rue du Simplon 12, 1006 Lausanne, le 4 mars 2026.',
        'attendu' => 'Rue du Simplon 12, 1006 Lausanne, le 4 mars 2026.',
        'interdits' => [],
    ],
    [
        'nom' => 'confirmation de commande',
        'categorie' => 'propre',
        'texte' => 'Confirmation de commande n° 88-2026 — quatre palettes, livraison franco de port.',
        'attendu' => 'Confirmation de commande n° 88-2026 — quatre palettes, livraison franco de port.',
        'interdits' => [],
    ],
    [
        'nom' => 'ligne de totaux et taxes',
        'categorie' => 'propre',
        'texte' => 'Total TTC : 1 248,50 CHF, TVA 8,1 % incluse.',
        'attendu' => 'Total TTC : 1 248,50 CHF, TVA 8,1 % incluse.',
        'interdits' => [],
    ],
    [
        'nom' => 'clause de contrat',
        'categorie' => 'propre',
        'texte' => 'Le présent contrat de maintenance prend effet le 1er avril 2026 et court jusqu’au 31 mars 2027.',
        'attendu' => 'Le présent contrat de maintenance prend effet le 1er avril 2026 et court jusqu’au 31 mars 2027.',
        'interdits' => [],
    ],
    [
        'nom' => 'appel de paiement allemand (coordonnées bancaires)',
        'categorie' => 'propre',
        'texte' => 'Bitte überweisen Sie den Betrag bis zum 15. Juni 2026 auf unser Konto CH55 0000 0000 0000 0000 0.',
        'attendu' => 'Bitte überweisen Sie den Betrag bis zum 15. Juni 2026 auf unser Konto CH55 0000 0000 0000 0000 0.',
        'interdits' => [],
    ],
    [
        'nom' => 'formule de politesse française',
        'categorie' => 'propre',
        'texte' => 'Nous vous prions d’agréer, Madame, Monsieur, nos salutations distinguées.',
        'attendu' => 'Nous vous prions d’agréer, Madame, Monsieur, nos salutations distinguées.',
        'interdits' => [],
    ],
    [
        'nom' => 'quittance italienne',
        'categorie' => 'propre',
        'texte' => 'Pagamento ricevuto con ringraziamenti: saldo di 350,00 EUR alla data del 2 settembre 2026.',
        'attendu' => 'Pagamento ricevuto con ringraziamenti: saldo di 350,00 EUR alla data del 2 settembre 2026.',
        'interdits' => [],
    ],
    [
        'nom' => 'référence et montants hors taxes',
        'categorie' => 'propre',
        'texte' => 'Référence 2026-0142 — montant HT 3 925,00 CHF, TVA 20 % en sus.',
        'attendu' => 'Référence 2026-0142 — montant HT 3 925,00 CHF, TVA 20 % en sus.',
        'interdits' => [],
    ],

    // ------------------------------------------------------------------ bruit PUIS texte (doit être rogné)
    [
        'nom' => 'tampon bruité puis adresse (forme du juge)',
        'categorie' => 'mixte',
        'texte' => 'nn NE {EL EE PostC.  Rue du Simplon 12, 1006 Lausanne',
        'attendu' => 'du Simplon 12, 1006 Lausanne',
        'interdits' => ['nn', '{', 'EL', 'EE', 'PostC'],
    ],
    [
        'nom' => 'logo bruité puis objet français',
        'categorie' => 'mixte',
        'texte' => 'XXXX || OE 7! ggg MAZ. Objet : demande de renseignements fiscaux pour l’exercice 2026.',
        'attendu' => 'Objet : demande de renseignements fiscaux pour l’exercice 2026.',
        'interdits' => ['OE', 'ggg', 'MAZ', '||'],
    ],
    [
        'nom' => 'tampon puis lettre allemande',
        'categorie' => 'mixte',
        'texte' => 'Blab blab 12 34 { } nnn. Wir danken Ihnen für Ihre Bestellung vom 3. Februar 2026.',
        'attendu' => 'Wir danken Ihnen für Ihre Bestellung vom 3. Februar 2026.',
        'interdits' => ['nnn', '{', '}', 'Blab'],
    ],
    [
        'nom' => 'tampon puis demande italienne',
        'categorie' => 'mixte',
        'texte' => 'xyz abc dd — Si prega di confermare la ricezione della merce entro il 15 maggio 2026.',
        'attendu' => 'Si prega di confermare la ricezione della merce entro il 15 maggio 2026.',
        'interdits' => ['xyz', 'abc', 'dd'],
    ],
    [
        'nom' => 'bruit puis montant allemand',
        'categorie' => 'mixte',
        'texte' => 'AA BB CC dd — Betrag: 1 248,50 CHF, zahlbar bis 30.04.2026.',
        'attendu' => 'Betrag: 1 248,50 CHF, zahlbar bis 30.04.2026.',
        'interdits' => ['AA BB CC', 'dd'],
    ],
    [
        'nom' => 'préfixe bruité sur une longue adresse (l’adresse dilue les ratios)',
        'categorie' => 'mixte',
        'texte' => 'KX KX nn — Rue du Relais 12, 1920 Martigny, dossier 2026-88, exemplaire unique, pièce numérotée, classement provisoire à confirmer.',
        'attendu' => 'du Relais 12, 1920 Martigny, dossier 2026-88, exemplaire unique, pièce numérotée, classement provisoire à confirmer.',
        'interdits' => ['KX', 'nn —'],
    ],

    // ------------------------------------------------------------------ bruit seul (rien à afficher)
    [
        'nom' => 'amas de fragments (forme du juge)',
        'categorie' => 'bruit',
        'texte' => 'Ps TC iar ee SES a — ad Dee ieee Post CHAG',
        'attendu' => null,
        'interdits' => ['iar', 'Dee', 'ieee', 'Post', 'CHAG'],
    ],
    [
        'nom' => 'soupe de symboles',
        'categorie' => 'bruit',
        'texte' => '@@@ ### $$$ %%% ^^^ ~~~ ||| /// \\\\ ___',
        'attendu' => null,
        'interdits' => ['@@@', '~~~', '|||'],
    ],
    [
        'nom' => 'soupe de consonnes',
        'categorie' => 'bruit',
        'texte' => 'bcd fgh jkl mnp qrs tvw xyz bcd fgh jkl mnp qrs',
        'attendu' => null,
        'interdits' => ['bcd', 'tvw'],
    ],
    [
        'nom' => 'fragments vocaliques sans mot réel',
        'categorie' => 'bruit',
        'texte' => 'ie iee oua eio aou iea oei eea aei iou eia',
        'attendu' => null,
        'interdits' => ['iee', 'oua', 'eia'],
    ],
    [
        'nom' => 'bribes mêlées à des petits mots',
        'categorie' => 'bruit',
        'texte' => 'ad Dee ieee Post CHAG',
        'attendu' => null,
        'interdits' => ['Dee', 'ieee', 'CHAG'],
    ],
    [
        'nom' => 'confusion chiffres et lettres',
        'categorie' => 'bruit',
        'texte' => '0O0 l1I 8B 5S 2Z |~| {[]} ## 3 3 3',
        'attendu' => null,
        'interdits' => ['0O0', 'l1I', '{[]}'],
    ],
    [
        'nom' => 'bruit de tampon postal',
        'categorie' => 'bruit',
        'texte' => 'CH-1000 ///// 3 3 3 {[]} Post',
        'attendu' => null,
        'interdits' => ['/////', '{[]}'],
    ],
];
