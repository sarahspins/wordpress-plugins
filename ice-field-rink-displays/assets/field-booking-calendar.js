(function () {
    'use strict';

    function escapeHtml(value) {
        var node = document.createElement('div');
        node.textContent = value == null ? '' : String(value);
        return node.innerHTML;
    }

    function shiftMonth(month, amount) {
        var parts = String(month).split('-');
        var date = new Date(Number(parts[0]), Number(parts[1]) - 1 + amount, 1, 12, 0, 0);
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
    }

    function todayMonth() {
        var now = new Date();
        return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    }

    function init(root) {
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (error) {
            return;
        }

        var grid = root.querySelector('[data-field-grid]');
        var status = root.querySelector('[data-field-status]');
        var monthLabel = root.querySelector('[data-field-month-label]');
        var refreshButton = root.querySelector('[data-field-refresh]');
        var state = { month: config.initialMonth || todayMonth(), loading: false };
        var initial = root.querySelector('[data-field-initial]');
        var initialPayload = null;

        if (initial) {
            try {
                initialPayload = JSON.parse(initial.textContent || 'null');
            } catch (error) {
                initialPayload = null;
            }
        }

        function render(payload) {
            var weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            var html = '';
            weekdays.forEach(function (weekday) {
                html += '<div class="ifrd-field-calendar-weekday">' + escapeHtml(weekday) + '</div>';
            });

            var firstWeekday = Number(payload.firstWeekday) || 0;
            var daysInMonth = Number(payload.daysInMonth) || 0;
            var totalCells = Math.ceil((firstWeekday + daysInMonth) / 7) * 7;
            var today = new Date();
            var todayKey = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

            for (var cell = 0; cell < totalCells; cell += 1) {
                var day = cell - firstWeekday + 1;
                if (day < 1 || day > daysInMonth) {
                    html += '<div class="ifrd-field-calendar-day is-outside" aria-hidden="true"></div>';
                    continue;
                }

                var dateKey = payload.month + '-' + String(day).padStart(2, '0');
                var bookings = payload.bookings && Array.isArray(payload.bookings[dateKey]) ? payload.bookings[dateKey] : [];
                html += '<section class="ifrd-field-calendar-day' + (dateKey === todayKey ? ' is-today' : '') + '">' +
                    '<div class="ifrd-field-calendar-date">' + day + '</div>';
                bookings.forEach(function (booking) {
                    html += '<div class="ifrd-field-calendar-booking">' +
                        '<strong>' + escapeHtml(booking.label || 'Unavailable') + '</strong>' +
                        '<span>' + escapeHtml(booking.time || '') + '</span>' +
                        '</div>';
                });
                html += '</section>';
            }

            grid.innerHTML = html;
            monthLabel.textContent = payload.monthLabel || payload.month;
            status.textContent = (payload.updatedLabel ? 'Last updated: ' + payload.updatedLabel : '') + (payload.warning ? ' • ' + payload.warning : '');
            status.classList.toggle('is-warning', Boolean(payload.warning));
        }

        async function load(force) {
            if (state.loading) return;
            state.loading = true;
            root.classList.add('is-updating');
            status.textContent = force ? 'Refreshing field availability…' : 'Loading field availability…';
            if (refreshButton) refreshButton.disabled = true;

            try {
                var form = new FormData();
                form.append('action', config.action);
                form.append('nonce', config.nonce || '');
                form.append('month', state.month);
                form.append('resource_id', String(config.resourceId || 3));
                if (force) form.append('force', '1');

                var response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: form
                });
                var result = await response.json();
                if (!response.ok || !result.success || !result.data) {
                    throw new Error(result && result.data && result.data.message ? result.data.message : 'Field availability could not be loaded.');
                }
                state.month = result.data.month;
                render(result.data);
            } catch (error) {
                status.textContent = error.message || 'Field availability could not be loaded.';
                status.classList.add('is-warning');
                if (!grid.children.length || grid.querySelector('.ifrd-field-calendar-loading')) {
                    grid.innerHTML = '<div class="ifrd-field-calendar-error">' + escapeHtml(error.message || 'Field availability could not be loaded.') + '</div>';
                }
            } finally {
                state.loading = false;
                root.classList.remove('is-updating');
                if (refreshButton) refreshButton.disabled = false;
            }
        }

        root.querySelector('[data-field-previous]').addEventListener('click', function () {
            state.month = shiftMonth(state.month, -1);
            load(false);
        });
        root.querySelector('[data-field-next]').addEventListener('click', function () {
            state.month = shiftMonth(state.month, 1);
            load(false);
        });
        root.querySelector('[data-field-today]').addEventListener('click', function () {
            state.month = todayMonth();
            load(false);
        });
        if (refreshButton) {
            refreshButton.addEventListener('click', function () { load(true); });
        }

        if (initialPayload && initialPayload.month === state.month) {
            render(initialPayload);
        }
        load(false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ifrd-field-calendar]'), init);
    });
}());
