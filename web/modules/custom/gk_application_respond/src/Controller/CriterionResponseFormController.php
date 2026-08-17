<?php

namespace Drupal\gk_application_respond\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * AJAX controller: delivers the entity sub-form for a single criterion_response.
 *
 * This is called via Drupal.ajax when the user expands a criterion row in the
 * accordion. It:
 *   1. Loads the criterion_response entity.
 *   2. Builds the entity form using the custom 'gk_respond' form operation
 *      (registered in hook_entity_type_alter — see gk_application_respond.module).
 *   3. Returns an AjaxResponse that injects the rendered form into the DOM
 *      inside the criterion row's .gk-criterion-form-container element.
 *
 * The form's own save/discard AJAX callbacks (in CriterionResponseAjaxForm)
 * handle subsequent round-trips for field updates.
 */
class CriterionResponseFormController extends ControllerBase {

  protected RendererInterface $renderer;

  public function __construct(
      EntityTypeManagerInterface $entityTypeManager,
      FormBuilderInterface $formBuilder,
      RendererInterface $renderer,
  ) {
      $this->entityTypeManager = $entityTypeManager;
      $this->formBuilder = $formBuilder;
      $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('form_builder'),
      $container->get('renderer'),
    );
  }

  /**
   * Loads and returns the criterion_response entity form as an AjaxResponse.
   *
   * Route: GET /application/{application}/criterion-response/{criterion_response}/form
   *
   * @param \Drupal\node\NodeInterface $application
   *   The application node (for ownership/access validation).
   * @param int|string $criterion_response
   *   The criterion_response entity ID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function loadForm(NodeInterface $application, $criterion_response, Request $request): AjaxResponse {
    $cr_storage = $this->entityTypeManager()->getStorage('criterion_response_ent');
    $entity     = $cr_storage->load($criterion_response);

    if (!$entity) {
      throw new NotFoundHttpException('Criterion response entity not found.');
    }

    // Ensure the criterion_response belongs to the given application.
    $app_id = $entity->get('field_res_application')->target_id ?? NULL;
    if ((int) $app_id !== (int) $application->id()) {
      throw new AccessDeniedHttpException('Criterion response does not belong to this application.');
    }

    // Inactive (Not applicable) responses are never editable — the row has
    // no Expand button, but guard here too in case of a stale/forged request.
    $appl = $entity->get('field_res_criterion_appl')->value ?? 'x';
    if ($appl === 'x') {
      $response = new AjaxResponse();
      $response->addCommand(new HtmlCommand(
        '[data-response-id="' . (int) $criterion_response . '"] .gk-criterion-form-container',
        '<p class="gk-criterion-row__na-notice">' . $this->t('This criterion is not applicable and cannot be edited.') . '</p>'
      ));
      $response->addCommand(new InvokeCommand(
        '[data-response-id="' . (int) $criterion_response . '"]',
        'addClass',
        ['gk-criterion-row--loaded gk-criterion-row--readonly']
      ));
      return $response;
    }

    // Check entity-level access.
    if (!$entity->access('update')) {
      // Build a read-only view instead of throwing 403, because some roles
      // (Jury, read-only programme viewers) can see responses but not edit them.
      $view_builder = $this->entityTypeManager()->getViewBuilder('criterion_response_ent');
      $view = $view_builder->view($entity, 'default');
      $html = $this->renderer->render($view);

      $response = new AjaxResponse();
      $response->addCommand(new HtmlCommand(
        '[data-response-id="' . (int) $criterion_response . '"] .gk-criterion-form-container',
        $html
      ));
      $response->addCommand(new InvokeCommand(
        '[data-response-id="' . (int) $criterion_response . '"]',
        'addClass',
        ['gk-criterion-row--loaded gk-criterion-row--readonly']
      ));
      return $response;
    }

    // ------------------------------------------------------------------
    // Build the entity form using the 'gk_respond' form operation.
    // This form class strips admin actions and adds AJAX save/discard buttons.
    // The field_permissions module automatically controls which fields are
    // rendered based on the current user's roles — no manual role checks needed.
    // ------------------------------------------------------------------
    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $this->entityTypeManager()
      ->getFormObject('criterion_response_ent', 'gk_respond')
      ->setEntity($entity);

    $form = $this->formBuilder->getForm($form_object);

    // Wrap in an outer div so ReplaceCommand can target it after save.
    $wrapper_id = 'cr-form-wrapper-' . (int) $criterion_response;
    $form['#prefix'] = '<div id="' . $wrapper_id . '">';
    $form['#suffix'] = '</div>';

    $rendered = $this->renderer->renderRoot($form);

    // ------------------------------------------------------------------
    // Build the AjaxResponse.
    // ------------------------------------------------------------------
    $response = new AjaxResponse();

    // Attach the form's JS/CSS libraries and drupalSettings (including the
    // drupalSettings.ajax bindings for the Save/Discard/#ajax buttons) so
    // Drupal.behaviors.AJAX can actually bind to them after injection.
    $response->setAttachments($form['#attached'] ?? []);

    // Inject rendered form HTML into the row's form container.
    $response->addCommand(new HtmlCommand(
      '[data-response-id="' . (int) $criterion_response . '"] .gk-criterion-form-container',
      $rendered
    ));

    // Mark the row as loaded so JS knows not to re-fetch.
    $response->addCommand(new InvokeCommand(
      '[data-response-id="' . (int) $criterion_response . '"]',
      'addClass',
      ['gk-criterion-row--loaded']
    ));

    // Attach drupal.ajax behaviours to the newly injected HTML.
    // Drupal's AjaxResponse does this automatically when it runs
    // Drupal.attachBehaviors(), but we also trigger a custom JS event
    // so gk-application-respond.js can set up dirty-state listeners.
    $response->addCommand(new InvokeCommand(
      '[data-response-id="' . (int) $criterion_response . '"] .gk-criterion-form-container',
      'trigger',
      ['gk:formLoaded', ['responseId' => (int) $criterion_response]]
    ));

    return $response;
  }

}
