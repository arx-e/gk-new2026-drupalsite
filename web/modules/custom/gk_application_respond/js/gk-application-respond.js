/**
 * @file gk-application-respond.js
 *
 * Behaviour for the /application/{application}/respond page.
 *
 * Responsibilities:
 *  1. Accordion expand/collapse with AJAX form loading on first open.
 *  2. Dirty-state tracking (detecting unsaved form changes).
 *  3. "Unsaved changes" modal when collapsing a dirty row.
 *  4. Live sidebar stats update when gk:responseSaved fires.
 *  5. Scroll-spy: highlights the active category in the left nav as user scrolls.
 *  6. Smooth anchor-scroll when clicking left nav links.
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // ---------------------------------------------------------------------------
  // Settings from drupalSettings (set in ApplicationRespondController).
  // ---------------------------------------------------------------------------
  let gkSettings = {};

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------

  /**
   * Tracks which criterion rows have already had their form loaded.
   * @type {Set<number>}
   */
  const loadedRows = new Set();

  /**
   * Pending "collapse after discard" data used by the modal.
   * @type {{ responseId: number|null, $row: jQuery|null }}
   */
  let pendingCollapse = { responseId: null, $row: null };

  // ---------------------------------------------------------------------------
  // Drupal behaviour
  // ---------------------------------------------------------------------------
  Drupal.behaviors.gkApplicationRespond = {
    attach(context, settings) {
      gkSettings = settings.gkApplicationRespond || {};

      // Run once() so behaviours don't double-attach after AJAX redraws.
      once('gk-respond-init', '#gk-accordion', context).forEach(initAccordion);
      once('gk-respond-nav', '.gk-respond-nav', context).forEach(initNavScrollSpy);
      once('gk-respond-modal', '#gk-discard-modal', context).forEach(initModal);

      // Re-attach dirty-state listeners whenever a new form is injected.
      once('gk-respond-form-listeners', '.gk-criterion-form-container', context)
        .forEach(initFormDirtyListeners);
    },
  };

  // ---------------------------------------------------------------------------
  // 1. Accordion + AJAX form loading
  // ---------------------------------------------------------------------------
  function initAccordion(accordionEl) {
    $(accordionEl).on('click', '[data-toggle-response]', function (e) {
      e.preventDefault();
      const $btn       = $(this);
      const responseId = parseInt($btn.data('toggle-response'), 10);
      const $row       = $btn.closest('.gk-criterion-row');
      const $body      = $row.find('.gk-criterion-row__body');
      const isOpen     = $row.hasClass('gk-criterion-row--open');

      if (isOpen) {
        // --- Attempting to collapse ---
        if ($row.hasClass('gk-criterion-row--dirty')) {
          // Dirty: show the "save or discard" modal.
          pendingCollapse = { responseId, $row };
          openModal();
          return;
        }
        collapseRow($row, $btn, $body);
      } else {
        // --- Expanding ---
        expandRow($row, $btn, $body, responseId);
      }
    });
  }

  function expandRow($row, $btn, $body, responseId) {
    $btn.attr('aria-expanded', 'true');
    $btn.find('.gk-criterion-row__toggle-label').text(Drupal.t('Collapse'));
    $body.removeAttr('hidden');
    $row.addClass('gk-criterion-row--open');

    if (!loadedRows.has(responseId)) {
      // First open: trigger AJAX to load the entity form.
      $row.addClass('gk-criterion-row--loading');
      loadCriterionForm(responseId, $row);
    }
  }

  function collapseRow($row, $btn, $body) {
    $btn.attr('aria-expanded', 'false');
    $btn.find('.gk-criterion-row__toggle-label').text(Drupal.t('Expand'));
    $body.attr('hidden', 'hidden');
    $row.removeClass('gk-criterion-row--open');
  }

  // ---------------------------------------------------------------------------
  // AJAX form loading via Drupal.ajax
  // ---------------------------------------------------------------------------
  function loadCriterionForm(responseId, $row) {
    if (!gkSettings.formUrlTemplate) {
      console.warn('gkApplicationRespond: formUrlTemplate not set in drupalSettings.');
      return;
    }

    const url = gkSettings.formUrlTemplate.replace('__CRID__', responseId);

    // Use Drupal.ajax to load the form so Drupal.attachBehaviors() runs
    // automatically on the injected HTML (needed for file widgets, AJAX buttons).
    const ajaxRequest = Drupal.ajax({
      url,
      base:     false,
      element:  $row.find('.gk-criterion-form-container')[0],
      progress: false,
    });

    ajaxRequest.execute()
      .then(() => {
        loadedRows.add(responseId);
        $row.removeClass('gk-criterion-row--loading');
      })
      .catch((err) => {
        console.error('gkApplicationRespond: Failed to load form for response', responseId, err);
        $row.removeClass('gk-criterion-row--loading');
        $row.find('.gk-criterion-row__loading').html(
          '<span class="gk-error-msg">' + Drupal.t('Failed to load form. Please reload the page.') + '</span>'
        );
      });
  }

  // ---------------------------------------------------------------------------
  // 2. Dirty-state tracking
  // ---------------------------------------------------------------------------

  /**
   * Attaches change listeners to the form container so we can detect unsaved
   * edits. Called by Drupal.behaviors.attach, which runs again each time new
   * HTML is injected via AJAX (because of once() keying on the container element).
   */
  function initFormDirtyListeners(containerEl) {
    const $container = $(containerEl);
    const $row       = $container.closest('.gk-criterion-row');
    const responseId = parseInt($row.data('response-id'), 10);

    // Listen to any input/change inside the container.
    $container.on(
      'change input',
      'input, select, textarea, [contenteditable]',
      function () {
        if (!$row.hasClass('gk-criterion-row--dirty')) {
          $row.addClass('gk-criterion-row--dirty');
          // Visual indicator in the row header.
          $row.find('.gk-criterion-row__toggle-label').text(Drupal.t('Collapse *'));
        }
      }
    );

    // Also listen to the gk:formLoaded event so we can re-attach after AJAX discard.
    $container.on('gk:formLoaded', function () {
      // Form freshly injected — ensure dirty is clear.
      $row.removeClass('gk-criterion-row--dirty');
      $row.find('.gk-criterion-row__toggle-label').text(
        $row.hasClass('gk-criterion-row--open') ? Drupal.t('Collapse') : Drupal.t('Expand')
      );
    });
  }

  // ---------------------------------------------------------------------------
  // 3. Unsaved-changes modal
  // ---------------------------------------------------------------------------
  function initModal(modalEl) {
    const $modal   = $(modalEl);
    const $overlay = $('#gk-discard-modal-overlay');

    // "Save first" — click the Save button in the open form, then collapse.
    $('#gk-discard-modal-save').on('click', function () {
      const $row = pendingCollapse.$row;
      closeModal();
      if ($row) {
        const $saveBtn = $row.find('input[type=submit][value]').filter(function () {
          return $(this).val() && $(this).val().toLowerCase().includes('save');
        }).first();

        if ($saveBtn.length) {
          $saveBtn.trigger('mousedown').trigger('click');
          $row.one('gk:responseSaved', function () {
            const $btn  = $row.find('[data-toggle-response]');
            const $body = $row.find('.gk-criterion-row__body');
            collapseRow($row, $btn, $body);
          });
        }
      }
    });

    // "Discard" — click the Discard button in the form, then collapse.
    $('#gk-discard-modal-discard').on('click', function () {
      const $row = pendingCollapse.$row;
      closeModal();
      if ($row) {
        const $discardBtn = $row.find('.gk-btn-discard').first();

        if ($discardBtn.length) {
          $discardBtn.trigger('click');
          setTimeout(function () {
            const $btn  = $row.find('[data-toggle-response]');
            const $body = $row.find('.gk-criterion-row__body');
            collapseRow($row, $btn, $body);
          }, 300);
        } else {
          const $btn  = $row.find('[data-toggle-response]');
          const $body = $row.find('.gk-criterion-row__body');
          $row.removeClass('gk-criterion-row--dirty');
          collapseRow($row, $btn, $body);
        }
      }
    });

    // "Cancel" — keep the row open, do nothing.
    $('#gk-discard-modal-cancel').on('click', closeModal);
    $overlay.on('click', closeModal);

    // Trap focus inside the modal when open.
    $modal.on('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    });
  }

  function openModal() {
    const $modal   = $('#gk-discard-modal');
    const $overlay = $('#gk-discard-modal-overlay');
    $modal.removeAttr('hidden');
    $overlay.removeAttr('hidden');
    $modal.find('button').first().trigger('focus');
    $('body').addClass('gk-modal-open');
  }

  function closeModal() {
    $('#gk-discard-modal').attr('hidden', 'hidden');
    $('#gk-discard-modal-overlay').attr('hidden', 'hidden');
    $('body').removeClass('gk-modal-open');
    pendingCollapse = { responseId: null, $row: null };
  }

  // ---------------------------------------------------------------------------
  // 4. Live sidebar stats update (gk:responseSaved event)
  // ---------------------------------------------------------------------------

  /**
   * The CriterionResponseAjaxForm broadcasts this custom event on the row element
   * after a successful save. We use it to:
   *   a) Update the answer badge in the row header.
   *   b) Re-compute and redraw per-category stats in the sidebar.
   *   c) Update the overall donut.
   */
  $(document).on('gk:responseSaved', '.gk-criterion-row', function (e, data) {
    const $row       = $(this);
    const responseId = data.responseId;
    const answer     = data.answer || '';
    const compliance = data.compliance || '';

    // ---- a) Update row header answer badge ----
    const answerLabels = {
      yes: 'Ναι',
      no:  'Όχι',
    };
    const $answerBadge = $('[data-answer-badge="' + responseId + '"]');
    $answerBadge
      .removeClass('gk-answer--yes gk-answer--no gk-answer--empty')
      .addClass('gk-answer--' + answer)
      .text(answerLabels[answer] || 'Όχι');

    const complianceLabels = {
      compliant:     Drupal.t('Compliant'),
      partial:       Drupal.t('Partly compliant'),
      non_compliant: Drupal.t('Non-compliant'),
    };
    if (compliance) {
      const $compBadge = $('[data-compliance-badge="' + responseId + '"]');
      $compBadge
        .removeClass('gk-compliance--compliant gk-compliance--partial gk-compliance--non-compliant gk-compliance--empty')
        .addClass('gk-compliance--' + compliance.replace('_', '-'))
        .text(complianceLabels[compliance] || '')
        .show();
    }

    // Update the data-answer attribute for future stat re-calculations.
    $row.attr('data-answer', answer);
    $row.attr('data-compliance', compliance);

    // ---- b) Re-compute category stats from live DOM ----
    recomputeCategoryStats($row.closest('.gk-category'));
  });

  /**
   * Re-scans all .gk-criterion-row elements inside a .gk-category to
   * recompute and redraw the sidebar stats for that category.
   *
   * Uses DOM data-* attributes (data-answer, data-compliance) as the source
   * of truth, which are updated on each gk:responseSaved event.
   */
  function recomputeCategoryStats($categoryEl) {
    const catId   = $categoryEl.data('cat-id');
    const $rows   = $categoryEl.find('.gk-criterion-row');

    let total = 0, answered = 0;
    let yes = 0, partial = 0, no = 0;
    let compliant = 0, partlyCompliant = 0, nonCompliant = 0;

    $rows.each(function () {
      const answer = $(this).attr('data-answer') || 'no';
      const compliance = $(this).attr('data-compliance') || '';
      total++;
      if (answer === 'yes') yes++;
      if (answer === 'no')  no++;
      if (compliance === 'compliant')     compliant++;
      if (compliance === 'partial')       partlyCompliant++;
      if (compliance === 'non_compliant') nonCompliant++;
    });

    const pct = total > 0 ? Math.round(yes / total * 100) : 0;

    // ---- Update accordion category progress ----
    $categoryEl
      .attr('data-yes', yes)
      .attr('data-total', total);
    $categoryEl.find('[data-fraction="' + catId + '"]')
      .text(yes + '/' + total);
    $categoryEl.find('.gk-progress-bar--category')
      .attr('aria-valuenow', pct)
      .css('--pct', pct + '%');

    // ---- Update sidebar category bar ----
    $('[data-cat-answered="' + catId + '"]').text(answered);
    $('[data-cat-total="'    + catId + '"]').text(total);
    $('[data-cat-bar="'      + catId + '"]')
      .attr('aria-valuenow', pct)
      .css('--pct', pct + '%');

    // Answer chips.
    $('[data-chip-yes="'    + catId + '"]').closest('.gk-chip').toggle(yes > 0).find('').end()
      .text(Drupal.t('Yes') + ': ' + yes);
    $('[data-chip-partial="' + catId + '"]').closest('.gk-chip').toggle(partial > 0)
      .text(Drupal.t('Partial') + ': ' + partial);
    $('[data-chip-no="'     + catId + '"]').closest('.gk-chip').toggle(no > 0)
      .text(Drupal.t('No') + ': ' + no);

    // Left nav fraction.
    $('[data-nav-cat="' + catId + '"] .gk-respond-nav__fraction')
      .text(answered + '/' + total);
    $('[data-nav-cat="' + catId + '"] .gk-mini-bar__fill')
      .css('width', pct + '%');

    // ---- Recompute overall totals ----
    recomputeOverallStats();
  }

  function recomputeOverallStats() {
    let total = 0, yes = 0;
    $('.gk-category').each(function () {
      total += parseInt($(this).attr('data-total'), 10) || 0;
      yes   += parseInt($(this).attr('data-yes'),   10) || 0;
    });
    const pct = total > 0 ? Math.round(yes / total * 100) : 0;

    $('[data-overall-yes]').text(yes);
    $('[data-overall-total]').text(total);
    $('[data-overall-pct]').text(pct + '%');

    // SVG donut arc: stroke-dasharray is "pct, 100".
    $('[data-donut-overall]').attr('stroke-dasharray', pct + ', 100');
  }

  // ---------------------------------------------------------------------------
  // 5 & 6. Scroll-spy and smooth anchor scroll for the left nav
  // ---------------------------------------------------------------------------
  function initNavScrollSpy(navEl) {
    const $nav = $(navEl);

    // Smooth scroll to category when nav link clicked.
    $nav.on('click', 'a[href^="#"]', function (e) {
      e.preventDefault();
      const target = $(this).attr('href');
      const $target = $(target);
      if ($target.length) {
        const offset = $target.offset().top - 20;
        $('html, body').animate({ scrollTop: offset }, 250);
      }
    });

    // Scroll-spy: highlight the nav item whose section is in view.
    let ticking = false;
    $(window).on('scroll.gk-spy', function () {
      if (!ticking) {
        window.requestAnimationFrame(function () {
          updateActiveNavItem($nav);
          ticking = false;
        });
        ticking = true;
      }
    });

    updateActiveNavItem($nav);
  }

  function updateActiveNavItem($nav) {
    const scrollTop     = $(window).scrollTop() + 80; // 80px offset for sticky nav height
    let activeCatId     = null;

    $('.gk-category').each(function () {
      const top = $(this).offset().top;
      if (scrollTop >= top) {
        activeCatId = $(this).data('cat-id');
      }
    });

    $nav.find('.gk-respond-nav__item').removeClass('is-active');
    if (activeCatId) {
      $nav.find('[data-nav-cat="' + activeCatId + '"]').addClass('is-active');
    }
  }

}(jQuery, Drupal, drupalSettings, once));
