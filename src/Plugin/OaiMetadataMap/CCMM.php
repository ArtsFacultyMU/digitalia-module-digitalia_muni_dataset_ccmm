<?php

namespace Drupal\digitalia_muni_dataset_ccmm\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;
use Drupal\views\Views;
use EDTF\EdtfFactory;
use Drupal\node\Entity\Node;

/**
 * CCMM using a View.
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
      'dataset' => [
        '@xmlns' => 'https://schema.ccmm.cz/research-data/1.1',
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

    foreach ($view_result as $row) {
      $render_array['elements']['agent_is_person'] = [];
      $render_array['elements']['role_uri'] = [];
      $render_array['elements']['person_first_names'] = [];
      $render_array['elements']['person_last_names'] = [];
      $render_array['elements']['person_orcid'] = [];
      $render_array['elements']['org_name'] = [];
      $render_array['elements']['org_ror'] = [];
      $render_array['elements']['affiliation'] = [];
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
            case 'nid':
              $render_array = $this->build_node($value, $render_array);
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

    $render_array['metadata_prefix'] = 'ccmm-xml';
    return parent::build($render_array);
  }

/*   protected function buildDataService($label, $value, $render_array) {
    preg_match_all('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $value, $matches);
    $urls = array_map('trim', $matches[1] ?? []);
    $titles = array_map(
      fn($title) => trim(strip_tags($title)),
      $matches[2] ?? []
    );
    $render_array['elements']['data_service_titles'][] = $titles;
    $render_array['elements']['data_service_urls'][] = $urls;
    return $render_array;
  } */

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

  protected function build_agents($value, $node) {
    $agents = [];

    foreach (Array('field_creator', 'field_contributor', 'field_publisher_dm') as $field) {
      if (!$node->hasField($field)) {
        continue;
      }

      switch ($field) {
        case 'field_creator':
          $role = 'Creator';
          break;
        case 'field_publisher_dm':
          $role = 'Publisher';
          break;
        case 'field_contributor':
          $role = "Contributor";
          break;
      }
    
      $items = $node->get($field);
      $base_iri = "https://vocabs.ccmm.cz/registry/codelist/AgentRole/{$role}";

      foreach ($items as $item) {
        $agents[] = [
          'role'            => $item->role ? preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $item->role) : $role,
          'role_uri'        => $item->role ? "{$base_iri}/{$item->role}" : $base_iri,
          'agent_is_person' => $item->agent_type === 'person',
          'name'            => $item->name,
          'first_names'     => $item->first_names,
          'last_names'      => $item->last_names,
          'orcid'           => $item->orcid,
          'ror'             => $item->ror,
          'affiliation'     => $item->institution_affiliation,
          'contact'         => $item->contact,
        ];
      }
    }

    return $agents;

  }

  protected function buildFundingReference($value, $node) {
    $funding_references = [];
    if (!$node->hasField('field_funding_reference')) {
      return $funding_references;
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    $digitalia_storage = $entity_type_manager->getStorage('digitalia_muni_entity');

    foreach ($node->get('field_funding_reference') as $funding_item) {
      $funding_id = $funding_item->target_id ?? NULL;
      if (empty($funding_id)) {
        continue;
      }

      $funding_entity = $digitalia_storage->load($funding_id);
      if (!$funding_entity) {
        continue;
      }

      $funders = [];
      foreach ($funding_entity->get('field_funder') as $funder_item) {
        $funder_id = $funder_item->target_id ?? NULL;
        if (empty($funder_id)) {
          continue;
        }

        $funder_entity = $digitalia_storage->load($funder_id);
        if (!$funder_entity) {
          continue;
        }

        $ror = $funder_entity->get('field_ror')->value ?? '';
        if (is_string($ror) && str_starts_with($ror, 'https://ror.org/')) {
          $ror = substr($ror, strlen('https://ror.org/'));
        }

        $funders[] = [
          'name' => $funder_entity->get('field_corporate_body_name')->value ?? '',
          'ror' => $ror,
        ];
      }

      $funding_references[] = [
        'award_title' => $funding_entity->get('field_award_title')->value ?? '',
        'award_id' => $funding_entity->get('field_local_identifier')->value ?? '',
        'funders' => $funders,
      ];
    }

    return $funding_references;
  }

  /* build node only once */
  protected function build_node($value, $render_array) {
    $node = Node::load($value);

    if (empty($node)) {
      return $render_array;
    }

    $render_array['elements']['qualified_relations'] = $this->build_agents($value, $node);
    $render_array['elements']['funding_references'] = $this->buildFundingReference($value, $node);

    return $render_array;
  }

}