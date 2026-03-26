<?php

namespace Drupal\digitalia_muni_dataset_ccmm\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;
use Drupal\views\Views;
// use EDTF\Parser\Parser;
use EDTF\EdtfFactory;

/**
 * Mods using a View.
 *
 * @OaiMetadataMap(
 *  id = "ccmm",
 *  label = @Translation("CCMM"),
 *  metadata_format = "ccmm-xml",
 *  template = {
 *    "type" = "module",
 *    "name" = "digitalia_muni_dataset_ccmm",
 *    "directory" = "templates",
 *    "file" = "ccmm"
 *  }
 * )
 */
class CCMM extends OaiMetadataMapBase {

  /**
   * Provides information on the metadata format.
   *
   * @return string[]
   *   The metadata format specification.
   */
  public function getMetadataFormat() {
    return [
      'metadataPrefix' => 'ccmm-xml',
      'schema' => 'https://model.ccmm.cz/research-data/dataset/schema.xsd',
      'metadataNamespace' => 'https://schema.ccmm.cz/research-data/1.1',
    ];
  }

  /**
   * Provides information contained in the metadata wrapper.
   *
   * @return string[]
   *   The information needed in the metadata wrapper.
   */
  public function getMetadataWrapper() {
    return [
      'ccmm' => [
        '@xsi:schemaLocation' => 'https://schema.ccmm.cz/research-data/1.1 https://model.ccmm.cz/research-data/dataset/schema.xsd',
        '@xmlns' => 'https://schema.ccmm.cz/research-data/1.1',
        '@xmlns:gml' => 'http://www.opengis.net/gml/3.2',
        '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      ],
    ];
  }

  /**
   * Method to transform the provided entity into the desired metadata record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to transform.
   *
   * @return string
   *   rendered XML.
   */
  public function transformRecord(ContentEntityInterface $entity) {
    //$config = \Drupal::config('rest_oai_pmh.settings');
    //$view_info = $config->get('mods_view');
    $view_machine_name = 'oai_pmh_dataset_item';
    $view_display_name = 'ccmm_info';

    $view = Views::getView($view_machine_name);
    if (!isset($view)) {
      \Drupal::logger('dataset_ccmm')->warning(
            $this->t("OAI-PMH Dataset Item Data ($view_machine_name) view does not exist.")
        );
      return '';
    }
    if (!$view->access($view_display_name)) {
      \Drupal::logger('dataset_ccmm')->warning(
            $this->t("View display $view_display_name not valid or not set.")
        );
      return '';
    }

    $view->setDisplay($view_display_name);
    $argument = [$entity->id()];
    $view->setArguments($argument);
    $view->preExecute();
    $view->execute();
    $view_result = $view->result;
    $view->render();

    $parser = \EDTF\EdtfFactory::newParser();

    $render_array['elements']['agent_is_person'][] = [];
    $render_array['elements']['role_uri'] = [];
    $render_array['elements']['person_first_names'] = [];
    $render_array['elements']['person_last_names'] = [];
    $render_array['elements']['person_orcid'] = [];
    $render_array['elements']['org_name'] = [];
    $render_array['elements']['org_ror'] = [];
    $render_array['elements']['affiliation'] = [];

    foreach ($view_result as $row) {
      foreach ($view->field as $field) {
        $label = $field->label();
        $value = $field->advancedRender($row);

        if (!is_string($value)) {
          $value = $value->__toString();
        }

        if (!empty($value)) {

          switch ($label) {
            case 'date_created':
            case 'date_issued':
            case 'date_available':
            case 'date_coverage':
            case 'date_collected':
            case 'date_accepted':
              $render_array = $this->buildTimeInterval($parser, $label, $value, $render_array);
              break;
            case 'related_agent_people':
            case 'related_agent_organisations':
              $render_array = $this->buildQualifiedRelationship($label, $value, $render_array);
              break;
            // TO DO funding reference
            // TO DO related resources
            // TO DO download link to file distribution
            // resource type
            // dataset language
          }

          $render_array['elements'][$label] = $value;
        }
      }
    }

    if (empty($render_array)) {
      return '';
    }

    // $render_array['metadata_prefix'] = 'ccmm-xml';
    // $render_array['elements']['title'][] = $entity->label();

    return parent::build($render_array);
  }

  protected function buildTimeInterval($parser, $label, $value, $render_array) {
    $parsingResult = $parser->parse($value);
    $edtf = $parsingResult->getEdtfValue();

    if ($edtf instanceof \EDTF\Model\Interval) {
      $earliest = $edtf->hasStartDate() ? $edtf->getStartDate()->getMin() : 0;
      $latest = $edtf->hasEndDate() ? $edtf->getEndDate()->getMax() : 9999;
    } else {
      $earliest = $edtf->getMin();
      $latest = $earliest;
    }

    $render_array['elements'][$label . '_earliest'] = date('Y-m-d', $earliest);
    $render_array['elements'][$label . '_latest'] = date('Y-m-d', $latest);

    return $render_array;
  }


  protected function buildQualifiedRelationship($label, $value, $qualified_relations) {
    $entity_ids = array_map('intval', explode(', ', $value));
    $storage = \Drupal::entityTypeManager()->getStorage('digitalia_muni_entity');
    $entities = $storage->loadMultiple($entity_ids);

    foreach ($entity_ids as $entity_id) {
      if (empty($entities[$entity_id])) {
        continue;
      }

      $ror_iri = '';
      $agent_is_person = '';
      $first_names = '';
      $last_names = '';
      $orcid = '';
      $affiliation = '';
      $org_name = '';
      $ror_id = '';

      $entity = $entities[$entity_id];

      if ($label == 'related_agent_people') {
        $related_person = $entity->get('field_related_person')->referencedEntities();
        if (empty($related_person)) {
          continue;
        }

        $agent_is_person = 'TRUE';
        $first_names = $related_person[0]->get('field_first_names')->value ?? '';
        $last_names  = $related_person[0]->get('field_last_names')->value ?? '';
        $orcid       = $related_person[0]->get('field_orcid')->value ?? '';
        $orcid = preg_replace('#^\s*https?://orcid\.org/#i', '', $orcid);
        $affiliation = $related_person[0]->get('field_corporate_body_name')->value ?? '';
      } else {
        $related_organisation = $entity->get('field_related_organisation')->referencedEntities();
        if (empty($related_organisation)) {
          continue;
        }
        $agent_is_person = 'FALSE';
        $org_name = $related_organisation[0]->get('field_corporate_body_name')->value ?? '';
        $ror_id = $related_organisation[0]->get('field_ror')->value ?? '';
        $ror_id = preg_replace('#^\s*https?://ror\.org/#i', '', $ror_id);
      }

      $agent_role_terms = $entity->get('field_agent_role')->referencedEntities();
      if (empty($agent_role_terms)) {
        continue;
      }

      foreach ($agent_role_terms as $agent_role_term) {
        if (empty($agent_role_term)) {
          continue;
        }

        $link_field = $agent_role_term->get('field_authority_link');
        if ($link_field->isEmpty()) {
          continue;
        }

        foreach ($link_field as $link_item) {
          if ($link_item->getValue()['source'] != 'ccmm') {
            continue;
          }
          // to do contact
          $qualified_relations['elements']['agent_is_person'][] = $agent_is_person;
          $qualified_relations['elements']['role_uri'][] = $link_item->getValue()['uri'];
          $qualified_relations['elements']['person_first_names'][] = $first_names;
          $qualified_relations['elements']['person_last_names'][] = $last_names;
          $qualified_relations['elements']['person_orcid'][] = $orcid;
          $qualified_relations['elements']['affiliation'][] = $affiliation;
          $qualified_relations['elements']['org_name'][] = $org_name;
          $qualified_relations['elements']['org_ror'][] = $ror_id;
        }
      }
    }

    return $qualified_relations;
  }
}