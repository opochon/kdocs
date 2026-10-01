# Corpus classement-bruite

Corpus R2e entièrement synthétique : 48 textes OCR de scans, sur les 12 types de la nomenclature. Les pièces
mélangent logos et tampons, colonnes, destinataire avant émetteur, pieds de page IBAN/IDE, lignes coupées,
dates FR/DE/IT et montants CHF. Elles incluent explicitement les factures énergie et vétérinaire datées, les
courriers qui citent contrat/attestation/police, ainsi que les variantes AG/SA/Sàrl/GmbH d'un même émetteur.

`verite.json` est l'oracle, avec réponses IA simulées déterministes : aucune donnée réelle ni appel réseau.
La porte publie les validés seuls, faux validés, émetteurs vides/faux, dates vides/fausses et types faux :

`php tools/banc/mesurer-classement.php classement-bruite --ia-simule`
