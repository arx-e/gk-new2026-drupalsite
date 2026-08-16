<?php

namespace Drupal\gk_application_setup\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to create a new Application and trigger the criterion_response setup batch.
 */
class NewApplicationForm extends FormBase {

  /**
   * Valid establishment type codes and their criterion applicability fields.
   *
   * Each code maps to the criterion node field holding the applicability
   * value ('i' = Imperative, 'g' = Guideline, 'x' = Not applicable) for
   * establishments of that type.
   */
  const ESTABLISHMENT_TYPE_CODES = ['hh', 'sa', 'chp', 'cc', 'r', 'a'];

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId() {
    return 'gk_application_setup_new_application_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['establishment'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['establishment']],
      '#title' => $this->t('Establishment'),
      '#required' => TRUE,
    ];

    $form['cycle'] = [
      '#type' => 'select',
      '#title' => $this->t('Application Cycle'),
      '#options' => $this->getCycleOptions(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create Application'),
    ];

    return $form;
  }

  /**
 * Builds the cycle dropdown options from the application_cycles vocabulary.
 */
  protected function getCycleOptions(): array {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadTree('application_cycles', 0, NULL, TRUE);

    $options = [];
    foreach ($terms as $term) {
      $options[$term->id()] = $term->label();
    }
    return $options;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $establishment_id = $form_state->getValue('establishment');
    $establishment = $establishment_id ? Node::load($establishment_id) : NULL;

    if (!$establishment || $establishment->bundle() !== 'establishment') {
      $form_state->setErrorByName('establishment', $this->t('Please select a valid establishment.'));
      return;
    }

    if ($establishment->get('field_est_type')->isEmpty()) {
      $form_state->setErrorByName('establishment', $this->t('The selected establishment has no Establishment Type set.'));
    }
    else {
      $type_term = Term::load($establishment->get('field_est_type')->target_id);
      $code = $type_term ? strtolower(trim((string) $type_term->get('field_establishment_type_code')->value)) : '';
      if (!in_array($code, static::ESTABLISHMENT_TYPE_CODES, TRUE)) {
        $form_state->setErrorByName('establishment', $this->t('The establishment type has no valid Establishment Type Code (expected one of: @codes).', [
          '@codes' => implode(', ', static::ESTABLISHMENT_TYPE_CODES),
        ]));
      }
    }

    if (!$form_state->getValue('cycle')) {
      $form_state->setErrorByName('cycle', $this->t('Please select an application cycle.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $establishment_id = (int) $form_state->getValue('establishment');
    $cycle_tid = (int) $form_state->getValue('cycle');

    $batch = [
      'title' => $this->t('Setting up new application...'),
      'init_message' => $this->t('Starting setup...'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('Setup encountered an error.'),
      'operations' => [
        [
          [static::class, 'batchCreateApplication'],
          [$establishment_id, $cycle_tid],
        ],
        [
          [static::class, 'batchCreateResponses'],
          [],
        ],
      ],
      'finished' => [static::class, 'batchFinished'],
    ];

    batch_set($batch);
  }

  /**
   * Batch op 1: Create the application node.
   */
  public static function batchCreateApplication($establishment_id, $cycle_tid, array &$context) {
    $establishment = Node::load($establishment_id);
    $cycle_term = Term::load($cycle_tid);

    $application = Node::create([
      'type' => 'application_container',
      'title' => $establishment->label() . ' — ' . $cycle_term->label(),
      'field_app_establishment' => ['target_id' => $establishment_id],
      'field_app_cycle' => ['target_id' => $cycle_tid],
      'moderation_state' => 'draft',
    ]);
    $application->save();

    $est_type_tid = $establishment->get('field_est_type')->target_id;
    $type_term = Term::load($est_type_tid);
    $est_type_code = $type_term ? strtolower(trim((string) $type_term->get('field_establishment_type_code')->value)) : '';

    $context['results']['application_id'] = $application->id();
    $context['results']['est_type_code'] = $est_type_code;
    $context['results']['created'] = 0;

    $context['message'] = t('Application %title created.', ['%title' => $application->label()]);
  }

  /**
   * Batch op 2: Create a criterion_response entity for every criterion.
   *
   * Each response snapshots the criterion's applicability for the
   * establishment's type into field_res_criterion_appl, and sets
   * field_res_criterion_active ON for Imperative/Guideline criteria and
   * OFF for Not applicable ones.
   */
  public static function batchCreateResponses(array &$context) {
    $application_id = $context['results']['application_id'];
    $est_type_code = $context['results']['est_type_code'];
    $appl_field = 'field_cr_appl_' . $est_type_code;

    if (!isset($context['sandbox']['progress'])) {
      $ids = array_values(\Drupal::entityQuery('node')
        ->condition('type', 'criterion')
        ->accessCheck(FALSE)
        ->execute());

      $context['sandbox']['ids'] = $ids;
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['max'] = count($ids) ?: 1;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('criterion_response_ent');
    $slice = array_slice($context['sandbox']['ids'], $context['sandbox']['progress'], 20);

    foreach ($slice as $cid) {
      $criterion = Node::load($cid);

      // Look up the criterion's applicability for this establishment type.
      // Empty means the criterion does not apply: treat as Not applicable.
      $appl = $criterion->hasField($appl_field) ? $criterion->get($appl_field)->value : NULL;
      if ($appl !== 'i' && $appl !== 'g') {
        $appl = 'x';
      }

      $response = $storage->create([
        'type' => 'criterion_response',
        'field_res_application' => ['target_id' => $application_id],
        'field_res_criterion' => ['target_id' => $cid],
        'field_res_criterion_appl' => $appl,
        'field_res_criterion_active' => $appl === 'x' ? 0 : 1,
      ]);
      $response->save();

      $context['sandbox']['progress']++;
      $context['results']['created']++;
    }

    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    $context['message'] = t('Created @count / @total criterion responses...', [
      '@count' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['max'],
    ]);
  }

  /**
   * Batch finished callback.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Application created with @count criterion responses.', [
        '@count' => $results['created'] ?? 0,
      ]));
    }
    else {
      \Drupal::messenger()->addError(t('An error occurred while setting up the application.'));
    }
  }

}