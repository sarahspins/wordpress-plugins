(function () {
    'use strict';

    function checkboxes(form) {
        return Array.prototype.slice.call(
            form.querySelectorAll(
                'input[name="ifprog_team_ids[]"]:not(:disabled), ' +
                'input[name="ifprog_level_ids[]"]:not(:disabled)'
            )
        );
    }

    function updateCount(form) {
        var items = checkboxes(form);
        var selected = items.filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        var count = form.querySelector('[data-ifprog-selection-count]');

        if (count) {
            count.textContent = selected + ' of ' + items.length + ' selected';
        }
    }

    function discoveryCheckboxes() {
        return Array.prototype.slice.call(
            document.querySelectorAll(
                'input[name="ifprog_bulk_season_ids[]"][form="ifprog-discovery-bulk-form"]'
            )
        );
    }

    function updateDiscoveryCount() {
        var selected = discoveryCheckboxes().filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        var count = document.querySelector('[data-ifprog-bulk-count]');
        if (count) {
            count.textContent = selected + (selected === 1 ? ' Season selected' : ' Seasons selected');
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ifprog-select]');
        if (!button) return;

        var form = button.closest('form');
        if (!form) return;

        var selectAll = button.getAttribute('data-ifprog-select') === 'all';
        checkboxes(form).forEach(function (checkbox) {
            checkbox.checked = selectAll;
        });
        updateCount(form);
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches(
            'input[name="ifprog_team_ids[]"], input[name="ifprog_level_ids[]"]'
        )) return;

        var form = event.target.closest('form');
        if (form) updateCount(form);
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ifprog-bulk-select]');
        if (!button) return;
        var selectAll = button.getAttribute('data-ifprog-bulk-select') === 'all';
        discoveryCheckboxes().forEach(function (checkbox) {
            checkbox.checked = selectAll;
        });
        updateDiscoveryCount();
    });

    document.addEventListener('change', function (event) {
        if (!event.target.matches('input[name="ifprog_bulk_season_ids[]"]')) return;
        updateDiscoveryCount();
    });

    document.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-ifprog-exclude-form]')) return;
        if (!window.confirm('Hide this Dash Season from the discovery inbox and future suggestions? You can restore it from All Seasons > Excluded.')) {
            event.preventDefault();
        }
    });

    document.addEventListener('submit', function (event) {
        if (!event.target.matches('[data-ifprog-bulk-exclude-form]')) return;
        var selected = discoveryCheckboxes().filter(function (checkbox) {
            return checkbox.checked;
        }).length;
        if (!selected) {
            window.alert('Select at least one Season to exclude.');
            event.preventDefault();
            return;
        }
        if (!window.confirm('Exclude ' + selected + (selected === 1 ? ' Season' : ' Seasons') + ' from discovery? You can restore them from All Seasons > Excluded.')) {
            event.preventDefault();
        }
    });

    document.addEventListener('submit', function (event) {
        var button = event.submitter;
        if (!button || !button.matches('[data-ifprog-discovery-refresh]')) return;
        button.setAttribute('aria-disabled', 'true');
        button.style.pointerEvents = 'none';
        button.textContent = 'Refreshing…';
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ifprog-copy-target]');
        if (!button) return;

        var input = document.getElementById(button.getAttribute('data-ifprog-copy-target'));
        var status = button.parentNode.querySelector('[data-ifprog-copy-status]');
        if (!input) return;

        function copied() {
            if (status) status.textContent = 'Copied!';
            window.setTimeout(function () {
                if (status) status.textContent = '';
            }, 2000);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(copied, function () {
                input.select();
                document.execCommand('copy');
                copied();
            });
            return;
        }

        input.select();
        document.execCommand('copy');
        copied();
    });

    document.querySelectorAll('[data-ifprog-selection-count]').forEach(function (count) {
        var form = count.closest('form');
        if (form) updateCount(form);
    });

    updateDiscoveryCount();
}());
