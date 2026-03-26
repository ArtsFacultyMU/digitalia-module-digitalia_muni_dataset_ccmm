<?php

/**
 * Implements hook_tokens().
 */
function digitalia-module-dataset_ccmm_tokens($type, $tokens, array $data, array $options, \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata) {
  $replacements = [];

  if ($type === 'node' && !empty($data['node'])) {
    $node = $data['node'];

    foreach ($tokens as $name => $original) {
      if ($name === 'creator-full-name') {

        if ($node->hasField('field_creator') && !$node->get('field_creator')->isEmpty()) {

          $creator = $node->get('field_creator')->entity;

          if ($creator) {
            $first = $creator->get('field_first_name')->value ?? '';
            $last = $creator->get('field_last_name')->value ?? '';

            $replacements[$original] = trim($first . ' ' . $last);
          }
        }
      }
    }
  }

  return $replacements;
}