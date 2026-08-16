<?php

namespace Drupal\gk_application_respond\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Computes per-category statistics for an application's criterion responses.
 *
 * Statistics computed per top-level category:
 *   - total:        number of criterion responses.
 *   - answered:     responses where field_res_answer is not empty.
 *   - yes / partial / no: breakdown of field_res_answer values.
 *   - compliant / partly_compliant / non_compliant / not_assessed:
 *       breakdown of field_res_compliance_status (auditor-only field).
 *   - imperative_total / imperative_answered: filtered to Imperative criteria.
 *   - guideline_total / guideline_answered: filtered to Guideline criteria.
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
   *     'response'       => ContentEntityInterface,
   *     'criterion'      => NodeInterface,
   *     'answer'         => string,
   *     'compliance'     => string,
   *     'criterion_type' => string,
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
          $answer     = $row['answer']         ?? '';
          $compliance = $row['compliance']     ?? '';
          $type       = $row['criterion_type'] ?? '';

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
          if (strtolower($type) === 'imperative') {
            $s['imperative_total']++;
            if ($answer !== '') {
              $s['imperative_answered']++;
            }
          }
          elseif (strtolower($type) === 'guideline') {
            $s['guideline_total']++;
            if ($answer !== '') {
              $s['guideline_answered']++;
            }
          }
        }
      }

      // Computed percentages (0-100, integer).
      $s['pct_answered']            = $s['total']             > 0 ? (int) round($s['answered']            / $s['total']             * 100) : 0;
      $s['pct_imperative_answered'] = $s['imperative_total']  > 0 ? (int) round($s['imperative_answered'] / $s['imperative_total']  * 100) : 0;
      $s['pct_guideline_answered']  = $s['guideline_total']   > 0 ? (int) round($s['guideline_answered']  / $s['guideline_total']   * 100) : 0;

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
      $grouped[$cat_id]['subcategories'][0]['criteria'][] = [
        'response'       => $response,
        'criterion'      => $criterion,
        'answer'         => $response->get('field_res_answer')->value ?? '',
        'compliance'     => $response->get('field_res_compliance_status')->value ?? '',
        'criterion_type' => $response->get('field_res_criterion_type')->value ?? '',
      ];
    }

    return $this->computeFromGrouped($grouped);
  }

  /**
   * Returns a zeroed-out stats array.
   */
  protected function emptyStats(): array {
    return [
      'total'                  => 0,
      'answered'               => 0,
      'yes'                    => 0,
      'partial'                => 0,
      'no'                     => 0,
      'compliant'              => 0,
      'partly_compliant'       => 0,
      'non_compliant'          => 0,
      'not_assessed'           => 0,
      'imperative_total'       => 0,
      'imperative_answered'    => 0,
      'guideline_total'        => 0,
      'guideline_answered'     => 0,
      'pct_answered'           => 0,
      'pct_imperative_answered'=> 0,
      'pct_guideline_answered' => 0,
    ];
  }

}
