<?php

namespace Drupal\gk_application_respond\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Computes per-category statistics for an application's criterion responses.
 *
 * Statistics computed per top-level category. Responses whose
 * field_res_criterion_appl is 'x' (Not applicable) are inactive: they are
 * excluded from all totals/answer/compliance breakdowns and only counted in
 * 'not_applicable'.
 *   - total:        number of ACTIVE criterion responses (appl i or g).
 *   - answered:     active responses where field_res_answer is not empty.
 *   - yes / partial / no: breakdown of field_res_answer values (active only).
 *   - compliant / partly_compliant / non_compliant / not_assessed:
 *       breakdown of field_res_compliance_status (auditor-only field, active only).
 *   - not_applicable: count of responses with appl = 'x'.
 *   - imperative_total / imperative_yes: filtered to Imperative criteria.
 *   - guideline_total / guideline_yes: filtered to Guideline criteria.
 *
 * Two entry points are provided:
 *   - computeFromGrouped(): accepts the already-loaded $grouped array from the
 *     controller (preferred — avoids extra queries).
 *   - computeForApplication(): loads everything from scratch given an app NID
 *     (used by future endpoints or batch operations).
 */
class ApplicationStatsService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Computes stats from the grouped array already assembled by the controller.
   *
   * @param array $grouped
   *   Keyed by category TID. Each entry has 'subcategories' → 'criteria'
   *   where each criterion item is:
   *   [
   *     'response'      => ContentEntityInterface,
   *     'criterion'     => NodeInterface,
   *     'answer'        => string,
   *     'compliance'    => string,
   *     'applicability' => string, // 'i' | 'g' | 'x'
   *     'active'        => bool,
   *   ]
   *
   * @return array
   *   Keyed by category TID, each value being a stats array (see below).
   */
  public function computeFromGrouped(array $grouped): array {
    $stats = [];

    foreach ($grouped as $cat_id => $cat_data) {
      $s = $this->emptyStats();

      foreach ($cat_data['subcategories'] as $sub_data) {
        foreach ($sub_data['criteria'] as $row) {
          $answer = $row['answer']     ?? '';
          $compliance = $row['compliance'] ?? '';
          $appl   = $row['applicability'] ?? 'x';
          $active = $row['active'] ?? ($appl !== 'x');

          // Inactive (Not applicable) responses are excluded from every
          // total/breakdown — they are not counted as answers at all.
          if (!$active) {
            $s['not_applicable']++;
            continue;
          }

          $s['total']++;

          // Answer breakdown.
          if ($answer !== '' && $answer !== NULL) {
            $s['answered']++;
          }
          match ($answer) {
            'yes'     => $s['yes']++,
            'partial' => $s['partial']++,
            'no'      => $s['no']++,
            default   => NULL,
          };

          // Compliance breakdown (auditor field, may be empty for non-auditors).
          match ($compliance) {
            'compliant'     => $s['compliant']++,
            'partial'       => $s['partly_compliant']++,
            'non_compliant' => $s['non_compliant']++,
            default         => $s['not_assessed']++,
          };

          // Imperative vs Guideline split.
          if ($appl === 'i') {
            $s['imperative_total']++;
            if ($answer === 'yes') {
              $s['imperative_yes']++;
            }
          }
          elseif ($appl === 'g') {
            $s['guideline_total']++;
            if ($answer === 'yes') {
              $s['guideline_yes']++;
            }
          }
        }
      }

      // Computed percentages (0-100, integer).
      $s['pct_answered']           = $s['total']            > 0 ? (int) round($s['answered']       / $s['total']            * 100) : 0;
      $s['pct_imperative_yes']     = $s['imperative_total'] > 0 ? (int) round($s['imperative_yes']  / $s['imperative_total'] * 100) : 0;
      $s['pct_guideline_yes']      = $s['guideline_total']  > 0 ? (int) round($s['guideline_yes']   / $s['guideline_total']  * 100) : 0;

      $stats[$cat_id] = $s;
    }

    return $stats;
  }

  /**
   * Loads criterion_responses for an application and computes stats.
   *
   * Useful when stats need to be refreshed independently of the accordion UI
   * (e.g. an async endpoint for a dashboard widget).
   *
   * @param int $application_nid
   *   The application node NID.
   *
   * @return array
   *   Same structure as computeFromGrouped().
   */
  public function computeForApplication(int $application_nid): array {
    $cr_storage = $this->entityTypeManager->getStorage('criterion_response_ent');
    $ids = $cr_storage->getQuery()
      ->condition('field_res_application', $application_nid)
      ->accessCheck(FALSE)
      ->execute();

    $responses = $cr_storage->loadMultiple($ids);

    // Build a minimal grouped structure on the fly.
    $grouped = [];
    foreach ($responses as $response) {
      $criterion = $response->get('field_res_criterion')->entity;
      if (!$criterion) {
        continue;
      }
      $cat_id = $criterion->get('field_criterion_category')->target_id ?? 0;
      if (!isset($grouped[$cat_id])) {
        $grouped[$cat_id] = ['subcategories' => [['criteria' => []]]];
      }
      $appl = $response->get('field_res_criterion_appl')->value ?? 'x';
      if (!in_array($appl, ['i', 'g', 'x'], TRUE)) {
        $appl = 'x';
      }

      $grouped[$cat_id]['subcategories'][0]['criteria'][] = [
        'response'      => $response,
        'criterion'     => $criterion,
        'answer'        => ((int) ($response->get('field_res_answer')->value ?? 0)) === 1 ? 'yes' : 'no',
        'compliance'    => $response->get('field_res_compliance_status')->value ?? '',
        'applicability' => $appl,
        'active'        => $appl !== 'x',
      ];
    }

    return $this->computeFromGrouped($grouped);
  }

  /**
   * Returns a zeroed-out stats array.
   */
  protected function emptyStats(): array {
    return [
      'total'              => 0,
      'answered'           => 0,
      'yes'                => 0,
      'partial'            => 0,
      'no'                 => 0,
      'compliant'          => 0,
      'partly_compliant'   => 0,
      'non_compliant'      => 0,
      'not_assessed'       => 0,
      'not_applicable'     => 0,
      'imperative_total'   => 0,
      'imperative_yes'     => 0,
      'guideline_total'    => 0,
      'guideline_yes'      => 0,
      'pct_answered'       => 0,
      'pct_imperative_yes' => 0,
      'pct_guideline_yes'  => 0,
    ];
  }

}
