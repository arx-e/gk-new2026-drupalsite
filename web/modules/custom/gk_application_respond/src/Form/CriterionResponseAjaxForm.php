<?php

namespace Drupal\gk_application_respond\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Custom form operation 'gk_respond' for criterion_response entities.
 *
 * Registered via hook_entity_type_alter() in gk_application_respond.module.
 * Used exclusively from the accordion respond UI — never linked from standard
 * ECK admin routes so the default form is untouched.
 *
 * Design decisions:
 *  - Extends ContentEntityForm (which ECK entity forms also extend) so that
 *    Drupal's field widget system, validation, and the field_permissions module
 *    all work out of the box. No manual role checks are needed here: field_permissions
 *    configuration on each field controls visibility per role automatically.
 *  - Removes the Delete action from the actions block.
 *  - Adds AJAX to the Save button so saving does not reload the page.
 *  - Adds a "Discard changes" button that reloads a clean copy of the form.
 *  - On successful save, broadcasts a custom jQuery event ('gk:responseSaved')
 *    that the page JS uses to update the criterion row header badge and the
 *    stats sidebar.
 */
class CriterionResponseAjaxForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $entity    = $this->entity;
    $entity_id = (int) $entity->id();

    // IDs used for AJAX targeting.
    $form['#id']     = 'gk-cr-form-' . $entity_id;
    $form['#prefix'] = '<div id="cr-form-wrapper-' . $entity_id . '" class="gk-cr-form-inner">';
    $form['#suffix'] = '</div>';

    // ------------------------------------------------------------------
    // Contextual info bar: show criterion code, type, and current state.
    // Rendered above the fields for orientation.
    // ------------------------------------------------------------------
    $criterion    = $entity->get('field_res_criterion')->entity;
    $crit_appl    = $entity->get('field_res_criterion_appl')->value ?? 'x';
    $crit_appl_label = match ($crit_appl) {
      'i' => (string) $this->t('Imperative'),
      'g' => (string) $this->t('Guideline'),
      default => (string) $this->t('Not Applicable'),
    };
    $crit_code    = '';
    $crit_title   = '';
    $crit_relevance   = [];
    $crit_expectations = [];
    $crit_evidence    = [];

    if ($criterion) {
      $crit_code    = $criterion->get('field_criterion_code_alt')->value
        ?? $criterion->get('field_criterion_code')->value
        ?? '';
      $crit_title   = $criterion->label();

      // Expandable explainer fields (Relevance, Expectations, Audit Evidence).
      foreach (['field_expl_relevance' => 'Relevance', 'field_expl_expectations' => 'Expectations', 'field_expl_audit_evidence' => 'Audit Evidence'] as $field => $label) {
        $value = $criterion->get($field)->value ?? '';
        if ($value) {
          $crit_relevance[$label] = $value;
        }
      }
    }

    $form['gk_context'] = [
      '#type'   => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['gk-cr-context']],
      'code'  => [
        '#markup' => '<span class="gk-cr-context__code">' . htmlspecialchars($crit_code) . '</span> ',
      ],
      'title' => [
        '#markup' => '<span class="gk-cr-context__title">' . htmlspecialchars($crit_title) . '</span>',
      ],
      'type'  => [
        '#markup' => '<span class="gk-badge gk-badge--' . htmlspecialchars($crit_appl) . '">'
          . htmlspecialchars($crit_appl_label) . '</span>',
      ],
    ];

    // Accordion-style explainer section (collapsed by default).
    if (!empty($crit_relevance)) {
      $explainer_content = '';
      foreach ($crit_relevance as $label => $value) {
        $explainer_content .= '<h4>' . htmlspecialchars($label) . '</h4><div>' . $value . '</div>';
      }
      $form['gk_explainer'] = [
        '#type'   => 'details',
        '#title'  => $this->t('Οδηγίες κριτηρίου / Criterion guidance'),
        '#open'   => FALSE,
        '#weight' => -90,
        '#attributes' => ['class' => ['gk-cr-explainer']],
        'content' => ['#markup' => $explainer_content],
      ];
    }

    // ------------------------------------------------------------------
    // Conditional field visibility based on the criterion's requirement flags.
    // The criterion node carries boolean flags:
    //   field_cr_upload_files, field_cr_upload_photos, field_cr_performance_data
    // We snapshot the criterion at setup time but read live flags here for the
    // form — the actual responses were created with the type at that point.
    // ------------------------------------------------------------------
    if ($criterion) {
      $needs_files   = (bool) ($criterion->get('field_cr_upload_files')->value ?? FALSE);
      $needs_photos  = (bool) ($criterion->get('field_cr_upload_photos')->value ?? FALSE);
      $needs_perfdata = (bool) ($criterion->get('field_cr_performance_data')->value ?? FALSE);

      if (!$needs_files && isset($form['field_res_uploads_files'])) {
        $form['field_res_uploads_files']['#access'] = FALSE;
      }
      if (!$needs_photos && isset($form['field_res_uploads_photos'])) {
        $form['field_res_uploads_photos']['#access'] = FALSE;
      }
      if (!$needs_perfdata && isset($form['field_res_performance_data'])) {
        $form['field_res_performance_data']['#access'] = FALSE;
      }
    }

    // Hide system fields from the inline form. field_res_criterion_appl and
    // field_res_criterion_active are intentionally left visible/editable —
    // field_permissions controls which roles may actually change them.
    // field_res_criterion_type is deprecated (replaced by field_res_criterion_appl)
    // and stays hidden.
    foreach (['field_res_application', 'field_res_criterion', 'field_res_criterion_type', 'langcode', 'uid', 'status', 'created', 'changed'] as $hidden) {
      if (isset($form[$hidden])) {
        $form[$hidden]['#access'] = FALSE;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Customise the actions block:
   *   - Remove the Delete action.
   *   - Rename Save to "Save response".
   *   - Wire AJAX to both Save and Discard.
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);

    // Remove delete.
    unset($actions['delete']);

    $entity_id  = (int) $this->entity->id();
    $wrapper_id = 'cr-form-wrapper-' . $entity_id;

    // AJAX Save button.
    $actions['submit']['#value'] = $this->t('Save response');
    $actions['submit']['#ajax']  = [
      'callback'    => [$this, 'ajaxSave'],
      'wrapper'     => $wrapper_id,
      'effect'      => 'fade',
      'progress'    => ['type' => 'throbber', 'message' => $this->t('Saving…')],
    ];

    // Discard button — reloads a clean copy of the entity form.
    $actions['discard'] = [
      '#type'                  => 'submit',
      '#value'                 => $this->t('Discard changes'),
      '#submit'                => ['::discardSubmit'],
      '#limit_validation_errors'=> [],
      '#ajax'                  => [
        'callback' => [$this, 'ajaxDiscard'],
        'wrapper'  => $wrapper_id,
        'effect'   => 'fade',
        'progress' => ['type' => 'throbber', 'message' => $this->t('Reverting…')],
      ],
      '#attributes'            => ['class' => ['button', 'gk-btn-discard']],
      '#weight'                => 10,
    ];

    return $actions;
  }

  // ---------------------------------------------------------------------------
  // Submit handlers
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Saves the entity and suppresses any redirect (AJAX context).
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $form_state->disableRedirect();
    return $status;
  }

  /**
   * Discard submit: does nothing (prevents entity from being saved).
   */
  public function discardSubmit(array $form, FormStateInterface $form_state): void {
    $form_state->disableRedirect();
  }

  // ---------------------------------------------------------------------------
  // AJAX callbacks
  // ---------------------------------------------------------------------------

  /**
   * AJAX callback for the Save button.
   *
   * If there are validation errors: replace the form with validation messages.
   * On success: update the row header badge and broadcast a JS event so the
   * stats sidebar can refresh without a page reload.
   */
  public function ajaxSave(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response  = new AjaxResponse();
    $entity    = $this->entity;
    $entity_id = (int) $entity->id();
    $wrapper   = '#cr-form-wrapper-' . $entity_id;

    if ($form_state->getErrors()) {
      // Return the form with error messages so the user can correct input.
      $response->addCommand(new ReplaceCommand($wrapper, $form));
      return $response;
    }

    // Reload the saved entity to get clean field values.
    $saved = \Drupal::entityTypeManager()
      ->getStorage('criterion_response_ent')
      ->load($entity_id);

    $answer_raw = $saved?->get('field_res_answer')->value;
    $answer     = ((int) ($answer_raw ?? 0)) === 1 ? 'yes' : 'no';
    $compliance = $saved?->get('field_res_compliance_status')->value ?? '';
    $applicability = $saved?->get('field_res_criterion_appl')->value ?? 'x';
    if (!in_array($applicability, ['i', 'g', 'x'], TRUE)) {
      $applicability = 'x';
    }

    // Show a transient status message inside the form wrapper.
    $this->messenger()->addStatus($this->t('Response saved.'));
    $messages_render = ['#type' => 'status_messages'];
    $messages_html   = \Drupal::service('renderer')->renderRoot($messages_render);
    $response->addCommand(new PrependCommand($wrapper, $messages_html));

    // Remove the dirty CSS class from the criterion row.
    $response->addCommand(new InvokeCommand(
      '[data-response-id="' . $entity_id . '"]',
      'removeClass',
      ['gk-criterion-row--dirty']
    ));

    // Broadcast a custom event that JS uses to:
    //   1. Update the row header status badge.
    //   2. Update the per-category progress counters in the sidebar.
    //   3. Handle the row transitioning to/from "Not applicable" (applicability).
    $response->addCommand(new InvokeCommand(
      '[data-response-id="' . $entity_id . '"]',
      'trigger',
      ['gk:responseSaved', [
        'responseId'    => $entity_id,
        'answer'        => $answer,
        'compliance'    => $compliance,
        'applicability' => $applicability,
      ]]
    ));

    return $response;
  }

  /**
   * AJAX callback for the Discard button.
   *
   * Reloads a fresh copy of the entity form (ignoring any unsaved input) and
   * replaces the current form with it.
   */
  public function ajaxDiscard(array &$form, FormStateInterface $form_state): AjaxResponse {
    $entity_id = (int) $this->entity->id();
    $wrapper   = '#cr-form-wrapper-' . $entity_id;

    // Reload entity from storage (clean, persisted state).
    $fresh_entity = \Drupal::entityTypeManager()
      ->getStorage('criterion_response_ent')
      ->load($entity_id);

    /** @var \Drupal\Core\Entity\EntityFormInterface $fresh_form_object */
    $fresh_form_object = \Drupal::entityTypeManager()
      ->getFormObject('criterion_response_ent', 'gk_respond')
      ->setEntity($fresh_entity);

    $fresh_form = \Drupal::formBuilder()->getForm($fresh_form_object);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand($wrapper, $fresh_form));
    $response->addCommand(new InvokeCommand(
      '[data-response-id="' . $entity_id . '"]',
      'removeClass',
      ['gk-criterion-row--dirty']
    ));
    $response->addCommand(new MessageCommand(
      (string) $this->t('Changes discarded.'),
      NULL,
      ['type' => 'warning'],
      TRUE
    ));

    return $response;
  }

}
