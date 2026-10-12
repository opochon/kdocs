# Parite Paperless-ngx / GEDv2

Mesure mecanique du lot `ged-oracle-paperless-1`, calculee depuis les sources de Paperless-ngx v2.20.5 (commit d1aa76e).

Un element n'est couvert que si l'equivalent GEDv2 existe et si la preuve citee a reussi au dernier rejeu. Une exclusion n'est jamais comptee comme couverte.

Inventaire : rejoue a l'identique — inventaire rejoue a l'identique (commit d1aa76e).

## Synthese

| Total | Couverts | Absents | Non prouves | Exclus | Parite stricte | Parite hors exclusions |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 780 | 435 | 297 | 0 | 48 | 55,77 % | 59,43 % |

### Par categorie

| Categorie | Total | Couverts | Absents | Non prouves | Exclus | Stricte | Hors exclusions |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| api | 318 | 96 | 179 | 0 | 43 | 30,19 % | 34,91 % |
| chaine | 60 | 55 | 5 | 0 | 0 | 91,67 % | 91,67 % |
| commande | 17 | 9 | 6 | 0 | 2 | 52,94 % | 60,00 % |
| correspondance | 13 | 9 | 4 | 0 | 0 | 69,23 % | 69,23 % |
| ecran | 30 | 12 | 17 | 0 | 1 | 40,00 % | 41,38 % |
| masse | 17 | 14 | 3 | 0 | 0 | 82,35 % | 82,35 % |
| modele | 313 | 233 | 79 | 0 | 1 | 74,44 % | 74,68 % |
| permissions | 12 | 7 | 4 | 0 | 1 | 58,33 % | 63,64 % |

## Comportement (phase 2)

Vecteurs {entree, sortie} extraits mecaniquement des tests Paperless, rejoues par l'equivalent GEDv2. Un ecart de comportement est mesure, jamais corrige dans ce lot.

| Categorie Paperless | Reference vecteurs | Passes | Total | Taux |
| --- | --- | ---: | ---: | ---: |
| dates | src/documents/tests/test_date_parsing.py | 25 | 25 | 100,00 % |
| correspondance | src/documents/tests/test_matchables.py | 137 | 137 | 100,00 % |
| — MATCH_NONE | — | 2 | 2 | 100,00 % |
| — MATCH_ALL | — | 40 | 40 | 100,00 % |
| — MATCH_ANY | — | 42 | 42 | 100,00 % |
| — MATCH_LITERAL | — | 29 | 29 | 100,00 % |
| — MATCH_REGEX | — | 19 | 19 | 100,00 % |
| — MATCH_FUZZY | — | 5 | 5 | 100,00 % |

## Chaine de consommation (phase 3)

Vecteurs {entree, sortie} extraits des tests Paperless (registre des parseurs, post-traitement OCR,
codes-barres, gabarit de nom de fichier), rejoues par l'equivalent GEDv2. Un ecart de comportement
reste un ecart ; le test de rejeu echoue si un vecteur s'ecarte.

| Famille | Fichier de vecteurs | Passes | Total | Taux |
| --- | --- | ---: | ---: | ---: |
| codebarres | tests/parite/vecteurs/chaine-codebarres.json | 4 | 4 | 100,00 % |
| gabarit | tests/parite/vecteurs/chaine-gabarit.json | 25 | 25 | 100,00 % |
| ocr-text | tests/parite/vecteurs/chaine-ocr-text.json | 3 | 3 | 100,00 % |
| registre | tests/parite/vecteurs/chaine-registre.json | 39 | 41 | 95,12 % |

## API — filtres de liste (phase 4)

Vecteurs {corpus, requete, documents retenus} extraits des tests Paperless (filtres de liste),
rejoues contre la vraie couche de requetes de GEDv2 sur gedv2_test. Le test de rejeu echoue si un
vecteur s'ecarte ; les vecteurs propres a Django/DRF sont listes `non_rejouables` avec leur raison.

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |
| --- | --- | ---: | ---: | ---: | ---: |
| filtres-documents | tests/parite/vecteurs/api-filtres-documents.json | 18 | 18 | 2 | 100,00 % |

## Modele — workflows (phase 5)

Vecteurs {document, declencheur, actions} extraits des tests Paperless (WorkflowTrigger,
WorkflowAction, WorkflowActionEmail, WorkflowActionWebhook, WorkflowRun), rejoues contre le
vrai moteur Workflows de GEDv2 sur gedv2_test. Les traits propres a Django/Celery sont listes
`non_rejouables` avec leur raison.

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |
| --- | --- | ---: | ---: | ---: | ---: |
| modele-workflows | tests/parite/vecteurs/modele-workflows.json | 27 | 27 | 4 | 100,00 % |
| — declencheurs | — | 16 | 16 | — | 100,00 % |
| — affectation | — | 1 | 1 | — | 100,00 % |
| — retrait | — | 2 | 2 | — | 100,00 % |
| — courriel-webhook | — | 2 | 2 | — | 100,00 % |
| — planification | — | 6 | 6 | — | 100,00 % |

## Modele — courrier (phase 6)

Vecteurs {courriel, regle} extraits des tests Paperless (MailRule, MailAccountHandler, actions
marquer / supprimer / deplacer / etiqueter, filtres de pieces), rejoues contre le vrai moteur
courrier de GEDv2 sur gedv2_test. Les traits propres a Django/Celery/OAuth sont listes
`non_rejouables` avec leur raison.

| Famille | Fichier de vecteurs | Passes | Total | Non rejouables | Taux |
| --- | --- | ---: | ---: | ---: | ---: |
| modele-courrier | tests/parite/vecteurs/modele-courrier.json | 42 | 42 | 4 | 100,00 % |
| — filtres-fichiers | — | 9 | 9 | — | 100,00 % |
| — filtres | — | 8 | 8 | — | 100,00 % |
| — titre | — | 3 | 3 | — | 100,00 % |
| — emetteur | — | 5 | 5 | — | 100,00 % |
| — pieces | — | 4 | 4 | — | 100,00 % |
| — portee | — | 1 | 1 | — | 100,00 % |
| — actions | — | 8 | 8 | — | 100,00 % |
| — regles | — | 1 | 1 | — | 100,00 % |
| — validation | — | 1 | 1 | — | 100,00 % |
| — journal | — | 2 | 2 | — | 100,00 % |

## api

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| @action DocumentViewSet.download [GET] detail | src/documents/views.py:970 | couvert-autrement | route GET /api/v1/documents/{id:[0-9]+}/fichier | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testPaginationBorneeEtVersionTelechargeable (couche 1) |  |
| @action DocumentViewSet.email_document [POST] detail | src/documents/views.py:1187 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| @action DocumentViewSet.email_documents [POST] liste | src/documents/views.py:1199 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| @action DocumentViewSet.history [GET] detail | src/documents/views.py:1120 | couvert-autrement | service Ged\Socle\Registre | phpunit Ged\Tests\Socle\RegistreTest::testÉcritPuisRelitDepuisUneAutreInstance (couche 1) |  |
| @action DocumentViewSet.metadata [GET] detail | src/documents/views.py:830 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| @action DocumentViewSet.notes [GET/POST/DELETE] detail | src/documents/views.py:982 | couvert-autrement | route POST /documents/{id:[0-9]+}/notes | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| @action DocumentViewSet.preview [GET] detail | src/documents/views.py:941 | couvert-autrement | route GET /documents/{id:[0-9]+}/apercu | phpunit Ged\Tests\Apercu\ModuleTest::testPageApercuPdfSeDesigne (couche 1) |  |
| @action DocumentViewSet.share_links [GET] detail | src/documents/views.py:1093 | couvert-autrement | route POST /documents/{id:[0-9]+}/partages | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| @action DocumentViewSet.suggestions [GET] detail | src/documents/views.py:893 | couvert-autrement | service Ged\Classement\ClasseurGed | phpunit Ged\Tests\Classement\ClasseurGedTest::testSansIndiceDuToutLeRepliCourrierEstProposeSansValidation (couche 1) |  |
| @action DocumentViewSet.thumb [GET] detail | src/documents/views.py:951 | couvert-autrement | route GET /documents/{id:[0-9]+}/miniature | phpunit Ged\Tests\Apercu\ModuleTest::testMiniatureServieEnPng (couche 1) |  |
| @action MailAccountViewSet.process [POST] detail | src/paperless_mail/views.py:130 | couvert-autrement | route POST /courrier/comptes/{id:[0-9]+}/traiter | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| @action MailAccountViewSet.test [POST] liste | src/paperless_mail/views.py:84 | couvert-autrement | route POST /courrier/comptes/{id:[0-9]+}/tester | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| @action ProcessedMailViewSet.bulk_delete [POST] liste | src/paperless_mail/views.py:151 | couvert-autrement | route POST /courrier/journal/purger | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| @action StoragePathViewSet.test [POST] liste | src/documents/views.py:2347 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| @action TasksViewSet.acknowledge [POST] liste | src/documents/views.py:2566 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| @action TasksViewSet.run [POST] liste | src/documents/views.py:2583 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| @action UnifiedSearchViewSet.next_asn [GET] liste | src/documents/views.py:1359 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| @action UserViewSet.deactivate_totp [POST] detail | src/paperless/views.py:150 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentFilterSet.id | src/documents/filters.py:81 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentFilterSet.name | src/documents/filters.py:81 | couvert-autrement | route GET /api/v1/emetteurs | phpunit Ged\Tests\Parite\ApiFiltresReferentielsTest::testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits (couche 1) |  |
| CustomFieldFilterSet.id | src/documents/filters.py:209 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldFilterSet.name | src/documents/filters.py:209 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.added | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.archive_serial_number | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.checksum | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments (couche 1) |  |
| DocumentFilterSet.content | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.correspondent | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.correspondent__id | src/documents/filters.py:763 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Recherche\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre (couche 1) |  |
| DocumentFilterSet.correspondent__id__none (ObjectFilter) | src/documents/filters.py:716 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.correspondent__name | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.created | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.created__date__gt (DateFilter) | src/documents/filters.py:756 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.created__date__gte (DateFilter) | src/documents/filters.py:757 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Perf\BibliothequeFiltresTest::testLeFiltreSqlDonneLeMemeResultatQueLeFiltragePhp (couche 1) |  |
| DocumentFilterSet.created__date__lt (DateFilter) | src/documents/filters.py:758 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.created__date__lte (DateFilter) | src/documents/filters.py:759 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Perf\BibliothequeFiltresTest::testLeFiltreSqlDonneLeMemeResultatQueLeFiltragePhp (couche 1) |  |
| DocumentFilterSet.custom_field_query (CustomFieldQueryFilter) | src/documents/filters.py:749 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.custom_fields | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.custom_fields__icontains (CustomFieldsFilter) | src/documents/filters.py:728 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.custom_fields__id__all (ObjectFilter) | src/documents/filters.py:730 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.custom_fields__id__in (ObjectFilter) | src/documents/filters.py:737 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.custom_fields__id__none (ObjectFilter) | src/documents/filters.py:732 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.document_type | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.document_type__id | src/documents/filters.py:763 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Recherche\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre (couche 1) |  |
| DocumentFilterSet.document_type__id__none (ObjectFilter) | src/documents/filters.py:718 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.document_type__name | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.has_custom_fields (BooleanFilter) | src/documents/filters.py:742 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.id | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments (couche 1) |  |
| DocumentFilterSet.is_in_inbox (InboxFilter) | src/documents/filters.py:722 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments (couche 1) |  |
| DocumentFilterSet.is_tagged (BooleanFilter) | src/documents/filters.py:703 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.mime_type (MimeTypeFilter) | src/documents/filters.py:753 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments (couche 1) |  |
| DocumentFilterSet.modified | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.original_filename | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.owner | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.owner__id | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.owner__id__none (ObjectFilter) | src/documents/filters.py:726 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.storage_path | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.storage_path__id | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.storage_path__id__none (ObjectFilter) | src/documents/filters.py:720 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.storage_path__name | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.tags__id | src/documents/filters.py:763 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentFilterSet.tags__id__all (ObjectFilter) | src/documents/filters.py:710 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Recherche\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre (couche 1) |  |
| DocumentFilterSet.tags__id__in (ObjectFilter) | src/documents/filters.py:714 | couvert-autrement | route POST /recherche/vue | phpunit Ged\Tests\Recherche\ServiceTest::testFiltresTexteEtMontantPartagentLeMemePerimetre (couche 1) |  |
| DocumentFilterSet.tags__id__none (ObjectFilter) | src/documents/filters.py:712 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testRejoueLesVecteursDesFiltresDeDocuments (couche 1) |  |
| DocumentFilterSet.tags__name | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.title | src/documents/filters.py:763 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Parite\ApiFiltresDocumentsTest::testLesFiltresDeBornesDExistenceEtDeTexteSontCables (couche 1) |  |
| DocumentFilterSet.title_content (TitleContentFilter) | src/documents/filters.py:724 | couvert-autrement | route GET /recherche | phpunit Ged\Tests\Perf\BibliothequeFiltresTest::testLeFiltreSqlDonneLeMemeResultatQueLeFiltragePhp (couche 1) |  |
| DocumentTypeFilterSet.id | src/documents/filters.py:105 | couvert-autrement | route GET /api/v1/types | phpunit Ged\Tests\Parite\ApiFiltresReferentielsTest::testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits (couche 1) |  |
| DocumentTypeFilterSet.name | src/documents/filters.py:105 | couvert-autrement | route GET /api/v1/types | phpunit Ged\Tests\Parite\ApiFiltresReferentielsTest::testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits (couche 1) |  |
| PaperlessTaskFilterSet.acknowledged (BooleanFilter) | src/documents/filters.py:800 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| PaperlessTaskFilterSet.status | src/documents/filters.py:807 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| PaperlessTaskFilterSet.task_name | src/documents/filters.py:807 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| PaperlessTaskFilterSet.type | src/documents/filters.py:807 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ProcessedMailFilterSet.rule | src/paperless_mail/filters.py:9 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testLeJournalSeFiltreParRegleEtParStatut (couche 1) |  |
| ProcessedMailFilterSet.status | src/paperless_mail/filters.py:9 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testLeJournalSeFiltreParRegleEtParStatut (couche 1) |  |
| ShareLinkFilterSet.created | src/documents/filters.py:793 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkFilterSet.expiration | src/documents/filters.py:793 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathFilterSet.id | src/documents/filters.py:114 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathFilterSet.name | src/documents/filters.py:114 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathFilterSet.path | src/documents/filters.py:114 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagFilterSet.id | src/documents/filters.py:90 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagFilterSet.is_root (BooleanFilter) | src/documents/filters.py:95 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagFilterSet.name | src/documents/filters.py:90 | couvert-autrement | route GET /api/v1/etiquettes | phpunit Ged\Tests\Parite\ApiFiltresReferentielsTest::testLesFiltresDesReferentielsSontCablesEtRespectentLesDroits (couche 1) |  |
| re_path accounts/^confirm-email/(?P<key>[-:\w]+)/$ -> account_confirm_email [allauth_account_views.ConfirmEmailView.as_view] | src/paperless/urls.py:309 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/account_inactive/ -> account_inactive [allauth_account_views.account_inactive] | src/paperless/urls.py:281 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/login/ -> account_login [allauth_account_views.login] | src/paperless/urls.py:278 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/logout/ -> account_logout [allauth_account_views.logout] | src/paperless/urls.py:279 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/password/reset/ -> account_reset_password [allauth_account_views.password_reset] | src/paperless/urls.py:291 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/password/reset/done/ -> account_reset_password_done [allauth_account_views.password_reset_done] | src/paperless/urls.py:296 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path accounts/^password/reset/key/(?P<uidb36>[0-9A-Za-z]+)-(?P<key>.+)/$ -> account_reset_password_from_key [allauth_account_views.password_reset_from_key] | src/paperless/urls.py:314 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/password/reset/key/done/ -> account_reset_password_from_key_done [allauth_account_views.password_reset_from_key_done] | src/paperless/urls.py:301 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/signup/ -> account_signup [allauth_account_views.signup] | src/paperless/urls.py:280 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/3rdparty/ [include] | src/paperless/urls.py:320 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/ [include] | src/paperless/urls.py:272 | exclu (exclu : infrastructure-django) | - | - |  |
| path accounts/password/ [include] | src/paperless/urls.py:287 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path admin/ [admin.site.urls] | src/paperless/urls.py:235 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^auth/ [include] | src/paperless/urls.py:89 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^documents/ [include] | src/paperless/urls.py:118 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/ [include] | src/paperless/urls.py:85 | exclu (exclu : infrastructure-django) | - | - |  |
| path ^api/^profile/disconnect_social_account/ [DisconnectSocialAccountView.as_view] | src/paperless/urls.py:177 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| path ^api/^profile/generate_auth_token/ [GenerateAuthTokenView.as_view] | src/paperless/urls.py:173 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^profile/ [include] | src/paperless/urls.py:164 | exclu (exclu : infrastructure-django) | - | - |  |
| path ^api/^profile/social_account_providers/ [SocialAccountProvidersView.as_view] | src/paperless/urls.py:181 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^$ [RedirectView.as_view] | src/paperless/urls.py:225 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^schema/ [include] | src/paperless/urls.py:208 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^search/ [include] | src/paperless/urls.py:96 | exclu (exclu : infrastructure-django) | - | - |  |
| path ^api/token/ [PaperlessObtainAuthTokenView.as_view] | src/paperless/urls.py:160 | exclu (exclu : infrastructure-django) | - | - |  |
| path assets/<path:path> [RedirectView.as_view] | src/paperless/urls.py:262 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^fetch/^doc/(?P<pk>\d+)$ [RedirectView.as_view] | src/paperless/urls.py:240 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^fetch/ [include] | src/paperless/urls.py:236 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^fetch/^preview/(?P<pk>\d+)$ [RedirectView.as_view] | src/paperless/urls.py:252 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^fetch/^thumb/(?P<pk>\d+)$ [RedirectView.as_view] | src/paperless/urls.py:246 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path share/(?P<slug>\w+)/?$ [SharedLinkView.as_view] | src/paperless/urls.py:233 | couvert-autrement | route GET /partages/{jeton:[A-Za-z0-9_-]+} | phpunit Ged\Tests\Socle\V212dTest::testLeJetonExterneEstUnFlashDeSessionConsommeUneSeuleFois (couche 1) |  |
| path ws/status/ [StatusConsumer.as_asgi] | src/paperless/urls.py:362 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| re_path ^logo(?:/(?P<filename>.+))?/?$ -> app_logo [serve_logo] | src/paperless/urls.py:270 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^search/^autocomplete/ -> autocomplete [SearchAutoCompleteView.as_view] | src/paperless/urls.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path .* -> base [login_required] | src/paperless/urls.py:353 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^documents/^bulk_download/ -> bulk_download [BulkDownloadView.as_view] | src/paperless/urls.py:132 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^documents/^bulk_edit/ -> bulk_edit [BulkEditView.as_view] | src/paperless/urls.py:127 | couvert-autrement | route POST /bibliotheque/masse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| re_path ^api/^bulk_edit_objects/ -> bulk_edit_objects [BulkEditObjectsView.as_view] | src/paperless/urls.py:145 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/config/ -> ApplicationConfigurationViewSet | src/paperless/urls.py:80 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/correspondents/ -> CorrespondentViewSet | src/paperless/urls.py:63 | couvert-autrement | route GET /api/v1/emetteurs | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| router /api/custom_fields/ -> CustomFieldViewSet | src/paperless/urls.py:79 | couvert-autrement | route POST /metadonnees/champ | phpunit Ged\Tests\Socle\ChampsTest::testUnChampTypéEstRattachéÀUnTypeAvecUneCléSlug (couche 1) |  |
| router /api/document_types/ -> DocumentTypeViewSet | src/paperless/urls.py:64 | couvert-autrement | route GET /api/v1/types | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| router /api/documents/ -> UnifiedSearchViewSet | src/paperless/urls.py:65 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesNouvellesRoutesSontDeclareesHorsSession (couche 1) |  |
| re_path ^favicon.ico$ -> favicon [FaviconView.as_view] | src/paperless/urls.py:234 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^search/^$ -> global_search [GlobalSearchView.as_view] | src/paperless/urls.py:100 | couvert-autrement | route GET /recherche | phpunit Ged\Tests\Recherche\EcranTest::testLesVraisResultatsArriventDansLeGabarit (couche 1) |  |
| router /api/groups/ -> GroupViewSet | src/paperless/urls.py:72 | couvert-autrement | route GET /administration/groupes | phpunit Ged\Tests\Securite\V213DroitsRolesTest::testAdministrationCreeUnCompteEtUnGroupeAvecTraceDaudit (couche 1) |  |
| router /api/logs/ -> LogViewSet | src/paperless/urls.py:66 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/mail_accounts/ -> MailAccountViewSet | src/paperless/urls.py:73 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| router /api/mail_rules/ -> MailRuleViewSet | src/paperless/urls.py:74 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| path accounts/2fa/authenticate/ -> mfa_authenticate [allauth_mfa_views.authenticate] | src/paperless/urls.py:344 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^oauth/callback/ -> oauth_callback [OauthCallbackView.as_view] | src/paperless/urls.py:203 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^documents/^post_document/ -> post_document [PostDocumentView.as_view] | src/paperless/urls.py:122 | couvert-autrement | route POST /api/v1/documents | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent (couche 1) |  |
| router /api/processed_mail/ -> ProcessedMailViewSet | src/paperless/urls.py:81 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| re_path ^api/^profile/^$ -> profile_view [ProfileView.as_view] | src/paperless/urls.py:168 | couvert-autrement | route GET /profil | phpunit Ged\Tests\Jonctions\ProfilTest::testLAdresseEstVerifieeParUnLienEnvoyeParCourriel (couche 1) |  |
| re_path ^api/^remote_version/ -> remoteversion [RemoteVersionView.as_view] | src/paperless/urls.py:150 | exclu (exclu : infrastructure-django) | - | - |  |
| router /api/saved_views/ -> SavedViewViewSet | src/paperless/urls.py:68 | couvert-autrement | route GET /api/v1/vues | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| re_path ^api/^schema/^$ -> schema [SpectacularAPIView.as_view] | src/paperless/urls.py:212 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^documents/^selection_data/ -> selection_data [SelectionDataView.as_view] | src/paperless/urls.py:137 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/share_links/ -> ShareLinkViewSet | src/paperless/urls.py:75 | couvert-autrement | route POST /documents/{id:[0-9]+}/partages | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| path accounts/3rdparty/login/cancelled/ -> socialaccount_login_cancelled [allauth_social_account_views.login_cancelled] | src/paperless/urls.py:324 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| path accounts/3rdparty/login/error/ -> socialaccount_login_error [allauth_social_account_views.login_error] | src/paperless/urls.py:329 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| path accounts/3rdparty/signup/ -> socialaccount_signup [allauth_social_account_views.signup] | src/paperless/urls.py:334 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^statistics/ -> statistics [StatisticsView.as_view] | src/paperless/urls.py:113 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/storage_paths/ -> StoragePathViewSet | src/paperless/urls.py:69 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^schema/^view/ -> swagger-ui [SpectacularSwaggerView.as_view] | src/paperless/urls.py:217 | exclu (exclu : infrastructure-django) | - | - |  |
| re_path ^api/^status/ -> system_status [SystemStatusView.as_view] | src/paperless/urls.py:193 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/tags/ -> TagViewSet | src/paperless/urls.py:67 | couvert-autrement | route GET /api/v1/etiquettes | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| router /api/tasks/ -> TasksViewSet | src/paperless/urls.py:70 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| path ^api/^profile/totp/ -> totp_view [TOTPView.as_view] | src/paperless/urls.py:185 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| re_path ^api/^trash/ -> trash [TrashView.as_view] | src/paperless/urls.py:198 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\CorbeilleTest::testLaCorbeilleDeplaceDrapeauteEtNeffaceRien (couche 1) |  |
| re_path ^api/^ui_settings/ -> ui_settings [UiSettingsView.as_view] | src/paperless/urls.py:155 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| router /api/users/ -> UserViewSet | src/paperless/urls.py:71 | couvert-autrement | route GET /administration/comptes | phpunit Ged\Tests\Securite\V213DroitsRolesTest::testAdministrationCreeUnCompteEtUnGroupeAvecTraceDaudit (couche 1) |  |
| router /api/workflow_actions/ -> WorkflowActionViewSet | src/paperless/urls.py:77 | couvert-autrement | service Ged\Workflows\Moteur | phpunit Ged\Tests\Workflows\MoteurTest::testUneErreurDActionNeBloquePasLeResteDuPlan (couche 1) |  |
| router /api/workflow_triggers/ -> WorkflowTriggerViewSet | src/paperless/urls.py:76 | couvert-autrement | route POST /workflows/evenements | phpunit Ged\Tests\Workflows\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction (couche 1) |  |
| router /api/workflows/ -> WorkflowViewSet | src/paperless/urls.py:78 | couvert-autrement | route GET /workflows | phpunit Ged\Tests\Workflows\ModuleTest::testEcranRepondMemeSansTablesDuSecteur (couche 1) |  |
| ApplicationConfigurationViewSet.create (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ApplicationConfigurationViewSet.destroy (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ApplicationConfigurationViewSet.list (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ApplicationConfigurationViewSet.partial_update (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ApplicationConfigurationViewSet.retrieve (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ApplicationConfigurationViewSet.update (DRF ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| BulkDownloadView.post | src/documents/views.py:2261 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| BulkEditObjectsView.post | src/documents/views.py:2698 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| BulkEditView.post | src/documents/views.py:1513 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentViewSet.create (DRF ModelViewSet) | src/documents/views.py:388 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:388 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentViewSet.list (DRF ModelViewSet) | src/documents/views.py:388 | couvert-autrement | route GET /api/v1/emetteurs | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| CorrespondentViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:388 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:388 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CorrespondentViewSet.update (DRF ModelViewSet) | src/documents/views.py:388 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.create (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.list (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| CustomFieldViewSet.update (DRF ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DisconnectSocialAccountView.post | src/paperless/views.py:386 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentTypeViewSet.create (DRF ModelViewSet) | src/documents/views.py:462 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentTypeViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:462 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentTypeViewSet.list (DRF ModelViewSet) | src/documents/views.py:462 | couvert-autrement | route GET /api/v1/types | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| DocumentTypeViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:462 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentTypeViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:462 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| DocumentTypeViewSet.update (DRF ModelViewSet) | src/documents/views.py:462 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| FaviconView.get | src/paperless/views.py:97 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GenerateAuthTokenView.post | src/paperless/views.py:328 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GlobalSearchView.get | src/documents/views.py:1952 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.create (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.destroy (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.list (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.partial_update (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.retrieve (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| GroupViewSet.update (DRF ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| MailAccountViewSet.create (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route POST /courrier/comptes | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailAccountViewSet.destroy (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route POST /courrier/comptes/{id:[0-9]+}/basculer | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailAccountViewSet.list (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailAccountViewSet.partial_update (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route POST /courrier/comptes/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailAccountViewSet.retrieve (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route GET /courrier/comptes/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailAccountViewSet.update (DRF ModelViewSet) | src/paperless_mail/views.py:68 | couvert-autrement | route POST /courrier/comptes/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.create (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route POST /courrier/regles | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.destroy (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route POST /courrier/regles/{id:[0-9]+}/basculer | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.list (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.partial_update (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route POST /courrier/regles/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.retrieve (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route GET /courrier/regles/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| MailRuleViewSet.update (DRF ModelViewSet) | src/paperless_mail/views.py:165 | couvert-autrement | route POST /courrier/regles/{id:[0-9]+} | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| OauthCallbackView.get | src/paperless_mail/views.py:184 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| PostDocumentView.post | src/documents/views.py:1666 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ProcessedMailViewSet.list (DRF ReadOnlyModelViewSet) | src/paperless_mail/views.py:137 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| ProcessedMailViewSet.retrieve (DRF ReadOnlyModelViewSet) | src/paperless_mail/views.py:137 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ProfileView.get | src/paperless/views.py:189 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ProfileView.patch | src/paperless/views.py:195 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| RemoteVersionView.get | src/documents/views.py:2458 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SavedViewViewSet.create (DRF ModelViewSet) | src/documents/views.py:1452 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SavedViewViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:1452 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SavedViewViewSet.list (DRF ModelViewSet) | src/documents/views.py:1452 | couvert-autrement | route GET /api/v1/vues | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| SavedViewViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:1452 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SavedViewViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:1452 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SavedViewViewSet.update (DRF ModelViewSet) | src/documents/views.py:1452 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SearchAutoCompleteView.get | src/documents/views.py:1880 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SelectionDataView.post | src/documents/views.py:1785 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.create (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.list (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| ShareLinkViewSet.update (DRF ModelViewSet) | src/documents/views.py:2602 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SharedLinkView.get | src/documents/views.py:2623 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SocialAccountProvidersView.get | src/paperless/views.py:412 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StatisticsView.get | src/documents/views.py:2154 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.create (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.list (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| StoragePathViewSet.update (DRF ModelViewSet) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| SystemStatusView.get | src/documents/views.py:2941 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TOTPView.delete | src/paperless/views.py:294 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TOTPView.get | src/paperless/views.py:247 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TOTPView.post | src/paperless/views.py:264 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagViewSet.create (DRF ModelViewSet) | src/documents/views.py:425 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:425 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagViewSet.list (DRF ModelViewSet) | src/documents/views.py:425 | couvert-autrement | route GET /api/v1/etiquettes | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| TagViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:425 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:425 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TagViewSet.update (DRF ModelViewSet) | src/documents/views.py:425 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TasksViewSet.list (DRF ReadOnlyModelViewSet) | src/documents/views.py:2532 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TasksViewSet.retrieve (DRF ReadOnlyModelViewSet) | src/documents/views.py:2532 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TrashView.get | src/documents/views.py:3123 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| TrashView.post | src/documents/views.py:3127 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UiSettingsView.get | src/documents/views.py:2366 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UiSettingsView.post | src/documents/views.py:2434 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.create (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.destroy (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.list (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.partial_update (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.retrieve (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| UserViewSet.update (DRF ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.create (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.list (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowActionViewSet.update (DRF ModelViewSet) | src/documents/views.py:2780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.create (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.list (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowTriggerViewSet.update (DRF ModelViewSet) | src/documents/views.py:2762 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.create (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.destroy (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.list (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.partial_update (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.retrieve (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| WorkflowViewSet.update (DRF ModelViewSet) | src/documents/views.py:2805 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe ApplicationConfigurationViewSet(ModelViewSet) | src/paperless/views.py:349 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe BulkDownloadView(GenericAPIView) | src/documents/views.py:2256 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe BulkEditObjectsView(PassUserMixin) | src/documents/views.py:2693 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe BulkEditView(PassUserMixin) | src/documents/views.py:1490 | couvert-autrement | route POST /bibliotheque/masse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| classe CorrespondentViewSet(ModelViewSet, PermissionsAwareDocumentCountMixin) | src/documents/views.py:388 | couvert-autrement | route GET /api/v1/emetteurs | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| classe CustomFieldViewSet(ModelViewSet) | src/documents/views.py:2823 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe DisconnectSocialAccountView(GenericAPIView) | src/paperless/views.py:379 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe DocumentTypeViewSet(ModelViewSet, PermissionsAwareDocumentCountMixin) | src/documents/views.py:462 | couvert-autrement | route GET /api/v1/types | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| classe DocumentViewSet(PassUserMixin, RetrieveModelMixin, UpdateModelMixin, DestroyModelMixin, ListModelMixin, GenericViewSet) | src/documents/views.py:685 | couvert-autrement | route GET /api/v1/documents | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesNouvellesRoutesSontDeclareesHorsSession (couche 1) |  |
| classe FaviconView(View) | src/paperless/views.py:96 | exclu (exclu : infrastructure-django) | - | - |  |
| classe GenerateAuthTokenView(GenericAPIView) | src/paperless/views.py:320 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe GlobalSearchView(PassUserMixin) | src/documents/views.py:1948 | couvert-autrement | route GET /recherche | phpunit Ged\Tests\Recherche\EcranTest::testLesVraisResultatsArriventDansLeGabarit (couche 1) |  |
| classe GroupViewSet(ModelViewSet) | src/paperless/views.py:168 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe IndexView(TemplateView) | src/documents/views.py:209 | exclu (exclu : infrastructure-django) | - | - |  |
| classe LogViewSet(ViewSet) | src/documents/views.py:1402 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe MailAccountViewSet(ModelViewSet, PassUserMixin) | src/paperless_mail/views.py:68 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| classe MailRuleViewSet(ModelViewSet, PassUserMixin) | src/paperless_mail/views.py:165 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| classe OauthCallbackView(GenericAPIView) | src/paperless_mail/views.py:181 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe PaperlessObtainAuthTokenView(ObtainAuthToken) | src/paperless/views.py:48 | exclu (exclu : infrastructure-django) | - | - |  |
| classe PassUserMixin(GenericAPIView) | src/documents/views.py:251 | exclu (exclu : infrastructure-django) | - | - |  |
| classe PostDocumentView(GenericAPIView) | src/documents/views.py:1661 | couvert-autrement | route POST /api/v1/documents | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testDeposerUnFichierPasseParLAChaineDIngestionEtEstIdempotent (couche 1) |  |
| classe ProcessedMailViewSet(ReadOnlyModelViewSet, PassUserMixin) | src/paperless_mail/views.py:137 | couvert-autrement | route GET /courrier | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| classe ProfileView(GenericAPIView) | src/paperless/views.py:181 | couvert-autrement | route GET /profil | phpunit Ged\Tests\Jonctions\ProfilTest::testLAdresseEstVerifieeParUnLienEnvoyeParCourriel (couche 1) |  |
| classe RemoteVersionView(GenericAPIView) | src/documents/views.py:2455 | exclu (exclu : infrastructure-django) | - | - |  |
| classe SavedViewViewSet(ModelViewSet, PassUserMixin) | src/documents/views.py:1452 | couvert-autrement | route GET /api/v1/vues | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| classe SearchAutoCompleteView(GenericAPIView) | src/documents/views.py:1877 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe SelectionDataView(GenericAPIView) | src/documents/views.py:1780 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe ShareLinkViewSet(ModelViewSet, PassUserMixin) | src/documents/views.py:2602 | couvert-autrement | route POST /documents/{id:[0-9]+}/partages | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| classe SharedLinkView(View) | src/documents/views.py:2619 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe SocialAccountProvidersView(GenericAPIView) | src/paperless/views.py:405 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe StatisticsView(GenericAPIView) | src/documents/views.py:2151 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe StoragePathViewSet(ModelViewSet, PermissionsAwareDocumentCountMixin) | src/documents/views.py:2306 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe SystemStatusView(PassUserMixin) | src/documents/views.py:2938 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe TOTPView(GenericAPIView) | src/paperless/views.py:240 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe TagViewSet(ModelViewSet, PermissionsAwareDocumentCountMixin) | src/documents/views.py:425 | couvert-autrement | route GET /api/v1/etiquettes | phpunit Ged\Tests\Jonctions\DocumentsApiTest::testLesReferentielsSontExposes (couche 1) |  |
| classe TasksViewSet(ReadOnlyModelViewSet) | src/documents/views.py:2532 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| classe TrashView(ListModelMixin, PassUserMixin) | src/documents/views.py:3113 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe UiSettingsView(GenericAPIView) | src/documents/views.py:2361 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe UnifiedSearchViewSet(DocumentViewSet) | src/documents/views.py:1289 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe UserViewSet(ModelViewSet) | src/paperless/views.py:105 | absent (ecart assume, lot a creer) | - | - | V2-05 |
| classe WorkflowActionViewSet(ModelViewSet) | src/documents/views.py:2780 | couvert-autrement | service Ged\Workflows\Moteur | phpunit Ged\Tests\Workflows\MoteurTest::testUneErreurDActionNeBloquePasLeResteDuPlan (couche 1) |  |
| classe WorkflowTriggerViewSet(ModelViewSet) | src/documents/views.py:2762 | couvert-autrement | route POST /workflows/evenements | phpunit Ged\Tests\Workflows\ModuleTest::testEvenementDeDepotParRouteJournaliseLAction (couche 1) |  |
| classe WorkflowViewSet(ModelViewSet) | src/documents/views.py:2805 | couvert-autrement | route GET /workflows | phpunit Ged\Tests\Workflows\ModuleTest::testEcranRepondMemeSansTablesDuSecteur (couche 1) |  |

## chaine

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| consumer.ConsumerError | src/documents/consumer.py:76 | couvert-autrement | service Ged\Ingestion\ErreurConsommation | phpunit Ged\Tests\Parite\ChainePreflightTest::testErreurDeConsommationNommeLeFichierEtLaCause (couche 1) |  |
| consumer.ConsumerPlugin | src/documents/consumer.py:151 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| consumer.ConsumerPluginMixin | src/documents/consumer.py:101 | couvert-autrement | service Ged\Ingestion\EtapesConsommation | phpunit Ged\Tests\Parite\ChainePreflightTest::testEtapeNommeEchoueAvecLeFichier (couche 1) |  |
| consumer.ConsumerPreflightPlugin | src/documents/consumer.py:753 | couvert-autrement | service Ged\Ingestion\ControleAvant | phpunit Ged\Tests\Parite\ChainePreflightTest::testExecutionDuControleRendLesEtapes (couche 1) |  |
| consumer.ConsumerStatusShortMessage | src/documents/consumer.py:80 | couvert-autrement | service Ged\Ingestion\MessageStatut | phpunit Ged\Tests\Parite\ChainePreflightTest::testMessagesDeStatutSontCeuxDePaperless (couche 1) |  |
| barcodes.Barcode | src/documents/barcodes.py:38 | couvert-autrement | service Ged\Ingestion\CodeBarres | phpunit Ged\Tests\Parite\ChaineCodeBarresTest::testClassifieEtExtraitLeNumeroArchive (couche 1) |  |
| Barcode.is_asn | src/documents/barcodes.py:56 | couvert | service Ged\Ingestion\CodeBarres | phpunit Ged\Tests\Parite\ChaineCodeBarresTest::testClassifieEtExtraitLeNumeroArchive (couche 1) |  |
| Barcode.is_separator | src/documents/barcodes.py:48 | couvert | service Ged\Ingestion\CodeBarres | phpunit Ged\Tests\Parite\ChaineCodeBarresTest::testClassifieEtExtraitLeNumeroArchive (couche 1) |  |
| barcodes.BarcodePlugin | src/documents/barcodes.py:64 | couvert-autrement | service Ged\Ingestion\CodeBarres | phpunit Ged\Tests\Parite\ChaineCodeBarresTest::testRejoueLaDecoupeParCodesBarres (couche 1) |  |
| BarcodePlugin.run | src/documents/barcodes.py:118 | couvert-autrement | service Ged\Ingestion\CodeBarres | phpunit Ged\Tests\Parite\ChaineCodeBarresTest::testRejoueLaDecoupeParCodesBarres (couche 1) |  |
| ConsumerPreflightPlugin.pre_check_duplicate | src/documents/consumer.py:778 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois (couche 1) |  |
| ConsumerPlugin._parse_title_placeholders | src/documents/consumer.py:585 | couvert | service Ged\Ingestion\GabaritTitre | phpunit Ged\Tests\Parite\ChainePreflightTest::testTitreAPlaceholders (couche 1) |  |
| ConsumerPlugin._store | src/documents/consumer.py:614 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| ConsumerPlugin._write | src/documents/consumer.py:739 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| ConsumerPlugin.apply_overrides | src/documents/consumer.py:679 | couvert | service Ged\Ingestion\Surcharges | phpunit Ged\Tests\Parite\ChainePreflightTest::testSurchargesNappliquentQueCeQuiEstFourni (couche 1) |  |
| ConsumerPlugin.run | src/documents/consumer.py:281 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| ConsumerPreflightPlugin.pre_check_asn_value | src/documents/consumer.py:811 | couvert | service Ged\Ingestion\ControleAvant | phpunit Ged\Tests\Parite\ChainePreflightTest::testControleDesNumerosArchive (couche 1) |  |
| ConsumerPreflightPlugin.pre_check_directories | src/documents/consumer.py:802 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testMemoireDEtatDansUnDossierQuiNExistePasEncore (couche 1) |  |
| ConsumerPreflightPlugin.pre_check_duplicate | src/documents/consumer.py:778 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois (couche 1) |  |
| ConsumerPreflightPlugin.pre_check_file_exists | src/documents/consumer.py:764 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLeMemeFichierNEntreJamaisDeuxFois (couche 1) |  |
| ConsumerPreflightPlugin.run | src/documents/consumer.py:847 | couvert-autrement | service Ged\Ingestion\ControleAvant | phpunit Ged\Tests\Parite\ChainePreflightTest::testExecutionDuControleRendLesEtapes (couche 1) |  |
| filepath._is_safe_relative_path | src/documents/templating/filepath.py:265 | couvert | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testCheminRelatifSurRefuseAbsoluEtRemontee (couche 1) |  |
| filepath.create_dummy_document | src/documents/templating/filepath.py:93 | couvert-autrement | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testLeDocumentTemoinPorteTousLesPlaceholders (couche 1) |  |
| filepath.get_added_date_context | src/documents/templating/filepath.py:137 | couvert | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testContexteDeDatesEtDeMetadonnees (couche 1) |  |
| filepath.get_basic_metadata_context | src/documents/templating/filepath.py:155 | couvert-autrement | service Ged\Socle\Fichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| filepath.get_creation_date_context | src/documents/templating/filepath.py:121 | couvert | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testContexteDeDatesEtDeMetadonnees (couche 1) |  |
| filepath.get_custom_fields_context | src/documents/templating/filepath.py:212 | couvert | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testContexteDeDatesEtDeMetadonnees (couche 1) |  |
| filepath.get_tags_context | src/documents/templating/filepath.py:196 | couvert | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testContexteDeDatesEtDeMetadonnees (couche 1) |  |
| filepath.validate_filepath_template_and_render | src/documents/templating/filepath.py:276 | couvert-autrement | service Ged\Ingestion\GabaritChemin | phpunit Ged\Tests\Parite\ChaineGabaritTest::testRejoueLesVecteursDuGabarit (couche 1) |  |
| DocumentParser.extract_metadata | src/documents/parsers.py:380 | couvert-autrement | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testMetadonneesDunePiece (couche 1) |  |
| DocumentParser.get_date | src/documents/parsers.py:401 | couvert-autrement | service Ged\Classement\Regles | phpunit Ged\Tests\Classement\ReglesTest::testDateEnTete (couche 1) |  |
| DocumentParser.get_page_count | src/documents/parsers.py:383 | couvert | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testCompteLesPagesDunPdf (couche 1) |  |
| DocumentParser.get_text | src/documents/parsers.py:398 | couvert | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testLitUnTexteLocalEtRemplaceLesOctetsInvalides (couche 1) |  |
| DocumentParser.get_thumbnail | src/documents/parsers.py:392 | couvert-autrement | service Ged\Apercu\Miniature | phpunit Ged\Tests\Apercu\MiniatureTest::testGenereUneVignetteDepuisUnPdf (couche 1) |  |
| DocumentParser.parse | src/documents/parsers.py:386 | couvert-autrement | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testMetadonneesDunePiece (couche 1) |  |
| MailDocumentParser.extract_metadata | src/paperless_mail/parsers.py:72 | couvert-autrement | service Ged\Jonctions\Courriel | phpunit Ged\Tests\Jonctions\CourrielTest::testUnEmlDonneTexteIndexeApercuNettoyeEtPiecesListees (couche 1) |  |
| MailDocumentParser.get_thumbnail | src/paperless_mail/parsers.py:55 | couvert-autrement | service Ged\Jonctions\Courriel | phpunit Ged\Tests\Jonctions\CourrielTest::testUnEmlDonneTexteIndexeApercuNettoyeEtPiecesListees (couche 1) |  |
| MailDocumentParser.parse | src/paperless_mail/parsers.py:125 | couvert-autrement | service Ged\Jonctions\Courriel | phpunit Ged\Tests\Jonctions\CourrielTest::testUnEmlDonneTexteIndexeApercuNettoyeEtPiecesListees (couche 1) |  |
| RasterisedDocumentParser.extract_metadata | src/paperless_tesseract/parsers.py:57 | couvert-autrement | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testMetadonneesDunePiece (couche 1) |  |
| RasterisedDocumentParser.extract_text | src/paperless_tesseract/parsers.py:154 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| RasterisedDocumentParser.get_page_count | src/paperless_tesseract/parsers.py:43 | couvert | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testCompteLesPagesDunPdf (couche 1) |  |
| RasterisedDocumentParser.get_thumbnail | src/paperless_tesseract/parsers.py:96 | couvert-autrement | service Ged\Apercu\Miniature | phpunit Ged\Tests\Apercu\MiniatureTest::testGenereUneVignetteDepuisUnPdf (couche 1) |  |
| RasterisedDocumentParser.parse | src/paperless_tesseract/parsers.py:333 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| TextDocumentParser.get_thumbnail | src/paperless_text/parsers.py:18 | absent (Ecart assume : GEDv2 ne fabrique pas de vignette pour un fichier texte — l’aperçu est le texte lui-même (Apercu\Piece, Apercu\Source).) | - | - | V2-apercu-texte |
| TextDocumentParser.parse | src/paperless_text/parsers.py:43 | couvert | service Ged\Ingestion\ParseurPiece | phpunit Ged\Tests\Parite\ChaineParseursTest::testLitUnTexteLocalEtRemplaceLesOctetsInvalides (couche 1) |  |
| TikaDocumentParser.extract_metadata | src/paperless_tika/parsers.py:34 | absent (Ecart assume : la bureautique (odt/rtf/doc/docx) passe par LibreOffice → PDF (Apercu\Outils::versPdf), non prouvée par un test et hors périmètre de ce lot.) | - | - | V2-bureautique |
| TikaDocumentParser.get_thumbnail | src/paperless_tika/parsers.py:24 | absent (Ecart assume : vignette bureautique non prouvée (LibreOffice → PDF → PNG via Apercu), hors périmètre de ce lot.) | - | - | V2-bureautique |
| TikaDocumentParser.parse | src/paperless_tika/parsers.py:56 | absent (Ecart assume : parse bureautique délégué à LibreOffice/Gotenberg, non prouvé par un test, hors périmètre de ce lot.) | - | - | V2-bureautique |
| get_default_file_extension | src/documents/parsers.py:69 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testRejoueLesVecteursDuRegistre (couche 1) |  |
| get_default_thumbnail | src/documents/parsers.py:184 | absent (Ecart assume : GEDv2 ne sert pas de vignette générique ; il NOMME l’absence (Apercu\Outils::MSG_FORMAT, MSG_VIGNETTE), jamais une image de remplacement.) | - | - | V2-apercu-defaut |
| get_parser_class_for_mime_type | src/documents/parsers.py:116 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testRejoueLesVecteursDuRegistre (couche 1) |  |
| get_supported_file_extensions | src/documents/parsers.py:100 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testRejoueLesVecteursDuRegistre (couche 1) |  |
| is_file_ext_supported | src/documents/parsers.py:89 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testRejoueLesVecteursDuRegistre (couche 1) |  |
| is_mime_type_supported | src/documents/parsers.py:61 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testMimeSupporteSuitLeParseurPourMime (couche 1) |  |
| make_thumbnail_from_pdf | src/documents/parsers.py:235 | couvert-autrement | service Ged\Apercu\Miniature | phpunit Ged\Tests\Apercu\MiniatureTest::testGenereUneVignetteDepuisUnPdf (couche 1) |  |
| make_thumbnail_from_pdf_gs_fallback | src/documents/parsers.py:191 | couvert-autrement | service Ged\Apercu\Miniature | phpunit Ged\Tests\Apercu\MiniatureTest::testLaVignetteDuRepliGhostscriptRespecteLaTaille (couche 1) |  |
| parse_date | src/documents/parsers.py:262 | couvert-autrement | service Ged\Classement\Regles | phpunit Ged\Tests\Classement\ReglesTest::testDateEnTete (couche 1) |  |
| parse_date_generator | src/documents/parsers.py:266 | couvert-autrement | service Ged\Classement\Regles | phpunit Ged\Tests\Classement\DatesDansTexteTest::testDateDansLeTexte (couche 1) |  |
| run_convert | src/documents/parsers.py:140 | couvert | service Ged\Ingestion\RegistreParseurs | phpunit Ged\Tests\Parite\ChaineRegistreTest::testArgumentsConvertSuiventLOrdreDePaperless (couche 1) |  |
| post_process_text | src/paperless_tesseract/parsers.py:461 | couvert | service Ged\Ingestion\TexteOcr | phpunit Ged\Tests\Parite\ChaineOcrTest::testRejoueLePostTraitementDuTexteOcr (couche 1) |  |

## commande

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| documents manage.py convert_mariadb_uuid | src/documents/management/commands/convert_mariadb_uuid.py:1 | exclu (exclu : infrastructure-django) | - | - |  |
| documents manage.py decrypt_documents | src/documents/management/commands/decrypt_documents.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| documents manage.py document_archiver | src/documents/management/commands/document_archiver.py:1 | couvert-autrement | service Ged\Ingestion\CopieArchivage | phpunit Ged\Tests\Ingestion\CopieArchivageTest::testUnPdfTexteDonneUneCopiePdfA2bAvecXmpOutputIntentEtTexteConserve (couche 1) |  |
| documents manage.py document_consumer | src/documents/management/commands/document_consumer.py:1 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| documents manage.py document_create_classifier | src/documents/management/commands/document_create_classifier.py:1 | couvert-autrement | service Ged\Classement\Habitudes | phpunit Ged\Tests\Classement\HabitudesTest::testTroisChoixIdentiquesFontUneHabitude (couche 1) |  |
| documents manage.py document_exporter | src/documents/management/commands/document_exporter.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| documents manage.py document_fuzzy_match | src/documents/management/commands/document_fuzzy_match.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| documents manage.py document_importer | src/documents/management/commands/document_importer.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| documents manage.py document_index | src/documents/management/commands/document_index.py:1 | couvert-autrement | service Ged\Socle\ReconstructionIndex | phpunit Ged\Tests\Socle\ReconstructionTest::testLaCommandeReconstruireIndexRendLeMemeHash (couche 1) |  |
| documents manage.py document_renamer | src/documents/management/commands/document_renamer.py:1 | couvert-autrement | service Ged\Socle\Fichiers | phpunit Ged\Tests\Socle\RangementTest::testMiseAJourDeplaceFichierMetaVersionsEtTrace (couche 1) |  |
| documents manage.py document_retagger | src/documents/management/commands/document_retagger.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| documents manage.py document_sanity_checker | src/documents/management/commands/document_sanity_checker.py:1 | couvert-autrement | service Ged\Archivage\Verificateur | phpunit Ged\Tests\Archivage\VerificateurTest::testFondsSainRendZeroEcart (couche 1) |  |
| documents manage.py document_thumbnails | src/documents/management/commands/document_thumbnails.py:1 | couvert-autrement | service Ged\Apercu\Miniature | phpunit Ged\Tests\Apercu\MiniatureTest::testGenereUneVignetteDepuisUnPdf (couche 1) |  |
| documents manage.py loaddata_stdin | src/documents/management/commands/loaddata_stdin.py:1 | exclu (exclu : infrastructure-django) | - | - |  |
| documents manage.py manage_superuser | src/documents/management/commands/manage_superuser.py:1 | couvert-autrement | service Ged\Socle\Schema | phpunit Ged\Tests\Socle\InstallateurTest::testPoseUnSchemaNeufAvecCompteAdmin (couche 1) |  |
| documents manage.py prune_audit_logs | src/documents/management/commands/prune_audit_logs.py:1 | absent (ecart assume, lot a creer) | - | - | V2-00 |
| paperless_mail manage.py mail_fetcher | src/paperless_mail/management/commands/mail_fetcher.py:1 | couvert-autrement | service Ged\Ingestion\Courrier\Moteur | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |

## correspondance

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| MATCH_ALL | src/documents/models.py:48 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchAll (couche 1) |  |
| MATCH_ANY | src/documents/models.py:47 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchAny (couche 1) |  |
| MATCH_AUTO | src/documents/models.py:52 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchNoneEtMatchAutoNeCorrespondentJamais (couche 1) |  |
| MATCH_FUZZY | src/documents/models.py:51 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchFuzzy (couche 1) |  |
| MATCH_LITERAL | src/documents/models.py:49 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchLiteral (couche 1) |  |
| MATCH_NONE | src/documents/models.py:46 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testUnMotifVideNeCorrespondJamais (couche 1) |  |
| MATCH_REGEX | src/documents/models.py:50 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchRegex (couche 1) |  |
| matching._split_match | src/documents/matching.py:252 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testMatchAll (couche 1) |  |
| matching.match_correspondents | src/documents/matching.py:47 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| matching.match_document_types | src/documents/matching.py:75 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| matching.match_storage_paths | src/documents/matching.py:127 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| matching.match_tags | src/documents/matching.py:102 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| matching.matches | src/documents/matching.py:155 | couvert-autrement | service Ged\Classement\Correspondance | phpunit Ged\Tests\Classement\CorrespondanceTest::testUnMotifVideNeCorrespondJamais (couche 1) |  |

## ecran

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| reglages/General | src-ui/src/app/components/admin/settings/settings.component.ts:72 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| reglages/Notifications | src-ui/src/app/components/admin/settings/settings.component.ts:74 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| reglages/Permissions | src-ui/src/app/components/admin/settings/settings.component.ts:73 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| reglages/SavedViews | src-ui/src/app/components/admin/settings/settings.component.ts:75 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI '404' -> NotFoundComponent | src-ui/src/app/app-routing.module.ts:303 | couvert-autrement | ecran templates/erreur.twig | phpunit Ged\Tests\EcransTest::testPageInconnueEstNommee (couche 1) |  |
| route UI 'asn/:id' -> DocumentAsnComponent | src-ui/src/app/app-routing.module.ts:97 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'config' -> ConfigComponent | src-ui/src/app/app-routing.module.ts:217 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'correspondents' -> CorrespondentListComponent | src-ui/src/app/app-routing.module.ts:133 | couvert-autrement | ecran templates/ecrans/metadonnees.twig | phpunit Ged\Tests\Socle\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds (couche 1) |  |
| route UI 'customfields' -> CustomFieldsComponent | src-ui/src/app/app-routing.module.ts:241 | couvert-autrement | ecran templates/ecrans/metadonnees.twig | phpunit Ged\Tests\Socle\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds (couche 1) |  |
| route UI 'dashboard' -> DashboardComponent | src-ui/src/app/app-routing.module.ts:40 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'documents' -> DocumentListComponent | src-ui/src/app/app-routing.module.ts:47 | couvert-autrement | ecran templates/ecrans/bibliotheque.twig | phpunit Ged\Tests\EcransTest::testEcranRepondEtSeDesigne (couche 1) |  |
| route UI 'documents/:id' -> DocumentDetailComponent | src-ui/src/app/app-routing.module.ts:73 | couvert-autrement | ecran templates/ecrans/document.twig | phpunit Ged\Tests\EcransTest::testEcranRepondEtSeDesigne (couche 1) |  |
| route UI 'documents/:id/:section' -> DocumentDetailComponent | src-ui/src/app/app-routing.module.ts:85 | couvert-autrement | ecran templates/ecrans/document.twig | phpunit Ged\Tests\EcransTest::testEcranRepondEtSeDesigne (couche 1) |  |
| route UI 'documenttypes' -> DocumentTypeListComponent | src-ui/src/app/app-routing.module.ts:121 | couvert-autrement | ecran templates/ecrans/metadonnees.twig | phpunit Ged\Tests\Socle\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds (couche 1) |  |
| route UI 'logs' -> LogsComponent | src-ui/src/app/app-routing.module.ts:157 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'mail' -> MailComponent | src-ui/src/app/app-routing.module.ts:265 | couvert-autrement | ecran templates/ecrans/courrier.twig | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| route UI 'savedviews' -> SavedViewsComponent | src-ui/src/app/app-routing.module.ts:289 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'settings' -> SettingsComponent | src-ui/src/app/app-routing.module.ts:191 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'settings/mail' -> SettingsComponent | src-ui/src/app/app-routing.module.ts:179 | couvert-autrement | ecran templates/ecrans/courrier.twig | phpunit Ged\Tests\Ingestion\Courrier\ModuleTest::testLAdministrateurCreeUnCompteLeTesteCreeUneRegleEtTraiteLeCourrier (couche 1) |  |
| route UI 'settings/savedviews' -> SettingsComponent | src-ui/src/app/app-routing.module.ts:187 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'settings/:section' -> SettingsComponent | src-ui/src/app/app-routing.module.ts:204 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'settings/usersgroups' -> SettingsComponent | src-ui/src/app/app-routing.module.ts:183 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'storagepaths' -> StoragePathListComponent | src-ui/src/app/app-routing.module.ts:145 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'tags' -> TagListComponent | src-ui/src/app/app-routing.module.ts:109 | couvert-autrement | ecran templates/ecrans/metadonnees.twig | phpunit Ged\Tests\Socle\EcranMetadonneesTest::testLOuvertureDeLÉcranNEcritJamaisDansLeFonds (couche 1) |  |
| route UI 'tasks' -> TasksComponent | src-ui/src/app/app-routing.module.ts:229 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'trash' -> TrashComponent | src-ui/src/app/app-routing.module.ts:166 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI 'usersgroups' -> UsersAndGroupsComponent | src-ui/src/app/app-routing.module.ts:277 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| route UI '' -> AppFrameComponent | src-ui/src/app/app-routing.module.ts:33 | exclu (exclu : infrastructure-django) | - | - |  |
| route UI 'view/:id' -> DocumentListComponent | src-ui/src/app/app-routing.module.ts:60 | couvert-autrement | ecran templates/ecrans/bibliotheque.twig | phpunit Ged\Tests\Socle\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier (couche 1) |  |
| route UI 'workflows' -> WorkflowsComponent | src-ui/src/app/app-routing.module.ts:253 | couvert-autrement | ecran templates/ecrans/workflows.twig | phpunit Ged\Tests\Workflows\ModuleTest::testEcranRepondMemeSansTablesDuSecteur (couche 1) |  |

## masse

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| bulk_edit.add_tag | src/documents/bulk_edit.py:99 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.delete | src/documents/bulk_edit.py:275 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLaCorbeilleEstUneMiseEnCorbeilleJamaisUneSuppression (couche 1) |  |
| bulk_edit.delete_pages | src/documents/bulk_edit.py:522 | couvert-autrement | service Ged\Ingestion\EditeurPdf | phpunit Ged\Tests\Ingestion\EditeurPdfTest::testTournerScinderFusionnerEtRetirerReecriventDesPdfValides (couche 1) |  |
| bulk_edit.edit_pdf | src/documents/bulk_edit.py:550 | couvert-autrement | service Ged\Ingestion\EditeurPdf | phpunit Ged\Tests\Ingestion\EditeurPdfTest::testTournerScinderFusionnerEtRetirerReecriventDesPdfValides (couche 1) |  |
| bulk_edit.merge | src/documents/bulk_edit.py:372 | couvert-autrement | service Ged\Ingestion\EditeurPdf | phpunit Ged\Tests\Ingestion\EditeurPdfTest::testTournerScinderFusionnerEtRetirerReecriventDesPdfValides (couche 1) |  |
| bulk_edit.modify_custom_fields | src/documents/bulk_edit.py:205 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.modify_tags | src/documents/bulk_edit.py:143 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.reflect_doclinks | src/documents/bulk_edit.py:647 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| bulk_edit.remove_doclink | src/documents/bulk_edit.py:710 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteRetireeSortDeLIndexEtUnLienEstVisibleDesDeuxCotes (couche 1) |  |
| bulk_edit.remove_tag | src/documents/bulk_edit.py:125 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.reprocess | src/documents/bulk_edit.py:297 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| bulk_edit.rotate | src/documents/bulk_edit.py:331 | couvert-autrement | service Ged\Ingestion\EditeurPdf | phpunit Ged\Tests\Ingestion\EditeurPdfTest::testTournerScinderFusionnerEtRetirerReecriventDesPdfValides (couche 1) |  |
| bulk_edit.set_correspondent | src/documents/bulk_edit.py:41 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.set_document_type | src/documents/bulk_edit.py:82 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.set_permissions | src/documents/bulk_edit.py:306 | absent (ecart assume, lot a creer) | - | - | V2-03 |
| bulk_edit.set_storage_path | src/documents/bulk_edit.py:61 | couvert-autrement | service Ged\Socle\ModificationMasse | phpunit Ged\Tests\Socle\ModificationMasseTest::testLeServiceEnMasseProduitLesMemeEffetsQueLEditionUnitaire (couche 1) |  |
| bulk_edit.split | src/documents/bulk_edit.py:458 | couvert-autrement | service Ged\Ingestion\EditeurPdf | phpunit Ged\Tests\Ingestion\EditeurPdfTest::testTournerScinderFusionnerEtRetirerReecriventDesPdfValides (couche 1) |  |

## modele

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| CustomField.created | src/documents/models.py:797 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| CustomField.data_type | src/documents/models.py:806 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| CustomField.extra_data | src/documents/models.py:813 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| CustomField.name | src/documents/models.py:804 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| CustomFieldInstance.created | src/documents/models.py:856 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomFieldInstance.document | src/documents/models.py:863 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.field | src/documents/models.py:872 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_bool | src/documents/models.py:884 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_date | src/documents/models.py:888 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_document_ids | src/documents/models.py:917 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomFieldInstance.value_float | src/documents/models.py:892 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_int | src/documents/models.py:890 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_long_text | src/documents/models.py:921 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_monetary | src/documents/models.py:894 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_monetary_amount | src/documents/models.py:896 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomFieldInstance.value_select | src/documents/models.py:919 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_text | src/documents/models.py:882 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| CustomFieldInstance.value_url | src/documents/models.py:886 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.added | src/documents/models.py:261 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.archive_checksum | src/documents/models.py:219 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.archive_filename | src/documents/models.py:278 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.archive_serial_number | src/documents/models.py:301 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.checksum | src/documents/models.py:211 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.content | src/documents/models.py:193 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.correspondent | src/documents/models.py:164 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.created | src/documents/models.py:240 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.document_type | src/documents/models.py:184 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.filename | src/documents/models.py:268 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.mime_type | src/documents/models.py:202 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.modified | src/documents/models.py:246 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.original_filename | src/documents/models.py:288 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.page_count | src/documents/models.py:228 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.storage_path | src/documents/models.py:173 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.storage_type | src/documents/models.py:253 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Document.tags | src/documents/models.py:204 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| Document.title | src/documents/models.py:182 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| MailAccount.account_type | src/paperless_mail/models.py:59 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.character_set | src/paperless_mail/models.py:49 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.expiration | src/paperless_mail/models.py:74 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.imap_port | src/paperless_mail/models.py:27 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.imap_security | src/paperless_mail/models.py:37 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.imap_server | src/paperless_mail/models.py:25 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.is_token | src/paperless_mail/models.py:47 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.name | src/paperless_mail/models.py:23 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.password | src/paperless_mail/models.py:45 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.refresh_token | src/paperless_mail/models.py:65 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailAccount.username | src/paperless_mail/models.py:43 | couvert-autrement | service Ged\Ingestion\Courrier\Comptes | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| MailRule.account | src/paperless_mail/models.py:147 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.action | src/paperless_mail/models.py:246 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.action_parameter | src/paperless_mail/models.py:252 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_correspondent | src/paperless_mail/models.py:291 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_correspondent_from | src/paperless_mail/models.py:285 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_document_type | src/paperless_mail/models.py:277 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_owner_from_rule | src/paperless_mail/models.py:299 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_tags | src/paperless_mail/models.py:271 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.assign_title_from | src/paperless_mail/models.py:265 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.attachment_type | src/paperless_mail/models.py:224 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.consumption_scope | src/paperless_mail/models.py:234 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.enabled | src/paperless_mail/models.py:154 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_attachment_filename_exclude | src/paperless_mail/models.py:206 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_attachment_filename_include | src/paperless_mail/models.py:194 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_body | src/paperless_mail/models.py:187 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_from | src/paperless_mail/models.py:166 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_subject | src/paperless_mail/models.py:180 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.filter_to | src/paperless_mail/models.py:173 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.folder | src/paperless_mail/models.py:156 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.maximum_age | src/paperless_mail/models.py:218 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.name | src/paperless_mail/models.py:143 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.order | src/paperless_mail/models.py:145 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MailRule.pdf_layout | src/paperless_mail/models.py:240 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| MatchingModel.is_insensitive | src/documents/models.py:74 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| MatchingModel.match | src/documents/models.py:66 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| MatchingModel.matching_algorithm | src/documents/models.py:68 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| MatchingModel.name | src/documents/models.py:64 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| ModelWithOwner.owner | src/documents/models.py:32 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Note.created | src/documents/models.py:687 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| Note.document | src/documents/models.py:693 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| Note.note | src/documents/models.py:681 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| Note.user | src/documents/models.py:702 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| PaperlessTask.acknowledged | src/documents/models.py:609 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.date_created | src/documents/models.py:638 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.date_done | src/documents/models.py:652 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.date_started | src/documents/models.py:645 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.result | src/documents/models.py:659 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.status | src/documents/models.py:630 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.task_file_name | src/documents/models.py:615 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.task_id | src/documents/models.py:602 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.task_name | src/documents/models.py:622 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| PaperlessTask.type | src/documents/models.py:668 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| ProcessedMail.error | src/paperless_mail/models.py:362 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.folder | src/paperless_mail/models.py:317 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.processed | src/paperless_mail/models.py:348 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.received | src/paperless_mail/models.py:341 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.rule | src/paperless_mail/models.py:309 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.status | src/paperless_mail/models.py:354 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.subject | src/paperless_mail/models.py:333 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| ProcessedMail.uid | src/paperless_mail/models.py:325 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| SavedView.display_fields | src/documents/models.py:488 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.display_mode | src/documents/models.py:480 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.name | src/documents/models.py:456 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier (couche 1) |  |
| SavedView.page_size | src/documents/models.py:473 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.show_in_sidebar | src/documents/models.py:461 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.show_on_dashboard | src/documents/models.py:458 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.sort_field | src/documents/models.py:465 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedView.sort_reverse | src/documents/models.py:471 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| SavedViewFilterRule.rule_type | src/documents/models.py:562 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testCompilerDesFiltresTypés (couche 1) |  |
| SavedViewFilterRule.saved_view | src/documents/models.py:555 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testCompilerDesFiltresTypés (couche 1) |  |
| SavedViewFilterRule.value | src/documents/models.py:564 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testCompilerDesFiltresTypés (couche 1) |  |
| ShareLink.created | src/documents/models.py:725 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| ShareLink.document | src/documents/models.py:748 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| ShareLink.expiration | src/documents/models.py:733 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| ShareLink.file_version | src/documents/models.py:756 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| ShareLink.owner | src/documents/models.py:762 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| ShareLink.slug | src/documents/models.py:740 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| StoragePath.path | src/documents/models.py:147 | couvert-autrement | service Ged\Socle\Nomenclature | phpunit Ged\Tests\Socle\NomenclatureTest::testLesTypesLivresSontLisiblesDansLOrdreDuPlan (couche 1) |  |
| Tag.color | src/documents/models.py:102 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Tag.is_inbox_tag | src/documents/models.py:106 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| UiSettings.settings | src/documents/models.py:581 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| UiSettings.user | src/documents/models.py:576 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| Workflow.actions | src/documents/models.py:1539 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| Workflow.enabled | src/documents/models.py:1546 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| Workflow.name | src/documents/models.py:1528 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| Workflow.order | src/documents/models.py:1530 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| Workflow.triggers | src/documents/models.py:1532 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| WorkflowAction.assign_change_groups | src/documents/models.py:1372 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_change_users | src/documents/models.py:1365 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_correspondent | src/documents/models.py:1324 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_custom_fields | src/documents/models.py:1379 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_custom_fields_values | src/documents/models.py:1386 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_document_type | src/documents/models.py:1315 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_owner | src/documents/models.py:1342 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_storage_path | src/documents/models.py:1333 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_tags | src/documents/models.py:1308 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_title | src/documents/models.py:1299 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_view_groups | src/documents/models.py:1358 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.assign_view_users | src/documents/models.py:1351 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.email | src/documents/models.py:1501 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.order | src/documents/models.py:1297 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_correspondents | src/documents/models.py:1427 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_custom_fields | src/documents/models.py:1496 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_document_types | src/documents/models.py:1415 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_owners | src/documents/models.py:1451 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_permissions | src/documents/models.py:1484 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_storage_paths | src/documents/models.py:1439 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_all_tags | src/documents/models.py:1403 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_change_groups | src/documents/models.py:1477 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_change_users | src/documents/models.py:1470 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_correspondents | src/documents/models.py:1420 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_custom_fields | src/documents/models.py:1489 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_document_types | src/documents/models.py:1408 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_owners | src/documents/models.py:1444 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_storage_paths | src/documents/models.py:1432 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_tags | src/documents/models.py:1396 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_view_groups | src/documents/models.py:1463 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.remove_view_users | src/documents/models.py:1456 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.type | src/documents/models.py:1291 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowAction.webhook | src/documents/models.py:1510 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDeRetraitEstApplique (couche 1) |  |
| WorkflowActionEmail.body | src/documents/models.py:1196 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsCourrielDeWorkflowActionEmail (couche 1) |  |
| WorkflowActionEmail.include_document | src/documents/models.py:1213 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsCourrielDeWorkflowActionEmail (couche 1) |  |
| WorkflowActionEmail.subject | src/documents/models.py:1186 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsCourrielDeWorkflowActionEmail (couche 1) |  |
| WorkflowActionEmail.to | src/documents/models.py:1205 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsCourrielDeWorkflowActionEmail (couche 1) |  |
| WorkflowActionWebhook.as_json | src/documents/models.py:1237 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.body | src/documents/models.py:1249 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.headers | src/documents/models.py:1256 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.include_document | src/documents/models.py:1263 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.params | src/documents/models.py:1242 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.url | src/documents/models.py:1225 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowActionWebhook.use_params | src/documents/models.py:1232 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesChampsWebhookDeWorkflowActionWebhook (couche 1) |  |
| WorkflowRun.document | src/documents/models.py:1566 | couvert-autrement | service Ged\Workflows\Executions | phpunit Ged\Tests\Workflows\ApplicateurTest::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution (couche 1) |  |
| WorkflowRun.run_at | src/documents/models.py:1574 | couvert-autrement | service Ged\Workflows\Executions | phpunit Ged\Tests\Workflows\ApplicateurTest::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution (couche 1) |  |
| WorkflowRun.type | src/documents/models.py:1560 | couvert-autrement | service Ged\Workflows\Executions | phpunit Ged\Tests\Workflows\ApplicateurTest::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution (couche 1) |  |
| WorkflowRun.workflow | src/documents/models.py:1553 | couvert-autrement | service Ged\Workflows\Executions | phpunit Ged\Tests\Workflows\ApplicateurTest::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution (couche 1) |  |
| WorkflowTrigger.filter_custom_field_query | src/documents/models.py:1127 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_filename | src/documents/models.py:1032 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_all_tags | src/documents/models.py:1068 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_correspondent | src/documents/models.py:1097 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_document_type | src/documents/models.py:1082 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_not_correspondents | src/documents/models.py:1105 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_not_document_types | src/documents/models.py:1090 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_not_storage_paths | src/documents/models.py:1120 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_not_tags | src/documents/models.py:1075 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_storage_path | src/documents/models.py:1112 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_has_tags | src/documents/models.py:1062 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_mailrule | src/documents/models.py:1044 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.filter_path | src/documents/models.py:1020 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.is_insensitive | src/documents/models.py:1060 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.match | src/documents/models.py:1052 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.matching_algorithm | src/documents/models.py:1054 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.schedule_date_custom_field | src/documents/models.py:1169 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.schedule_date_field | src/documents/models.py:1159 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.schedule_is_recurring | src/documents/models.py:1142 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.schedule_offset_days | src/documents/models.py:1134 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.schedule_recurring_interval_days | src/documents/models.py:1150 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| WorkflowTrigger.type | src/documents/models.py:1008 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesFiltresDeConsommation (couche 1) |  |
| classe Correspondent(MatchingModel) | src/documents/models.py:95 | couvert-autrement | service Ged\Socle\Nomenclature | phpunit Ged\Tests\Socle\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase (couche 1) |  |
| classe CustomField(models.Model) | src/documents/models.py:780 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| classe CustomFieldInstance(SoftDeleteModel) | src/documents/models.py:837 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testLaValeurVitDansLeMetaJsonEtEstPortéeParLIndex (couche 1) |  |
| classe Document(SoftDeleteModel, ModelWithOwner) | src/documents/models.py:156 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| classe DocumentType(MatchingModel) | src/documents/models.py:140 | couvert-autrement | service Ged\Socle\Nomenclature | phpunit Ged\Tests\Socle\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase (couche 1) |  |
| classe MailAccount(document_models.ModelWithOwner) | src/paperless_mail/models.py:8 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\CoffreTest::testLeMotDePasseNEstJamaisEnClairDansLeFichierNiALEcran (couche 1) |  |
| classe MailRule(document_models.ModelWithOwner) | src/paperless_mail/models.py:87 | couvert-autrement | service Ged\Ingestion\Courrier\Regles | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testUneRegleSeRelitALEIdentique (couche 1) |  |
| classe MatchingModel(ModelWithOwner) | src/documents/models.py:45 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| classe ModelWithOwner(models.Model) | src/documents/models.py:31 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| classe Note(SoftDeleteModel) | src/documents/models.py:680 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| classe PaperlessTask(ModelWithOwner) | src/documents/models.py:587 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| classe ProcessedMail(document_models.ModelWithOwner) | src/paperless_mail/models.py:308 | couvert-autrement | service Ged\Ingestion\Courrier\Traites | phpunit Ged\Tests\Ingestion\Courrier\TraitesTest::testUnMessageVUUneFoisNEstPlusJamaisTraite (couche 1) |  |
| classe SavedView(ModelWithOwner) | src/documents/models.py:435 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testUneVueEnregistréeSeRelitEtNeDéplaceAucunFichier (couche 1) |  |
| classe SavedViewFilterRule(models.Model) | src/documents/models.py:503 | couvert-autrement | service Ged\Socle\Vues | phpunit Ged\Tests\Socle\VuesTest::testCompilerDesFiltresTypés (couche 1) |  |
| classe ShareLink(SoftDeleteModel) | src/documents/models.py:720 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |
| classe StoragePath(MatchingModel) | src/documents/models.py:146 | couvert-autrement | service Ged\Socle\Fichiers | phpunit Ged\Tests\Socle\RangementTest::testDepotRangeFichierMetaEtVersion1 (couche 1) |  |
| classe Tag(MatchingModel, TreeNodeModel) | src/documents/models.py:101 | couvert-autrement | service Ged\Socle\Nomenclature | phpunit Ged\Tests\Socle\NomenclatureTest::testLaNomenclatureVitDansLeFondsPasDansLaBase (couche 1) |  |
| classe UiSettings(models.Model) | src/documents/models.py:575 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| classe Workflow(models.Model) | src/documents/models.py:1527 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| classe WorkflowAction(models.Model) | src/documents/models.py:1272 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDAffectationEstApplique (couche 1) |  |
| classe WorkflowActionEmail(models.Model) | src/documents/models.py:1185 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDAffectationEstApplique (couche 1) |  |
| classe WorkflowActionWebhook(models.Model) | src/documents/models.py:1222 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testChaqueChampDAffectationEstApplique (couche 1) |  |
| classe WorkflowRun(SoftDeleteModel) | src/documents/models.py:1552 | couvert-autrement | service Ged\Workflows\Executions | phpunit Ged\Tests\Workflows\ApplicateurTest::testLeWorkflowSAppliqueParLeDepotEtTraceUneExecution (couche 1) |  |
| classe WorkflowTrigger(models.Model) | src/documents/models.py:980 | couvert-autrement | service Ged\Workflows\DepotWorkflows | phpunit Ged\Tests\Workflows\ApplicateurTest::testLesEnumerationsDeWorkflowRunEtDUneDefinitionSontPortees (couche 1) |  |
| CustomField type BOOL | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type DATE | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type DOCUMENTLINK | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type FLOAT | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type INT | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type LONG_TEXT | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type MONETARY | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type SELECT | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type STRING | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| CustomField type URL | src/documents/models.py:843 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| AttachmentProcessing.ATTACHMENTS_ONLY | src/paperless_mail/models.py:115 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| AttachmentProcessing.EVERYTHING | src/paperless_mail/models.py:116 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ConsumptionScope.ATTACHMENTS_ONLY | src/paperless_mail/models.py:104 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ConsumptionScope.EML_ONLY | src/paperless_mail/models.py:105 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ConsumptionScope.EVERYTHING | src/paperless_mail/models.py:106 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| CorrespondentSource.FROM_CUSTOM | src/paperless_mail/models.py:141 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| CorrespondentSource.FROM_EMAIL | src/paperless_mail/models.py:139 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| CorrespondentSource.FROM_NAME | src/paperless_mail/models.py:140 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| CorrespondentSource.FROM_NOTHING | src/paperless_mail/models.py:138 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| DisplayFields.ADDED | src/documents/models.py:444 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.ASN | src/documents/models.py:452 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.CORRESPONDENT | src/documents/models.py:446 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.CREATED | src/documents/models.py:443 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.CUSTOM_FIELD | src/documents/models.py:454 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.DOCUMENT_TYPE | src/documents/models.py:447 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.NOTES | src/documents/models.py:449 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.OWNER | src/documents/models.py:450 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.PAGE_COUNT | src/documents/models.py:453 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.SHARED | src/documents/models.py:451 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.STORAGE_PATH | src/documents/models.py:448 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.TAGS | src/documents/models.py:445 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayFields.TITLE | src/documents/models.py:442 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayMode.LARGE_CARDS | src/documents/models.py:439 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayMode.SMALL_CARDS | src/documents/models.py:438 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DisplayMode.TABLE | src/documents/models.py:437 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| DocumentSourceChoices.API_UPLOAD | src/documents/models.py:998 | couvert-autrement | route POST /api/v1/depots | phpunit Ged\Tests\Jonctions\DepotApiTest::testTroisFichiersPoussesDeuxFoisDonnentTroisTravauxSansDoublon (couche 1) |  |
| DocumentSourceChoices.CONSUME_FOLDER | src/documents/models.py:997 | couvert-autrement | service Ged\Ingestion\Pilote | phpunit Ged\Tests\Ingestion\PiloteTest::testLotDecoupeEnPiecesPuisRange (couche 1) |  |
| DocumentSourceChoices.MAIL_FETCH | src/documents/models.py:999 | couvert-autrement | service Ged\Ingestion\Imap\ImapBoite | phpunit Ged\Tests\Ingestion\SourceAdresseTest::testClientImapContreServeurLocal (couche 1) |  |
| DocumentSourceChoices.WEB_UI | src/documents/models.py:1000 | couvert-autrement | route POST /deposer | phpunit Ged\Tests\Ui\ATraiterNomenclatureTest::testUnTypeAjouteAuFondsApparaitDansLesChoixDeLEcran (couche 1) |  |
| FieldDataType.BOOL | src/documents/models.py:789 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.DATE | src/documents/models.py:788 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.DOCUMENTLINK | src/documents/models.py:793 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| FieldDataType.FLOAT | src/documents/models.py:791 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.INT | src/documents/models.py:790 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.LONG_TEXT | src/documents/models.py:795 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.MONETARY | src/documents/models.py:792 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.SELECT | src/documents/models.py:794 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.STRING | src/documents/models.py:786 | couvert-autrement | service Ged\Socle\Champs | phpunit Ged\Tests\Socle\ChampsTest::testChaqueNatureValideEtRefuseCeQuIlFaut (couche 1) |  |
| FieldDataType.URL | src/documents/models.py:787 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| FileVersion.ARCHIVE | src/documents/models.py:722 | couvert-autrement | service Ged\Ingestion\CopieArchivage | phpunit Ged\Tests\Ingestion\CopieArchivageTest::testUnPdfTexteDonneUneCopiePdfA2bAvecXmpOutputIntentEtTexteConserve (couche 1) |  |
| FileVersion.ORIGINAL | src/documents/models.py:723 | couvert-autrement | service Ged\Socle\DepotFichiers | phpunit Ged\Tests\Socle\RangementTest::testNouvelleVersionConserveLaPrecedenteEtIndexeLeNouveauContenu (couche 1) |  |
| ImapSecurity.NONE | src/paperless_mail/models.py:14 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ImapSecurity.SSL | src/paperless_mail/models.py:15 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ImapSecurity.STARTTLS | src/paperless_mail/models.py:16 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAccountType.GMAIL_OAUTH | src/paperless_mail/models.py:20 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAccountType.IMAP | src/paperless_mail/models.py:19 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAccountType.OUTLOOK_OAUTH | src/paperless_mail/models.py:21 | couvert-autrement | service Ged\Ingestion\Courrier\Compte | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAction.DELETE | src/paperless_mail/models.py:126 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAction.FLAG | src/paperless_mail/models.py:129 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAction.MARK_READ | src/paperless_mail/models.py:128 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAction.MOVE | src/paperless_mail/models.py:127 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| MailAction.TAG | src/paperless_mail/models.py:130 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| PdfLayout.DEFAULT | src/paperless_mail/models.py:119 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| PdfLayout.HTML_ONLY | src/paperless_mail/models.py:122 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| PdfLayout.HTML_TEXT | src/paperless_mail/models.py:121 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| PdfLayout.TEXT_HTML | src/paperless_mail/models.py:120 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| PdfLayout.TEXT_ONLY | src/paperless_mail/models.py:123 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| ScheduleDateField.ADDED | src/documents/models.py:1003 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLaPlanificationSurLeChampDeDateEtLeDecalage (couche 1) |  |
| ScheduleDateField.CREATED | src/documents/models.py:1004 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLaPlanificationSurLeChampDeDateEtLeDecalage (couche 1) |  |
| ScheduleDateField.CUSTOM_FIELD | src/documents/models.py:1006 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLaPlanificationSurLeChampDeDateEtLeDecalage (couche 1) |  |
| ScheduleDateField.MODIFIED | src/documents/models.py:1005 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLaPlanificationSurLeChampDeDateEtLeDecalage (couche 1) |  |
| TaskName.CHECK_SANITY | src/documents/models.py:599 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskName.CONSUME_FILE | src/documents/models.py:597 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskName.INDEX_OPTIMIZE | src/documents/models.py:600 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskName.TRAIN_CLASSIFIER | src/documents/models.py:598 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskType.AUTO | src/documents/models.py:592 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskType.MANUAL_TASK | src/documents/models.py:594 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TaskType.SCHEDULED_TASK | src/documents/models.py:593 | absent (ecart assume, lot a creer) | - | - | V2-04 |
| TitleSource.FROM_FILENAME | src/paperless_mail/models.py:134 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| TitleSource.FROM_SUBJECT | src/paperless_mail/models.py:133 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| TitleSource.NONE | src/paperless_mail/models.py:135 | couvert-autrement | service Ged\Ingestion\Courrier\Regle | phpunit Ged\Tests\Ingestion\Courrier\RegleTest::testToutesLesEnumerationsDuModeleExistent (couche 1) |  |
| WorkflowActionType.ASSIGNMENT | src/documents/models.py:1274 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesEnumerationsDeActionSontCellesDuModele (couche 1) |  |
| WorkflowActionType.EMAIL | src/documents/models.py:1282 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesEnumerationsDeActionSontCellesDuModele (couche 1) |  |
| WorkflowActionType.REMOVAL | src/documents/models.py:1278 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesEnumerationsDeActionSontCellesDuModele (couche 1) |  |
| WorkflowActionType.WEBHOOK | src/documents/models.py:1286 | couvert-autrement | service Ged\Workflows\Action | phpunit Ged\Tests\Workflows\ActionTest::testLesEnumerationsDeActionSontCellesDuModele (couche 1) |  |
| WorkflowTriggerMatching.ALL | src/documents/models.py:985 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerMatching.ANY | src/documents/models.py:984 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerMatching.FUZZY | src/documents/models.py:988 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerMatching.LITERAL | src/documents/models.py:986 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerMatching.NONE | src/documents/models.py:983 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerMatching.REGEX | src/documents/models.py:987 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerType.CONSUMPTION | src/documents/models.py:991 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerType.DOCUMENT_ADDED | src/documents/models.py:992 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerType.DOCUMENT_UPDATED | src/documents/models.py:993 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |
| WorkflowTriggerType.SCHEDULED | src/documents/models.py:994 | couvert-autrement | service Ged\Workflows\Declencheur | phpunit Ged\Tests\Workflows\DeclencheurTest::testLesEnumerationsSontCellesDuModelePaperless (couche 1) |  |

## permissions

| Fonction Paperless | Reference source | Statut | Equivalent GEDv2 | Preuve / harness | Lot |
| --- | --- | --- | --- | --- | --- |
| AcknowledgeTasksPermissions | src/documents/permissions.py:207 | exclu (exclu : infrastructure-celery-redis) | - | - |  |
| PaperlessAdminPermissions | src/documents/permissions.py:45 | couvert-autrement | service Ged\Securite\DroitsDossiers | phpunit Ged\Tests\Securite\V213DroitsRolesTest::testMatriceCanoniqueSuitLaDecisionEtCouvreLesRoutes (couche 1) |  |
| PaperlessNotePermissions | src/documents/permissions.py:186 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testUneNoteEstEditeeParSonAuteurTraceeEtCherchableDansLeMemeIndex (couche 1) |  |
| PaperlessObjectPermissions | src/documents/permissions.py:19 | absent (ecart assume, lot a creer) | - | - | V2-07 |
| ViewDocumentsPermissions | src/documents/permissions.py:168 | couvert-autrement | service Ged\Securite\DroitsDossiers | phpunit Ged\Tests\Securite\DroitsBaseTest::testOracleDroitsEstRespecteParToutesLesSurfaces (couche 1) |  |
| get_document_count_filter_for_user | src/documents/permissions.py:132 | couvert-autrement | service Ged\Securite\DroitsDossiers | phpunit Ged\Tests\Recherche\SemblablesTest::testLesDroitsDeDossierSontAppliquesDansLaRequete (couche 1) |  |
| get_groups_with_only_permission | src/documents/permissions.py:50 | absent (ecart assume, lot a creer) | - | - | V2-07 |
| get_objects_for_user_owner_aware | src/documents/permissions.py:151 | absent (ecart assume, lot a creer) | - | - | V2-07 |
| has_perms_owner_aware | src/documents/permissions.py:163 | couvert-autrement | service Ged\Securite\DroitsDossiers | phpunit Ged\Tests\Securite\DroitsBaseTest::testHeritageEtRefusExpliciteSontPlusFortsQueLaPermissionDuRole (couche 1) |  |
| set_permissions_for_object | src/documents/permissions.py:64 | absent (ecart assume, lot a creer) | - | - | V2-07 |
| owner sur ModelWithOwner | src/documents/models.py:32 | couvert-autrement | service Ged\Securite\DroitsDossiers | phpunit Ged\Tests\Securite\DroitsBaseTest::testOracleDroitsEstRespecteParToutesLesSurfaces (couche 1) |  |
| owner sur ShareLink | src/documents/models.py:762 | couvert-autrement | service Ged\Socle\Collaboration | phpunit Ged\Tests\Socle\V212dTest::testLeLienExterneEstLongExpireRevocableEtJamaisEcritEnClair (couche 1) |  |

## Carnet de commandes (ecarts)

Chaque ecart (absent, non prouve, exclu) est listé ci-dessous avec sa reference Paperless.

| Id | Categorie | Reference Paperless | Statut | Raison | Lot |
| --- | --- | --- | --- | --- | --- |
| api:action:DocumentViewSet.email_document | api | src/documents/views.py:1187 | absent | ecart assume, lot a creer | V2-05 |
| api:action:DocumentViewSet.email_documents | api | src/documents/views.py:1199 | absent | ecart assume, lot a creer | V2-05 |
| api:action:DocumentViewSet.metadata | api | src/documents/views.py:830 | absent | ecart assume, lot a creer | V2-05 |
| api:action:StoragePathViewSet.test | api | src/documents/views.py:2347 | absent | ecart assume, lot a creer | V2-05 |
| api:action:TasksViewSet.acknowledge | api | src/documents/views.py:2566 | exclu | infrastructure-celery-redis |  |
| api:action:TasksViewSet.run | api | src/documents/views.py:2583 | exclu | infrastructure-celery-redis |  |
| api:action:UnifiedSearchViewSet.next_asn | api | src/documents/views.py:1359 | absent | ecart assume, lot a creer | V2-05 |
| api:action:UserViewSet.deactivate_totp | api | src/paperless/views.py:150 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:CorrespondentFilterSet.id | api | src/documents/filters.py:81 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:CustomFieldFilterSet.id | api | src/documents/filters.py:209 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:CustomFieldFilterSet.name | api | src/documents/filters.py:209 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.archive_serial_number | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.correspondent__id__none | api | src/documents/filters.py:716 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_field_query | api | src/documents/filters.py:749 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_fields | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_fields__icontains | api | src/documents/filters.py:728 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_fields__id__all | api | src/documents/filters.py:730 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_fields__id__in | api | src/documents/filters.py:737 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.custom_fields__id__none | api | src/documents/filters.py:732 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.document_type__id__none | api | src/documents/filters.py:718 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.has_custom_fields | api | src/documents/filters.py:742 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.original_filename | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.owner | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.owner__id | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.owner__id__none | api | src/documents/filters.py:726 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.storage_path__id | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.storage_path__id__none | api | src/documents/filters.py:720 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.storage_path__name | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:DocumentFilterSet.tags__id | api | src/documents/filters.py:763 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:PaperlessTaskFilterSet.acknowledged | api | src/documents/filters.py:800 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:PaperlessTaskFilterSet.status | api | src/documents/filters.py:807 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:PaperlessTaskFilterSet.task_name | api | src/documents/filters.py:807 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:PaperlessTaskFilterSet.type | api | src/documents/filters.py:807 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:ShareLinkFilterSet.created | api | src/documents/filters.py:793 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:ShareLinkFilterSet.expiration | api | src/documents/filters.py:793 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:StoragePathFilterSet.id | api | src/documents/filters.py:114 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:StoragePathFilterSet.name | api | src/documents/filters.py:114 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:StoragePathFilterSet.path | api | src/documents/filters.py:114 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:TagFilterSet.id | api | src/documents/filters.py:90 | absent | ecart assume, lot a creer | V2-05 |
| api:filtre:TagFilterSet.is_root | api | src/documents/filters.py:95 | absent | ecart assume, lot a creer | V2-05 |
| api:route:account_confirm_email | api | src/paperless/urls.py:309 | exclu | infrastructure-django |  |
| api:route:account_inactive | api | src/paperless/urls.py:281 | exclu | infrastructure-django |  |
| api:route:account_login | api | src/paperless/urls.py:278 | exclu | infrastructure-django |  |
| api:route:account_logout | api | src/paperless/urls.py:279 | exclu | infrastructure-django |  |
| api:route:account_reset_password | api | src/paperless/urls.py:291 | exclu | infrastructure-django |  |
| api:route:account_reset_password_done | api | src/paperless/urls.py:296 | exclu | infrastructure-django |  |
| api:route:account_reset_password_from_key | api | src/paperless/urls.py:314 | exclu | infrastructure-django |  |
| api:route:account_reset_password_from_key_done | api | src/paperless/urls.py:301 | exclu | infrastructure-django |  |
| api:route:account_signup | api | src/paperless/urls.py:280 | exclu | infrastructure-django |  |
| api:route:anon:accounts-3rdparty-include | api | src/paperless/urls.py:320 | exclu | infrastructure-django |  |
| api:route:anon:accounts-include | api | src/paperless/urls.py:272 | exclu | infrastructure-django |  |
| api:route:anon:accounts-password-include | api | src/paperless/urls.py:287 | exclu | infrastructure-django |  |
| api:route:anon:admin-admin-site-urls | api | src/paperless/urls.py:235 | exclu | infrastructure-django |  |
| api:route:anon:api-auth-include | api | src/paperless/urls.py:89 | exclu | infrastructure-django |  |
| api:route:anon:api-documents-include | api | src/paperless/urls.py:118 | exclu | infrastructure-django |  |
| api:route:anon:api-include | api | src/paperless/urls.py:85 | exclu | infrastructure-django |  |
| api:route:anon:api-profile-disconnect-social-account-disconnectsocialaccountview-as-view | api | src/paperless/urls.py:177 | absent | ecart assume, lot a creer | V2-05 |
| api:route:anon:api-profile-generate-auth-token-generateauthtokenview-as-view | api | src/paperless/urls.py:173 | absent | ecart assume, lot a creer | V2-05 |
| api:route:anon:api-profile-include | api | src/paperless/urls.py:164 | exclu | infrastructure-django |  |
| api:route:anon:api-profile-social-account-providers-socialaccountprovidersview-as-view | api | src/paperless/urls.py:181 | absent | ecart assume, lot a creer | V2-05 |
| api:route:anon:api-redirectview-as-view | api | src/paperless/urls.py:225 | exclu | infrastructure-django |  |
| api:route:anon:api-schema-include | api | src/paperless/urls.py:208 | exclu | infrastructure-django |  |
| api:route:anon:api-search-include | api | src/paperless/urls.py:96 | exclu | infrastructure-django |  |
| api:route:anon:api-token-paperlessobtainauthtokenview-as-view | api | src/paperless/urls.py:160 | exclu | infrastructure-django |  |
| api:route:anon:assets-path-path-redirectview-as-view | api | src/paperless/urls.py:262 | exclu | infrastructure-django |  |
| api:route:anon:fetch-doc-p-pk-d-redirectview-as-view | api | src/paperless/urls.py:240 | exclu | infrastructure-django |  |
| api:route:anon:fetch-include | api | src/paperless/urls.py:236 | exclu | infrastructure-django |  |
| api:route:anon:fetch-preview-p-pk-d-redirectview-as-view | api | src/paperless/urls.py:252 | exclu | infrastructure-django |  |
| api:route:anon:fetch-thumb-p-pk-d-redirectview-as-view | api | src/paperless/urls.py:246 | exclu | infrastructure-django |  |
| api:route:anon:ws-status-statusconsumer-as-asgi | api | src/paperless/urls.py:362 | exclu | infrastructure-celery-redis |  |
| api:route:app_logo | api | src/paperless/urls.py:270 | exclu | infrastructure-django |  |
| api:route:autocomplete | api | src/paperless/urls.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:route:base | api | src/paperless/urls.py:353 | exclu | infrastructure-django |  |
| api:route:bulk_download | api | src/paperless/urls.py:132 | absent | ecart assume, lot a creer | V2-05 |
| api:route:bulk_edit_objects | api | src/paperless/urls.py:145 | absent | ecart assume, lot a creer | V2-05 |
| api:route:config | api | src/paperless/urls.py:80 | absent | ecart assume, lot a creer | V2-05 |
| api:route:favicon | api | src/paperless/urls.py:234 | exclu | infrastructure-django |  |
| api:route:logs | api | src/paperless/urls.py:66 | absent | ecart assume, lot a creer | V2-05 |
| api:route:mfa_authenticate | api | src/paperless/urls.py:344 | exclu | infrastructure-django |  |
| api:route:oauth_callback | api | src/paperless/urls.py:203 | absent | ecart assume, lot a creer | V2-05 |
| api:route:remoteversion | api | src/paperless/urls.py:150 | exclu | infrastructure-django |  |
| api:route:schema | api | src/paperless/urls.py:212 | exclu | infrastructure-django |  |
| api:route:selection_data | api | src/paperless/urls.py:137 | absent | ecart assume, lot a creer | V2-05 |
| api:route:socialaccount_login_cancelled | api | src/paperless/urls.py:324 | absent | ecart assume, lot a creer | V2-05 |
| api:route:socialaccount_login_error | api | src/paperless/urls.py:329 | absent | ecart assume, lot a creer | V2-05 |
| api:route:socialaccount_signup | api | src/paperless/urls.py:334 | absent | ecart assume, lot a creer | V2-05 |
| api:route:statistics | api | src/paperless/urls.py:113 | absent | ecart assume, lot a creer | V2-05 |
| api:route:storage-paths | api | src/paperless/urls.py:69 | absent | ecart assume, lot a creer | V2-05 |
| api:route:swagger-ui | api | src/paperless/urls.py:217 | exclu | infrastructure-django |  |
| api:route:system_status | api | src/paperless/urls.py:193 | absent | ecart assume, lot a creer | V2-05 |
| api:route:tasks | api | src/paperless/urls.py:70 | exclu | infrastructure-celery-redis |  |
| api:route:totp_view | api | src/paperless/urls.py:185 | absent | ecart assume, lot a creer | V2-05 |
| api:route:ui_settings | api | src/paperless/urls.py:155 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.create | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.destroy | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.list | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.partial_update | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.retrieve | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ApplicationConfigurationViewSet.update | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:BulkDownloadView.post | api | src/documents/views.py:2261 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:BulkEditObjectsView.post | api | src/documents/views.py:2698 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:BulkEditView.post | api | src/documents/views.py:1513 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CorrespondentViewSet.create | api | src/documents/views.py:388 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CorrespondentViewSet.destroy | api | src/documents/views.py:388 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CorrespondentViewSet.partial_update | api | src/documents/views.py:388 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CorrespondentViewSet.retrieve | api | src/documents/views.py:388 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CorrespondentViewSet.update | api | src/documents/views.py:388 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.create | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.destroy | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.list | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.partial_update | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.retrieve | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:CustomFieldViewSet.update | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DisconnectSocialAccountView.post | api | src/paperless/views.py:386 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DocumentTypeViewSet.create | api | src/documents/views.py:462 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DocumentTypeViewSet.destroy | api | src/documents/views.py:462 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DocumentTypeViewSet.partial_update | api | src/documents/views.py:462 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DocumentTypeViewSet.retrieve | api | src/documents/views.py:462 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:DocumentTypeViewSet.update | api | src/documents/views.py:462 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:FaviconView.get | api | src/paperless/views.py:97 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GenerateAuthTokenView.post | api | src/paperless/views.py:328 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GlobalSearchView.get | api | src/documents/views.py:1952 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.create | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.destroy | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.list | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.partial_update | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.retrieve | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:GroupViewSet.update | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:OauthCallbackView.get | api | src/paperless_mail/views.py:184 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:PostDocumentView.post | api | src/documents/views.py:1666 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ProcessedMailViewSet.retrieve | api | src/paperless_mail/views.py:137 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ProfileView.get | api | src/paperless/views.py:189 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ProfileView.patch | api | src/paperless/views.py:195 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:RemoteVersionView.get | api | src/documents/views.py:2458 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SavedViewViewSet.create | api | src/documents/views.py:1452 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SavedViewViewSet.destroy | api | src/documents/views.py:1452 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SavedViewViewSet.partial_update | api | src/documents/views.py:1452 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SavedViewViewSet.retrieve | api | src/documents/views.py:1452 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SavedViewViewSet.update | api | src/documents/views.py:1452 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SearchAutoCompleteView.get | api | src/documents/views.py:1880 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SelectionDataView.post | api | src/documents/views.py:1785 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.create | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.destroy | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.list | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.partial_update | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.retrieve | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:ShareLinkViewSet.update | api | src/documents/views.py:2602 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SharedLinkView.get | api | src/documents/views.py:2623 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SocialAccountProvidersView.get | api | src/paperless/views.py:412 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StatisticsView.get | api | src/documents/views.py:2154 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.create | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.destroy | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.list | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.partial_update | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.retrieve | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:StoragePathViewSet.update | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:SystemStatusView.get | api | src/documents/views.py:2941 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TOTPView.delete | api | src/paperless/views.py:294 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TOTPView.get | api | src/paperless/views.py:247 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TOTPView.post | api | src/paperless/views.py:264 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TagViewSet.create | api | src/documents/views.py:425 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TagViewSet.destroy | api | src/documents/views.py:425 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TagViewSet.partial_update | api | src/documents/views.py:425 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TagViewSet.retrieve | api | src/documents/views.py:425 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TagViewSet.update | api | src/documents/views.py:425 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TasksViewSet.list | api | src/documents/views.py:2532 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TasksViewSet.retrieve | api | src/documents/views.py:2532 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TrashView.get | api | src/documents/views.py:3123 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:TrashView.post | api | src/documents/views.py:3127 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UiSettingsView.get | api | src/documents/views.py:2366 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UiSettingsView.post | api | src/documents/views.py:2434 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.create | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.destroy | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.list | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.partial_update | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.retrieve | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:UserViewSet.update | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.create | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.destroy | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.list | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.partial_update | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.retrieve | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowActionViewSet.update | api | src/documents/views.py:2780 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.create | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.destroy | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.list | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.partial_update | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.retrieve | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowTriggerViewSet.update | api | src/documents/views.py:2762 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.create | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.destroy | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.list | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.partial_update | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.retrieve | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:verbe:WorkflowViewSet.update | api | src/documents/views.py:2805 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:ApplicationConfigurationViewSet | api | src/paperless/views.py:349 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:BulkDownloadView | api | src/documents/views.py:2256 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:BulkEditObjectsView | api | src/documents/views.py:2693 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:CustomFieldViewSet | api | src/documents/views.py:2823 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:DisconnectSocialAccountView | api | src/paperless/views.py:379 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:FaviconView | api | src/paperless/views.py:96 | exclu | infrastructure-django |  |
| api:vue:GenerateAuthTokenView | api | src/paperless/views.py:320 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:GroupViewSet | api | src/paperless/views.py:168 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:IndexView | api | src/documents/views.py:209 | exclu | infrastructure-django |  |
| api:vue:LogViewSet | api | src/documents/views.py:1402 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:OauthCallbackView | api | src/paperless_mail/views.py:181 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:PaperlessObtainAuthTokenView | api | src/paperless/views.py:48 | exclu | infrastructure-django |  |
| api:vue:PassUserMixin | api | src/documents/views.py:251 | exclu | infrastructure-django |  |
| api:vue:RemoteVersionView | api | src/documents/views.py:2455 | exclu | infrastructure-django |  |
| api:vue:SearchAutoCompleteView | api | src/documents/views.py:1877 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:SelectionDataView | api | src/documents/views.py:1780 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:SharedLinkView | api | src/documents/views.py:2619 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:SocialAccountProvidersView | api | src/paperless/views.py:405 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:StatisticsView | api | src/documents/views.py:2151 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:StoragePathViewSet | api | src/documents/views.py:2306 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:SystemStatusView | api | src/documents/views.py:2938 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:TOTPView | api | src/paperless/views.py:240 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:TasksViewSet | api | src/documents/views.py:2532 | exclu | infrastructure-celery-redis |  |
| api:vue:TrashView | api | src/documents/views.py:3113 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:UiSettingsView | api | src/documents/views.py:2361 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:UnifiedSearchViewSet | api | src/documents/views.py:1289 | absent | ecart assume, lot a creer | V2-05 |
| api:vue:UserViewSet | api | src/paperless/views.py:105 | absent | ecart assume, lot a creer | V2-05 |
| chaine:parseur:TextDocumentParser.get_thumbnail | chaine | src/paperless_text/parsers.py:18 | absent | Ecart assume : GEDv2 ne fabrique pas de vignette pour un fichier texte — l’aperçu est le texte lui-même (Apercu\Piece, Apercu\Source). | V2-apercu-texte |
| chaine:parseur:TikaDocumentParser.extract_metadata | chaine | src/paperless_tika/parsers.py:34 | absent | Ecart assume : la bureautique (odt/rtf/doc/docx) passe par LibreOffice → PDF (Apercu\Outils::versPdf), non prouvée par un test et hors périmètre de ce lot. | V2-bureautique |
| chaine:parseur:TikaDocumentParser.get_thumbnail | chaine | src/paperless_tika/parsers.py:24 | absent | Ecart assume : vignette bureautique non prouvée (LibreOffice → PDF → PNG via Apercu), hors périmètre de ce lot. | V2-bureautique |
| chaine:parseur:TikaDocumentParser.parse | chaine | src/paperless_tika/parsers.py:56 | absent | Ecart assume : parse bureautique délégué à LibreOffice/Gotenberg, non prouvé par un test, hors périmètre de ce lot. | V2-bureautique |
| chaine:parseur:documents.get_default_thumbnail | chaine | src/documents/parsers.py:184 | absent | Ecart assume : GEDv2 ne sert pas de vignette générique ; il NOMME l’absence (Apercu\Outils::MSG_FORMAT, MSG_VIGNETTE), jamais une image de remplacement. | V2-apercu-defaut |
| commande:documents:convert_mariadb_uuid | commande | src/documents/management/commands/convert_mariadb_uuid.py:1 | exclu | infrastructure-django |  |
| commande:documents:decrypt_documents | commande | src/documents/management/commands/decrypt_documents.py:1 | absent | ecart assume, lot a creer | V2-00 |
| commande:documents:document_exporter | commande | src/documents/management/commands/document_exporter.py:1 | absent | ecart assume, lot a creer | V2-00 |
| commande:documents:document_fuzzy_match | commande | src/documents/management/commands/document_fuzzy_match.py:1 | absent | ecart assume, lot a creer | V2-00 |
| commande:documents:document_importer | commande | src/documents/management/commands/document_importer.py:1 | absent | ecart assume, lot a creer | V2-00 |
| commande:documents:document_retagger | commande | src/documents/management/commands/document_retagger.py:1 | absent | ecart assume, lot a creer | V2-00 |
| commande:documents:loaddata_stdin | commande | src/documents/management/commands/loaddata_stdin.py:1 | exclu | infrastructure-django |  |
| commande:documents:prune_audit_logs | commande | src/documents/management/commands/prune_audit_logs.py:1 | absent | ecart assume, lot a creer | V2-00 |
| correspondance:fonction:match_correspondents | correspondance | src/documents/matching.py:47 | absent | ecart assume, lot a creer | V2-03 |
| correspondance:fonction:match_document_types | correspondance | src/documents/matching.py:75 | absent | ecart assume, lot a creer | V2-03 |
| correspondance:fonction:match_storage_paths | correspondance | src/documents/matching.py:127 | absent | ecart assume, lot a creer | V2-03 |
| correspondance:fonction:match_tags | correspondance | src/documents/matching.py:102 | absent | ecart assume, lot a creer | V2-03 |
| ecran:reglage:general | ecran | src-ui/src/app/components/admin/settings/settings.component.ts:72 | absent | ecart assume, lot a creer | V2-03 |
| ecran:reglage:notifications | ecran | src-ui/src/app/components/admin/settings/settings.component.ts:74 | absent | ecart assume, lot a creer | V2-03 |
| ecran:reglage:permissions | ecran | src-ui/src/app/components/admin/settings/settings.component.ts:73 | absent | ecart assume, lot a creer | V2-03 |
| ecran:reglage:savedviews | ecran | src-ui/src/app/components/admin/settings/settings.component.ts:75 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:asn-id | ecran | src-ui/src/app/app-routing.module.ts:97 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:config | ecran | src-ui/src/app/app-routing.module.ts:217 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:dashboard | ecran | src-ui/src/app/app-routing.module.ts:40 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:logs | ecran | src-ui/src/app/app-routing.module.ts:157 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:savedviews | ecran | src-ui/src/app/app-routing.module.ts:289 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:settings | ecran | src-ui/src/app/app-routing.module.ts:191 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:settings-savedviews | ecran | src-ui/src/app/app-routing.module.ts:187 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:settings-section | ecran | src-ui/src/app/app-routing.module.ts:204 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:settings-usersgroups | ecran | src-ui/src/app/app-routing.module.ts:183 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:storagepaths | ecran | src-ui/src/app/app-routing.module.ts:145 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:tasks | ecran | src-ui/src/app/app-routing.module.ts:229 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:trash | ecran | src-ui/src/app/app-routing.module.ts:166 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:usersgroups | ecran | src-ui/src/app/app-routing.module.ts:277 | absent | ecart assume, lot a creer | V2-03 |
| ecran:route:vide | ecran | src-ui/src/app/app-routing.module.ts:33 | exclu | infrastructure-django |  |
| masse:fonction:reflect_doclinks | masse | src/documents/bulk_edit.py:647 | absent | ecart assume, lot a creer | V2-03 |
| masse:fonction:reprocess | masse | src/documents/bulk_edit.py:297 | absent | ecart assume, lot a creer | V2-03 |
| masse:fonction:set_permissions | masse | src/documents/bulk_edit.py:306 | absent | ecart assume, lot a creer | V2-03 |
| modele:champ:CustomFieldInstance.created | modele | src/documents/models.py:856 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:CustomFieldInstance.value_document_ids | modele | src/documents/models.py:917 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:CustomFieldInstance.value_monetary_amount | modele | src/documents/models.py:896 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:CustomFieldInstance.value_url | modele | src/documents/models.py:886 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.added | modele | src/documents/models.py:261 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.archive_checksum | modele | src/documents/models.py:219 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.archive_filename | modele | src/documents/models.py:278 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.archive_serial_number | modele | src/documents/models.py:301 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.checksum | modele | src/documents/models.py:211 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.filename | modele | src/documents/models.py:268 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.mime_type | modele | src/documents/models.py:202 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.modified | modele | src/documents/models.py:246 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.page_count | modele | src/documents/models.py:228 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Document.storage_type | modele | src/documents/models.py:253 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:MatchingModel.is_insensitive | modele | src/documents/models.py:74 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:MatchingModel.match | modele | src/documents/models.py:66 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:MatchingModel.matching_algorithm | modele | src/documents/models.py:68 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:MatchingModel.name | modele | src/documents/models.py:64 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:ModelWithOwner.owner | modele | src/documents/models.py:32 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.acknowledged | modele | src/documents/models.py:609 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.date_created | modele | src/documents/models.py:638 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.date_done | modele | src/documents/models.py:652 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.date_started | modele | src/documents/models.py:645 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.result | modele | src/documents/models.py:659 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.status | modele | src/documents/models.py:630 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.task_file_name | modele | src/documents/models.py:615 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.task_id | modele | src/documents/models.py:602 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.task_name | modele | src/documents/models.py:622 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:PaperlessTask.type | modele | src/documents/models.py:668 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.display_fields | modele | src/documents/models.py:488 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.display_mode | modele | src/documents/models.py:480 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.page_size | modele | src/documents/models.py:473 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.show_in_sidebar | modele | src/documents/models.py:461 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.show_on_dashboard | modele | src/documents/models.py:458 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.sort_field | modele | src/documents/models.py:465 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:SavedView.sort_reverse | modele | src/documents/models.py:471 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:ShareLink.file_version | modele | src/documents/models.py:756 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Tag.color | modele | src/documents/models.py:102 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:Tag.is_inbox_tag | modele | src/documents/models.py:106 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:UiSettings.settings | modele | src/documents/models.py:581 | absent | ecart assume, lot a creer | V2-04 |
| modele:champ:UiSettings.user | modele | src/documents/models.py:576 | absent | ecart assume, lot a creer | V2-04 |
| modele:classe:MatchingModel | modele | src/documents/models.py:45 | absent | ecart assume, lot a creer | V2-04 |
| modele:classe:ModelWithOwner | modele | src/documents/models.py:31 | absent | ecart assume, lot a creer | V2-04 |
| modele:classe:PaperlessTask | modele | src/documents/models.py:587 | exclu | infrastructure-celery-redis |  |
| modele:classe:UiSettings | modele | src/documents/models.py:575 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:BOOL | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:DATE | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:DOCUMENTLINK | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:FLOAT | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:INT | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:LONG_TEXT | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:MONETARY | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:SELECT | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:STRING | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:customfield-type:URL | modele | src/documents/models.py:843 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.ADDED | modele | src/documents/models.py:444 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.ASN | modele | src/documents/models.py:452 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.CORRESPONDENT | modele | src/documents/models.py:446 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.CREATED | modele | src/documents/models.py:443 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.CUSTOM_FIELD | modele | src/documents/models.py:454 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.DOCUMENT_TYPE | modele | src/documents/models.py:447 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.NOTES | modele | src/documents/models.py:449 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.OWNER | modele | src/documents/models.py:450 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.PAGE_COUNT | modele | src/documents/models.py:453 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.SHARED | modele | src/documents/models.py:451 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.STORAGE_PATH | modele | src/documents/models.py:448 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.TAGS | modele | src/documents/models.py:445 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayFields.TITLE | modele | src/documents/models.py:442 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayMode.LARGE_CARDS | modele | src/documents/models.py:439 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayMode.SMALL_CARDS | modele | src/documents/models.py:438 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:DisplayMode.TABLE | modele | src/documents/models.py:437 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:FieldDataType.DOCUMENTLINK | modele | src/documents/models.py:793 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:FieldDataType.URL | modele | src/documents/models.py:787 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskName.CHECK_SANITY | modele | src/documents/models.py:599 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskName.CONSUME_FILE | modele | src/documents/models.py:597 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskName.INDEX_OPTIMIZE | modele | src/documents/models.py:600 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskName.TRAIN_CLASSIFIER | modele | src/documents/models.py:598 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskType.AUTO | modele | src/documents/models.py:592 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskType.MANUAL_TASK | modele | src/documents/models.py:594 | absent | ecart assume, lot a creer | V2-04 |
| modele:type:TaskType.SCHEDULED_TASK | modele | src/documents/models.py:593 | absent | ecart assume, lot a creer | V2-04 |
| permissions:classe:AcknowledgeTasksPermissions | permissions | src/documents/permissions.py:207 | exclu | infrastructure-celery-redis |  |
| permissions:classe:PaperlessObjectPermissions | permissions | src/documents/permissions.py:19 | absent | ecart assume, lot a creer | V2-07 |
| permissions:fonction:get_groups_with_only_permission | permissions | src/documents/permissions.py:50 | absent | ecart assume, lot a creer | V2-07 |
| permissions:fonction:get_objects_for_user_owner_aware | permissions | src/documents/permissions.py:151 | absent | ecart assume, lot a creer | V2-07 |
| permissions:fonction:set_permissions_for_object | permissions | src/documents/permissions.py:64 | absent | ecart assume, lot a creer | V2-07 |

