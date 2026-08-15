<?php
$storage = \Drupal::entityTypeManager()->getStorage('criterion_response_ent_type');
$bundles = $storage->loadMultiple();
foreach ($bundles as $id => $bundle) {
  print $id . ' => ' . $bundle->label() . PHP_EOL;
}