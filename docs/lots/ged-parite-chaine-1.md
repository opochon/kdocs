# Lot ged-parite-chaine-1 — rattraper les 46 écarts de la chaîne de consommation

Base : `ged-parite-comportement-1` fusionné (`13b94a6`) : correspondance 137/137, dates 25/25, parité stricte
23,97 %. Les 46 éléments `chaine` encore `absent` sont la cible :

- classes de la consommation (4) : `ConsumerError`, `ConsumerPluginMixin`, `ConsumerPreflightPlugin`,
  `ConsumerStatusShortMessage` ;
- codes-barres (5) : `Barcode`, `Barcode.is_asn`, `Barcode.is_separator`, `BarcodePlugin`,
  `BarcodePlugin.run` ;
- étapes (4) : `ConsumerPlugin._parse_title_placeholders`, `ConsumerPlugin.apply_overrides`,
  `ConsumerPreflightPlugin.pre_check_asn_value`, `ConsumerPreflightPlugin.run` ;
- gabarit de nom de fichier (7) : `_is_safe_relative_path`, `create_dummy_document`,
  `get_added_date_context`, `get_creation_date_context`, `get_custom_fields_context`, `get_tags_context`,
  `validate_filepath_template_and_render` ;
- registre des parseurs (8) : `get_default_file_extension`, `get_default_thumbnail`,
  `get_parser_class_for_mime_type`, `get_supported_file_extensions`, `is_file_ext_supported`,
  `is_mime_type_supported`, `parse_date_generator`, `run_convert` ;
- parseurs (25) : `DocumentParser.*`, `MailDocumentParser.*`, `RasterisedDocumentParser.*`,
  `TextDocumentParser.*`, `TikaDocumentParser.*` ;
- post-traitement OCR (1) : `paperless_tesseract.post_process_text`.

Méthode oracle d'abord, une famille à la fois : vecteurs extraits en lecture seule des tests Paperless
(`C:\ged\ref\paperless-ngx`, tag v2.20.5, commit `d1aa76e`) → rejeu PHPUnit rouge → implémentation GEDv2 →
vert → commit. Un commit par famille.

## Fait (commits)

- (en cours) cadrage : lecture d'AGENTS.md, PLAN, qualité, outillage parité et des tests Paperless.

## En cours

- Extracteur `tools/parite/extraire-chaine.py` (familles registre / OCR / codes-barres / gabarit).

## Reste

- Les six familles, puis correspondance.json, PARITE-PAPERLESS.md par le juge, harness `-SansBanc`.

## Mesures exactes (à tenir à jour)

- Avant : `chaine` 0 couvert / 46 absents ; parité stricte 23,97 % (187/780) ; hors exclusions 25,55 %.
- Après : (à compléter).

ETAT: en cours
