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

    function batchOverlay() {
        var overlay = document.querySelector('[data-ifprog-batch-overlay]');
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'ifprog-batch-overlay';
        overlay.setAttribute('data-ifprog-batch-overlay', '');
        overlay.innerHTML = '<div class="ifprog-batch-dialog" role="status" aria-live="polite">' +
            '<h2>Preparing Dash data</h2><p data-ifprog-batch-status>Starting…</p>' +
            '<div class="ifprog-batch-track"><span data-ifprog-batch-bar></span></div>' +
            '<p class="ifprog-batch-detail" data-ifprog-batch-detail></p>' +
            '<button type="button" class="button" data-ifprog-batch-close hidden>Close</button></div>';
        document.body.appendChild(overlay);
        overlay.querySelector('[data-ifprog-batch-close]').addEventListener('click', function () {
            overlay.remove();
        });
        return overlay;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = event.submitter;
        if (!window.ifprogPreviewBatch || !button || button.name !== 'ifprog_preview_action') return;
        if (['preview', 'check_season', 'import'].indexOf(button.value) === -1) return;
        if (form.querySelector('input[name="ifprog_batched_preview"]')) return;

        var season = form.querySelector('input[name="ifprog_dash_season_id"]');
        if (!season || !season.value) return;
        event.preventDefault();

        var dropin = form.querySelector('input[name="ifprog_dropin_team_id"]');
        var stages = [
            ['teams', 'Loading Teams'],
            ['leagues', 'Loading Leagues'],
            ['products', 'Loading Products'],
            ['availability', 'Loading registration availability'],
            ['events', 'Loading scheduled Events']
        ];
        if (dropin && dropin.value) stages.push(['dropin', 'Loading the recurring drop-in Team']);

        var overlay = batchOverlay();
        var status = overlay.querySelector('[data-ifprog-batch-status]');
        var detail = overlay.querySelector('[data-ifprog-batch-detail]');
        var bar = overlay.querySelector('[data-ifprog-batch-bar]');
        var close = overlay.querySelector('[data-ifprog-batch-close]');
        bar.style.backgroundColor = '#1473a8';
        var index = 0;
        var eventWeek = 0;

        function runNext() {
            if (index >= stages.length) {
                status.textContent = 'Dash data is ready. Building the protected review…';
                bar.style.width = '100%';
                var batched = document.createElement('input');
                batched.type = 'hidden';
                batched.name = 'ifprog_batched_preview';
                batched.value = '1';
                form.appendChild(batched);
                var action = document.createElement('input');
                action.type = 'hidden';
                action.name = button.name;
                action.value = button.value;
                form.appendChild(action);
                form.submit();
                return;
            }

            status.textContent = stages[index][1] + '…';
            detail.textContent = stages[index][0] === 'events' && eventWeek ? 'Scheduled Events week ' + (eventWeek + 1) : 'Step ' + (index + 1) + ' of ' + stages.length;
            bar.style.width = Math.round((index / stages.length) * 100) + '%';
            var payload = new FormData();
            payload.append('action', 'ifprog_warm_preview_stage');
            payload.append('nonce', window.ifprogPreviewBatch.nonce);
            payload.append('stage', stages[index][0]);
            payload.append('season_id', season.value);
            if (stages[index][0] === 'events') payload.append('event_week', eventWeek);
            if (dropin && dropin.value) payload.append('dropin_team_id', dropin.value);

            fetch(window.ifprogPreviewBatch.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: payload})
                .then(function (response) {
                    var status = response.status;
                    return response.text().then(function (body) {
                        try {
                            return JSON.parse(body);
                        } catch (ignore) {
                            throw new Error('The server returned an unreadable response' + (status ? ' (HTTP ' + status + ')' : '') + '.');
                        }
                    });
                })
                .then(function (response) {
                    if (!response.success) {
                        throw new Error(response.data && response.data.message ? response.data.message : 'This Dash request failed.');
                    }
                    if (stages[index][0] === 'events' && response.data && response.data.more_event_weeks) {
                        eventWeek = parseInt(response.data.event_week || eventWeek + 1, 10);
                    } else {
                        index += 1;
                    }
                    runNext();
                })
                .catch(function (error) {
                    status.textContent = stages[index][1] + ' failed';
                    detail.textContent = error.message + ' No import changes were made.';
                    overlay.classList.add('ifprog-batch-overlay--error');
                    close.hidden = false;
                });
        }
        runNext();
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
