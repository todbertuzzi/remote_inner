(function () {
    'use strict';

    document.querySelectorAll('[data-invited-dashboard-tabs]').forEach(function (tabs) {
        var buttons = Array.prototype.slice.call(tabs.querySelectorAll('[data-invited-dashboard-tab]'));
        var panels = Array.prototype.slice.call(tabs.querySelectorAll('[data-invited-dashboard-panel]'));

        function activateTab(button, moveFocus) {
            var target = button.getAttribute('data-invited-dashboard-tab');

            buttons.forEach(function (candidate) {
                var isActive = candidate === button;
                candidate.classList.toggle('is-active', isActive);
                candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
                candidate.setAttribute('tabindex', isActive ? '0' : '-1');
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-invited-dashboard-panel') !== target;
            });

            if (moveFocus) {
                button.focus();
            }
        }

        buttons.forEach(function (button, index) {
            button.addEventListener('click', function () {
                activateTab(button, false);
            });

            button.addEventListener('keydown', function (event) {
                var nextIndex;

                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % buttons.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + buttons.length) % buttons.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = buttons.length - 1;
                } else {
                    return;
                }

                event.preventDefault();
                activateTab(buttons[nextIndex], true);
            });
        });
    });
}());
