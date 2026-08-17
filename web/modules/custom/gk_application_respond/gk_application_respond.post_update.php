<?php

/**
 * @file
 * Post-update hooks for the GK Application Respond module.
 */

/**
 * Backfills field_res_criterion_active = 0 for existing criterion_response
 * entities whose field_res_criterion_appl is "Not applicable" (x).
 *
 * This is a one-off data fix for responses created before
 * gk_application_respond_entity_presave() started enforcing this
 * consistency rule on every save. Run via `drush updb`.
 */
function gk_application_respond_post_update_enforce_active_for_not_applicable(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('criterion_response_ent');

  if (!isset($sandbox['progress'])) {
    $ids = array_values(
      $storage->getQuery()
        ->condition('field_res_criterion_appl', 'x')
        ->condition('field_res_criterion_active', 1)
        ->accessCheck(FALSE)
        ->execute()
    );

    $sandbox['ids']      = $ids;
    $sandbox['progress'] = 0;
    $sandbox['max']      = count($ids) ?: 1;
    $sandbox['fixed']    = 0;
  }

  $slice = array_slice($sandbox['ids'], $sandbox['progress'], 50);

  foreach ($slice as $id) {
    $entity = $storage->load($id);
    if ($entity) {
      $entity->set('field_res_criterion_active', 0);
      $entity->save();
      $sandbox['fixed']++;
    }
    $sandbox['progress']++;
  }

  $sandbox['#finished'] = empty($sandbox['ids']) ? 1 : ($sandbox['progress'] / $sandbox['max']);

  return (string) t('Fixed @fixed criterion responses (Not applicable -> inactive) out of @total checked.', [
    '@fixed' => $sandbox['fixed'],
    '@total' => $sandbox['max'],
  ]);
}
