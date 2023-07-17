/**
 * @file
 * Provides frontend behavior for date widget.
 */

(function ($, once, Drupal) {

  'use strict';

  Drupal.behaviors.oeListPagesDateWidget = {
    attach: function (context) {
      once('oeListPagesDateWidget', '.oe-list-pages-date-widget-wrapper', context).forEach(function (el) {
        const $widget = $(el),
          $year = $widget.find('.oe-list-pages-date-widget-year'),
          $month = $widget.find('.oe-list-pages-date-widget-month'),
          yearMonths = $widget.data('yearMonths');

        // Get list of month names from months dropdown itself.
        let monthNames = {};
        $month.find('option').each(function () {
          const $option = $(this), month = parseInt($option.val());
          if (month > 0) {
            monthNames[month] = $option.text();
          }
        });

        // Handler to update months dropdown based on months available for
        // selected year.
        let latestYear = $year.val();
        $year.on('change', function () {
          const year = $year.val();

          // Unselect month if year has changed.
          if (latestYear != year) {
            latestYear = year;
            $month.find('option:selected').prop('selected', false);
          }

          // Hide/show months depending on the selected year.
          if (yearMonths[year]) {
            $month.find('option').each(function () {
              const $option = $(this), month = parseInt($option.val());
              if (month > 0) {
                if (yearMonths[year][month]) {
                  $option.show();
                }
                else {
                  $option.hide();
                }
              }
            });

            // Trigger update event to allow libraries rebuild themselves.
            $month.trigger('change').trigger('chosen:updated');
          }
        }).trigger('change');
      });
    }
  };

})(jQuery, once, Drupal);
