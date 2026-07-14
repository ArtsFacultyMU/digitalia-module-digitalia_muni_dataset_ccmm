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
      'ccmm-xml' => [
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

    /*
    $render_array['elements']['agent_is_person'] = [];
    $render_array['elements']['role_uri'] = [];
    $render_array['elements']['person_first_names'] = [];
    $render_array['elements']['person_last_names'] = [];
    $render_array['elements']['person_orcid'] = [];
    $render_array['elements']['org_name'] = [];
    $render_array['elements']['org_ror'] = [];
    $render_array['elements']['affiliation'] = [];
    */

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
            // loads node and retrieves complex values
            case 'nid':
              $render_array['elements']['qualified_relations'] = $this->build_agents($value, $render_array);
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
    // $render_array['elements']['title'][] = $entity->label();

    return parent::build($render_array);
  }

  protected function buildTimeInterval($parser, $label, $value, $render_array) {
    try {
      $parsingResult = $parser->parse($value);
    } catch (\EDTF\Exception\EdtfException $e) {
      \Drupal::logger('dataset_ccmm')->warning(
        $this->t("EDTF parsing error for field $label with value '$value': @message", ['@message' => $e->getMessage()])
      );
      return $render_array;
    }
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

  protected function build_agents($value) {

    $node = Node::load($value);

    if (empty($node)) {
      return $render_array;
    }

    $agents = [];

    foreach (Array('field_creator', 'field_contributor', 'field_publisher') as $field) {
      if (!$node->hasField($field)) {
        continue;
      }
    
      $items = $node->get($field);

      foreach ($items as $item) {
        $agents[] = [
          'role'            => $item->role,
          'role_uri'        => "https://vocabs.ccmm.cz/registry/codelist/{$item->role}/",
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
}