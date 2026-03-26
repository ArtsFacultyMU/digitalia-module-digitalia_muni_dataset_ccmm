# CCMM OAI-PMH endpoint

This module is created for mapping the Dataset content type used on ArchaeoVault to CCMM in order for the records to be harvested to NMA. The module is also used for harvesting of Digitalia MUNI ARTS datasets and collections to NMA on Metasys.

The serialization extends this module for oai-pmh:
https://www.drupal.org/project/rest_oai_pmh

## 0. create view

/admin/structure/views/view/ccmm_oai_pmh/edit/all_datasets

## 1. provide dublin core mapping
composer require 'drupal/metatag:^2.2'

/admin/config/search/metatag

problem with tokens for creator and contributor (and other publishers)
separators not working correctly

## 2. create view with data needed for CCMM mapping

/admin/structure/views/view/oai_pmh_dataset_item/edit/ccmm_info

## 3. Create Plugin with custom ccmm mapping

Resources:
- Example: https://github.com/ArtsFacultyMU/digitalia/tree/master/examples/OAI
- Simple tutorial: https://github.com/joecorall/rest_oai_pmh_jpcoar
- https://islandora.github.io/documentation/user-documentation/metadata-harvesting/#creating-additional-metadata-formats
- https://git.drupalcode.org/project/rest_oai_pmh/-/blob/2.0.x/src/Plugin/OaiMetadataMap/DefaultMap.php
- https://docs.nrp.eosc.cz/en/docs/repo_admins/operating-repositories-in-the-nrp/exports-to-nma
- https://github.com/techlib/CCMM-V

## embed custom entities if used in content type

Create custom search index processor and create XML there for speed.

## create twig template

The ccmm one is: templates/ccmm.html.twig

# Dev copy tu pod

```
tar czf - digitalia_muni_dataset_ccmm | kubectl exec -i drupal-<pod_id> -- tar xzf - -C /var/www/drupal/web/modules/custom/
```

# check OAI-PMH

/oai/request?verb=ListRecords&metadataPrefix=ccmm-xml