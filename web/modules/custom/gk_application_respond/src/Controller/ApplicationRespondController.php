<?php

namespace Drupal\gk_application_respond\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\gk_application_respond\Service\ApplicationStatsService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the /application/{application}/respond route.
 *
 * Loads all criterion_response entities for the application, groups them by
 * category → subcategory, and renders the three-column accordion UI.
 */
class ApplicationRespondController extends ControllerBase {


  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    AccountInterface $currentUser,
    protected ApplicationStatsService $statsService,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('gk_application_respond.stats'),
    );
  }

  // ---------------------------------------------------------------------------
  // Access helpers
  // ---------------------------------------------------------------------------

  /**
   * Returns TRUE if the current user may view/respond to this application.
   *
   * Allowed roles (checked via permissions defined in the site):
   *   - Programme admin / Site admin: always.
   *   - Establishment admin: only if they are listed on the application's
   *     field_app_establishment → field_est_admin_user.
   *   - Auditor: only if they are listed on field_app_auditor_user.
   *   - Jury: read-only view; handled by field_permissions on individual fields.
   *
   * All field access is guarded with hasField(): some of these fields are
   * planned/optional and may be absent in a given environment — a missing
   * field must never turn the access check into a fatal error.
   */
  protected function checkAccess(NodeInterface $application): bool {
    $account = $this->currentUser;

    if ($account->hasPermission('administer nodes')
      || $account->hasPermission('bypass node access')) {
      return TRUE;
    }

    // Programme admins / operators.
    if ($account->hasPermission('manage gk applications')) {
      return TRUE;
    }

    // Auditors listed on the application.
    if ($application->hasField('field_app_auditor_user')) {
      $auditor_ids = array_column(
        $application->get('field_app_auditor_user')->getValue(),
        'target_id'
      );
      if (in_array($account->id(), $auditor_ids, TRUE)) {
        return TRUE;
      }
    }

    // Jury members.
    if ($account->hasPermission('view gk applications jury')) {
      return TRUE;
    }

    // Establishment admins: must be listed on the linked establishment.
    if ($application->hasField('field_app_establishment')) {
      $establishment = $application->get('field_app_establishment')->entity;
      if ($establishment && $establishment->hasField('field_est_admin_user')) {
        $admin_ids = array_column(
          $establishment->get('field_est_admin_user')->getValue(),
          'target_id'
        );
        if (in_array($account->id(), $admin_ids, TRUE)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  // ---------------------------------------------------------------------------
  // Main page controller
  // ---------------------------------------------------------------------------

  /**
   * Renders the application respond page.
   *
   * @param \Drupal\node\NodeInterface $application
   *   The application node (upcasted from the {application} route parameter).
   *
   * @return array
   *   A render array for the three-column respond UI.
   */
  public function respond(NodeInterface $application): array {
    // Verify bundle.
    if ($application->bundle() !== 'application_container') {
      throw new NotFoundHttpException();
    }

    // Access check.
    if (!$this->checkAccess($application)) {
      throw new AccessDeniedHttpException();
    }

    // ------------------------------------------------------------------
    // Load all criterion_response entities for this application.
    // ------------------------------------------------------------------
    $cr_storage = $this->entityTypeManager()->getStorage('criterion_response_ent');
    $ids = $cr_storage->getQuery()
      ->condition('field_res_application', $application->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [
        '#markup' => $this->t(
          'No criterion responses found for this application. Please run the <a href=":url">setup process</a> first.',
          [':url' => Url::fromRoute('gk_application_setup.new_application')->toString()]
        ),
      ];
    }

    $responses = $cr_storage->loadMultiple($ids);

    // ------------------------------------------------------------------
    // Eager-load related criterion nodes and taxonomy terms in bulk to
    // avoid N+1 query problems.
    // ------------------------------------------------------------------
    $criterion_ids = [];
    foreach ($responses as $response) {
      if ($cid = $response->get('field_res_criterion')->target_id) {
        $criterion_ids[$cid] = $cid;
      }
    }

    $node_storage = $this->entityTypeManager()->getStorage('node');
    $criteria_nodes = $node_storage->loadMultiple($criterion_ids);

    // Collect taxonomy term IDs.
    $category_ids   = [];
    $subcategory_ids = [];
    foreach ($criteria_nodes as $cn) {
      if ($cat_id = $cn->get('field_criterion_category')->target_id) {
        $category_ids[$cat_id] = $cat_id;
      }
      if ($sub_id = $cn->get('field_criterion_subcategory')->target_id) {
        $subcategory_ids[$sub_id] = $sub_id;
      }
    }

    $term_storage = $this->entityTypeManager()->getStorage('taxonomy_term');
    $categories   = $term_storage->loadMultiple($category_ids);
    $subcategories = $term_storage->loadMultiple($subcategory_ids);

    // ------------------------------------------------------------------
    // Group responses: category → subcategory → criterion rows.
    // Each entry in $grouped is keyed by category TID.
    // ------------------------------------------------------------------
    $grouped = [];

    foreach ($responses as $response) {
      $criterion = $criteria_nodes[$response->get('field_res_criterion')->target_id] ?? NULL;
      if (!$criterion) {
        continue;
      }

      $cat_id  = $criterion->get('field_criterion_category')->target_id ?? 0;
      $sub_id  = $criterion->get('field_criterion_subcategory')->target_id ?? 0;
      $cat     = $categories[$cat_id]   ?? NULL;
      $sub     = $subcategories[$sub_id] ?? NULL;

      $cat_label  = $cat  ? $cat->label()  : $this->t('Uncategorised');
      $cat_code   = $cat  ? $cat->get('field_category_code')->value  : '';
      $sub_label  = $sub  ? $sub->label()  : $this->t('General');
      $sub_code   = $sub  ? $sub->get('field_category_code')->value  : '';

      if (!isset($grouped[$cat_id])) {
        $grouped[$cat_id] = [
          'id'            => $cat_id,
          'label'         => $cat_label,
          'code'          => $cat_code,
          'subcategories' => [],
        ];
      }
      if (!isset($grouped[$cat_id]['subcategories'][$sub_id])) {
        $grouped[$cat_id]['subcategories'][$sub_id] = [
          'id'       => $sub_id,
          'label'    => $sub_label,
          'code'     => $sub_code,
          'criteria' => [],
        ];
      }

      $appl = $response->get('field_res_criterion_appl')->value ?? 'x';
      if (!in_array($appl, ['i', 'g', 'x'], TRUE)) {
        $appl = 'x';
      }

      $grouped[$cat_id]['subcategories'][$sub_id]['criteria'][] = [
        'response'      => $response,
        'criterion'     => $criterion,
        'answer'        => ApplicationStatsService::normalizeAnswer($response->get('field_res_answer')->value ?? ''),
        'compliance'    => $response->get('field_res_compliance_status')->value ?? '',
        'applicability' => $appl,
        'active'        => $appl !== 'x',
      ];
    }

    // Sort categories and subcategories by their numeric code.
    uasort($grouped, fn($a, $b) => strnatcmp($a['code'], $b['code']));
    foreach ($grouped as &$cat_data) {
      uasort($cat_data['subcategories'], fn($a, $b) => strnatcmp($a['code'], $b['code']));
      foreach ($cat_data['subcategories'] as &$sub_data) {
        usort($sub_data['criteria'], fn($a, $b) => strnatcmp(
          $a['criterion']->get('field_criterion_code_alt')->value ?? '',
          $b['criterion']->get('field_criterion_code_alt')->value ?? ''
        ));
      }
    }
    unset($cat_data, $sub_data);

    // ------------------------------------------------------------------
    // Build render arrays for each level.
    // ------------------------------------------------------------------
    $stats = $this->statsService->computeFromGrouped($grouped);

    $categories_render = [];
    $category_nav      = [];

    foreach ($grouped as $cat_id => $cat_data) {
      $sub_render = [];

      foreach ($cat_data['subcategories'] as $sub_id => $sub_data) {
        $rows_render = [];
        $sub_imperative_total = 0;
        $sub_guideline_total  = 0;

        foreach ($sub_data['criteria'] as $row) {
          /** @var \Drupal\Core\Entity\ContentEntityInterface $response */
          $response = $row['response'];
          /** @var \Drupal\node\NodeInterface $criterion */
          $criterion = $row['criterion'];

          $rows_render[] = [
            '#theme'               => 'gk_criterion_row',
            '#response_id'         => $response->id(),
            '#criterion_code'      => $criterion->get('field_criterion_code_alt')->value
              ?? $criterion->get('field_criterion_code')->value,
            '#criterion_title'     => $criterion->label(),
            '#applicability'       => $row['applicability'],
            '#applicability_label' => $this->applicabilityLabel($row['applicability']),
            '#active'              => $row['active'],
            '#answer'              => $row['answer'],
            '#compliance'          => $row['compliance'],
            '#application_id'      => $application->id(),
          ];

          if ($row['active']) {
            if ($row['applicability'] === 'i') {
              $sub_imperative_total++;
            }
            elseif ($row['applicability'] === 'g') {
              $sub_guideline_total++;
            }
          }
        }

        $sub_render[$sub_id] = [
          '#theme'              => 'gk_criterion_subcategory',
          '#subcategory_id'     => $sub_id,
          '#subcategory_label'  => $sub_data['label'],
          '#subcategory_code'   => $sub_data['code'],
          '#criteria'           => $rows_render,
          '#progress'           => [
            'imperative' => $sub_imperative_total,
            'guideline'  => $sub_guideline_total,
          ],
        ];
      }

      $cat_stats = $stats[$cat_id] ?? [
        'imperative_total' => 0,
        'imperative_yes'   => 0,
        'guideline_total'  => 0,
        'guideline_yes'    => 0,
      ];

      $categories_render[$cat_id] = [
        '#theme'          => 'gk_criterion_category',
        '#category_id'    => $cat_id,
        '#category_label' => $cat_data['label'],
        '#category_code'  => $cat_data['code'],
        '#subcategories'  => $sub_render,
        '#progress'       => [
          'imperative_total' => $cat_stats['imperative_total'],
          'imperative_yes'   => $cat_stats['imperative_yes'],
          'guideline_total'  => $cat_stats['guideline_total'],
          'guideline_yes'    => $cat_stats['guideline_yes'],
        ],
      ];

      $category_nav[] = [
        'id'                => 'gk-category-' . $cat_id,
        'label'             => $cat_data['label'],
        'code'              => $cat_data['code'],
        'imperative_total'  => $cat_stats['imperative_total'],
        'imperative_yes'    => $cat_stats['imperative_yes'],
        'guideline_total'   => $cat_stats['guideline_total'],
        'guideline_yes'     => $cat_stats['guideline_yes'],
      ];
    }

    $template_url = Url::fromRoute(
      'gk_application_respond.criterion_form',
      ['application' => $application->id(), 'criterion_response' => 0]
    )->toString();
    $form_url_template = preg_replace('#/0/form$#', '/__CRID__/form', $template_url);

    // ------------------------------------------------------------------
    // Return main render array.
    // ------------------------------------------------------------------
    return [
      '#theme'       => 'gk_application_respond',
      '#application' => $application,
      '#categories'  => $categories_render,
      '#stats'       => $stats,
      '#category_nav'=> $category_nav,
      '#attached'    => [
        'library' => ['gk_application_respond/respond'],
        'drupalSettings' => [
          'gkApplicationRespond' => [
            'applicationId' => (int) $application->id(),
            // Template URL for AJAX form loading; JS will replace __CRID__.
            'formUrlTemplate' => $form_url_template,
            // Per-category stats for the live sidebar (JS updates these on save).
            'stats' => $stats,
          ],
        ],
      ],
      '#cache' => [
        // Invalidate when any criterion_response for this application changes.
        'tags'    => ['criterion_response_list', 'node:' . $application->id()],
        'contexts'=> ['user', 'user.roles'],
      ],
    ];
  }

  /**
   * Title callback for the respond route.
   */
  public function title(NodeInterface $application): string {
    return (string) $this->t('Respond — @title', ['@title' => $application->label()]);
  }

  /**
   * Maps a field_res_criterion_appl machine value to a human label.
   *
   * @param string $appl
   *   One of 'i', 'g', 'x'.
   *
   * @return string
   *   Translated label: Imperative / Guideline / Not Applicable.
   */
  public static function applicabilityLabel(string $appl): string {
    return match ($appl) {
      'i' => (string) t('Imperative'),
      'g' => (string) t('Guideline'),
      default => (string) t('Not Applicable'),
    };
  }

}
