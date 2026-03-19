// English comments only

(function ($) {
  function initPreline() {
    if (window.HSStaticMethods && typeof window.HSStaticMethods.autoInit === 'function') {
      window.HSStaticMethods.autoInit();
    }
  }

  function initCheckoutSteps() {
    var $root = $('.fls-checkout-flow--checkout');

    if (!$root.length) {
      initPreline();
      return;
    }

    var order = ['details', 'shipping', 'payment'];
    var $stepButtons = $root.find('[data-fls-step-target]');
    var $stepPanels = $root.find('[data-fls-step-panel]');
    var $connectors = $root.find('[data-fls-step-connector]');

    function setCurrentStep(stepKey) {
      var currentIndex = order.indexOf(stepKey);

      if (currentIndex === -1) {
        currentIndex = 0;
        stepKey = order[0];
      }

      $stepButtons.each(function (index) {
        var $button = $(this);

        $button.removeClass('is-active is-complete is-upcoming');
        $button.removeAttr('aria-current');

        if (index < currentIndex) {
          $button.addClass('is-complete');
        } else if (index === currentIndex) {
          $button.addClass('is-active');
          $button.attr('aria-current', 'step');
        } else {
          $button.addClass('is-upcoming');
        }
      });

      $connectors.each(function (index) {
        var $connector = $(this);

        $connector.removeClass('is-active is-complete is-upcoming');

        if (index < currentIndex) {
          $connector.addClass('is-complete');
        } else if (index === currentIndex) {
          $connector.addClass('is-active');
        } else {
          $connector.addClass('is-upcoming');
        }
      });

      $stepPanels.each(function () {
        var $panel = $(this);
        var panelKey = String($panel.data('fls-step-panel'));
        var isMatch = panelKey === stepKey;

        $panel.toggleClass('is-active', isMatch);
        $panel.toggleClass('is-hidden', !isMatch);
        $panel.attr('aria-hidden', isMatch ? 'false' : 'true');
      });

      initPreline();
    }

    $root.on('click', '[data-fls-step-target], [data-fls-go-step]', function (event) {
      event.preventDefault();

      var stepKey = $(this).data('flsStepTarget') || $(this).data('flsGoStep');
      setCurrentStep(String(stepKey));
    });

    var initialStep = String($root.find('[data-fls-step-target].is-active').first().data('flsStepTarget') || 'details');
    setCurrentStep(initialStep);
  }

  $(document).ready(function () {
    initCheckoutSteps();
  });
})(jQuery);
