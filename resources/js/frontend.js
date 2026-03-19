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
    var $buttons = $root.find('[data-fls-step]');
    var $panes = $root.find('[data-fls-step-pane]');
    var $lines = $root.find('[data-fls-step-line]');

    function setCurrent(stepKey) {
      var currentIndex = order.indexOf(stepKey);

      if (currentIndex === -1) {
        currentIndex = 0;
      }

      $buttons.each(function () {
        var $button = $(this);
        var key = $button.data('fls-step');
        var index = order.indexOf(key);

        $button.removeClass('is-active is-complete is-inactive');

        if (index < currentIndex) {
          $button.addClass('is-complete').attr('aria-current', 'false');
        } else if (index === currentIndex) {
          $button.addClass('is-active').attr('aria-current', 'step');
        } else {
          $button.addClass('is-inactive').attr('aria-current', 'false');
        }
      });

      $lines.each(function () {
        var $line = $(this);
        var lineIndex = Number($line.data('fls-step-line-index'));

        $line.removeClass('is-active is-complete is-inactive');

        if (lineIndex < currentIndex) {
          $line.addClass('is-complete');
        } else if (lineIndex === currentIndex) {
          $line.addClass('is-active');
        } else {
          $line.addClass('is-inactive');
        }
      });

      $panes.each(function () {
        var $pane = $(this);
        var paneKey = $pane.data('fls-step-pane');
        var isMatch = paneKey === stepKey;

        $pane.toggleClass('is-hidden', !isMatch);
        $pane.attr('aria-hidden', isMatch ? 'false' : 'true');
      });

      initPreline();
    }

    $root.on('click', '[data-fls-step], [data-fls-go-step]', function (event) {
      event.preventDefault();
      setCurrent($(this).data('flsStep') || $(this).data('flsGoStep'));
    });

    var initialStep = $root.find('[data-fls-step].is-active').data('flsStep') || 'details';
    setCurrent(initialStep);
  }

  $(document).ready(function () {
    initCheckoutSteps();
  });
})(jQuery);
