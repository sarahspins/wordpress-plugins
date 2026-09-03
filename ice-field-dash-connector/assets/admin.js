jQuery(function($){
    let lastPayload = null;
    let requestHistory = [];

    function showResult($el, ok, message) {
        $el.removeAttr('hidden').toggleClass('is-error', !ok).toggleClass('is-success', ok).text(message);
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function displayValue(value) {
        if (value === null || typeof value === 'undefined') return '';
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (Array.isArray(value)) {
            if (value.length === 0) return '';
            if (value.every(v => ['string','number','boolean'].includes(typeof v))) return value.join(', ');
            return JSON.stringify(value);
        }
        if (typeof value === 'object') return JSON.stringify(value);
        return String(value);
    }

    function readableKey(key) {
        return String(key || '')
            .replace(/[_-]+/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .replace(/\b\w/g, c => c.toUpperCase());
    }

    function unwrapPayload(payload) {
        if (!payload || typeof payload !== 'object') return {records: [], root: payload};

        const data = Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
        if (Array.isArray(data)) return {records: data, root: payload};
        if (data && typeof data === 'object') return {records: [data], root: payload};
        return {records: [], root: payload};
    }

    function normalizedRecord(item, index) {
        if (!item || typeof item !== 'object') {
            return {id: index + 1, type: '', attributes: {value: item}, relationships: {}, links: {}};
        }

        const attrs = item.attributes && typeof item.attributes === 'object' ? item.attributes : {};
        const direct = {};
        Object.keys(item).forEach(key => {
            if (!['id','type','attributes','relationships','links','meta'].includes(key)) direct[key] = item[key];
        });

        return {
            id: item.id != null ? item.id : index + 1,
            type: item.type || '',
            attributes: Object.assign({}, direct, attrs),
            relationships: item.relationships || {},
            links: item.links || {},
            meta: item.meta || {},
            raw: item
        };
    }

    function chooseColumns(records) {
        const priority = [
            'name','title','desc','description','full_name','first_name','last_name',
            'start','start_time','start_date','end','end_time','status','active',
            'resource_id','league_id','season_id','program_id','level_id'
        ];
        const counts = {};

        records.forEach(record => {
            Object.keys(record.attributes).forEach(key => {
                const val = record.attributes[key];
                if (val === null || typeof val === 'undefined' || typeof val === 'object') return;
                counts[key] = (counts[key] || 0) + 1;
            });
        });

        const available = Object.keys(counts);
        const ordered = [];
        priority.forEach(key => {
            if (available.includes(key) && !ordered.includes(key)) ordered.push(key);
        });
        available
            .sort((a,b) => counts[b] - counts[a])
            .forEach(key => {
                if (!ordered.includes(key)) ordered.push(key);
            });

        return ordered.slice(0, 7);
    }

    function recordLabel(record) {
        const a = record.attributes || {};
        const candidates = ['name','title','desc','description','full_name'];
        for (const key of candidates) {
            if (a[key]) return displayValue(a[key]);
        }
        const first = a.first_name || a.firstName || '';
        const last = a.last_name || a.lastName || '';
        if ((first + last).trim()) return (first + ' ' + last).trim();
        return (record.type ? record.type + ' ' : 'Record ') + record.id;
    }

    function renderSummary(payload, records) {
        const meta = payload && payload.meta ? payload.meta : {};
        const links = payload && payload.links ? payload.links : {};
        const cards = [
            ['Records', records.length],
            ['Type', records[0] && records[0].type ? records[0].type : 'Mixed / unknown'],
            ['Pagination', links.next ? 'More available' : 'Single response']
        ];
        if (meta && Object.keys(meta).length) cards.push(['Metadata', Object.keys(meta).length + ' fields']);

        $('#ifdc-response-summary').html(cards.map(card =>
            '<div class="ifdc-summary-item"><span>' + escapeHtml(card[0]) + '</span><strong>' + escapeHtml(card[1]) + '</strong></div>'
        ).join(''));
    }

    function renderBrowser(payload) {
        const unwrapped = unwrapPayload(payload);
        const records = unwrapped.records.map(normalizedRecord);
        renderSummary(payload, records);

        const $wrap = $('#ifdc-table-wrap');
        if (!records.length) {
            $wrap.html('<div class="ifdc-empty-state"><span class="dashicons dashicons-info-outline"></span><h3>No collection records found</h3><p>Use Raw JSON to inspect the response structure.</p></div>');
            $('#ifdc-browser-title').text('Response received');
            return;
        }

        const columns = chooseColumns(records);
        let html = '<div class="ifdc-table-tools"><input type="search" id="ifdc-table-search" placeholder="Filter these records…"><span>' + records.length + ' record' + (records.length === 1 ? '' : 's') + '</span></div>';
        html += '<table class="widefat striped ifdc-data-table"><thead><tr><th>ID</th><th>Type</th>';
        columns.forEach(key => html += '<th>' + escapeHtml(readableKey(key)) + '</th>');
        html += '<th></th></tr></thead><tbody>';

        records.forEach((record, index) => {
            const search = [record.id, record.type].concat(Object.values(record.attributes).map(displayValue)).join(' ').toLowerCase();
            html += '<tr data-search="' + escapeHtml(search) + '">';
            html += '<td><code>' + escapeHtml(record.id) + '</code></td>';
            html += '<td>' + escapeHtml(record.type) + '</td>';
            columns.forEach(key => {
                let value = displayValue(record.attributes[key]);
                if (value.length > 140) value = value.slice(0, 137) + '…';
                html += '<td>' + escapeHtml(value) + '</td>';
            });
            html += '<td><button type="button" class="button button-small ifdc-inspect" data-index="' + index + '">Inspect</button></td></tr>';
        });
        html += '</tbody></table>';

        $wrap.html(html);
        $wrap.data('records', records);
        $('#ifdc-browser-title').text(records.length === 1 ? recordLabel(records[0]) : (records[0].type ? readableKey(records[0].type) : 'API records'));

        $('#ifdc-table-search').on('input', function(){
            const needle = $(this).val().toLowerCase();
            $('.ifdc-data-table tbody tr').each(function(){
                $(this).toggle($(this).attr('data-search').includes(needle));
            });
        });
    }

    function renderObjectTable(obj) {
        if (!obj || typeof obj !== 'object' || !Object.keys(obj).length) return '<p class="description">None</p>';
        let html = '<dl class="ifdc-record-grid">';
        Object.keys(obj).sort().forEach(key => {
            html += '<dt>' + escapeHtml(readableKey(key)) + '</dt><dd><code>' + escapeHtml(displayValue(obj[key])) + '</code></dd>';
        });
        html += '</dl>';
        return html;
    }

    function inspectRecord(index) {
        const records = $('#ifdc-table-wrap').data('records') || [];
        const record = records[index];
        if (!record) return;

        $('#ifdc-record-title').text(recordLabel(record));
        let html = '<div class="ifdc-record-meta"><span><strong>ID:</strong> ' + escapeHtml(record.id) + '</span><span><strong>Type:</strong> ' + escapeHtml(record.type || 'Unknown') + '</span></div>';
        html += '<h3>Attributes</h3>' + renderObjectTable(record.attributes);
        const relationshipKeys = Object.keys(record.relationships || {});
        if (relationshipKeys.length && record.type && record.id) {
            html += '<div class="ifdc-related-actions"><strong>Browse relationships:</strong><div class="ifdc-relationship-buttons">';
            relationshipKeys.forEach(function(name){
                html += '<button type="button" class="button ifdc-follow-endpoint" data-endpoint="' + escapeHtml(record.type) + '/' + escapeHtml(record.id) + '/' + escapeHtml(name) + '">' + escapeHtml(readableKey(name)) + '</button>';
            });
            html += '</div></div>';
        }
        if (record.type === 'teams' && record.id) {
            html += '<div class="ifdc-related-actions ifdc-featured-relationship"><strong>Common team paths:</strong> ' +
                '<button type="button" class="button button-primary ifdc-follow-endpoint" data-endpoint="teams/' + escapeHtml(record.id) + '/registeredCustomers">Registered Customers</button>' +
                '<button type="button" class="button ifdc-follow-endpoint" data-endpoint="teams/' + escapeHtml(record.id) + '?include=registeredCustomers">Team + Roster</button>' +
                '</div>';
        }
        html += '<h3>Relationships</h3>' + renderObjectTable(record.relationships);
        html += '<h3>Links</h3>' + renderObjectTable(record.links);
        html += '<details><summary>Raw record JSON</summary><pre class="ifdc-json ifdc-json-small">' + escapeHtml(JSON.stringify(record.raw, null, 2)) + '</pre></details>';
        if (record.type === 'teams' && record.id && IFDC.canDeleteTeams) {
            const deletePhrase = 'DELETE TEAM #' + record.id;
            html += '<div class="ifdc-danger-zone" data-team-id="' + escapeHtml(record.id) + '">' +
                '<h3>Delete this team</h3>' +
                '<p><strong>Permanent action:</strong> the connector will freshly verify this exact team and block deletion if registered customers or assigned schedule events are found.</p>' +
                '<p>Temporarily enable <strong>Registration → Delete</strong> for the Dash API token, then remove that permission when finished.</p>' +
                '<label>Type <code>' + escapeHtml(deletePhrase) + '</code> to confirm' +
                    '<input type="text" class="regular-text code ifdc-delete-team-confirmation" autocomplete="off" data-expected="' + escapeHtml(deletePhrase) + '">' +
                '</label>' +
                '<p><button type="button" class="button ifdc-delete-team" disabled>Permanently Delete Team</button></p>' +
                '<div class="ifdc-result ifdc-delete-team-result" hidden></div>' +
                '</div>';
        }

        $('#ifdc-record-content').html(html);
        $('#ifdc-record-panel').removeAttr('hidden')[0].scrollIntoView({behavior:'smooth', block:'start'});
    }

    function addHistory(endpoint, query, response) {
        requestHistory.unshift({
            endpoint: endpoint,
            query: query,
            time: new Date().toLocaleTimeString(),
            status: response && response._ifdc ? response._ifdc.status : ''
        });
        requestHistory = requestHistory.slice(0, 8);

        $('#ifdc-history-list').html(requestHistory.map((item, index) =>
            '<button type="button" class="ifdc-history-item" data-index="' + index + '">' +
                '<strong>' + escapeHtml(item.endpoint) + '</strong>' +
                '<span>' + escapeHtml(item.time) + (item.status ? ' • HTTP ' + escapeHtml(item.status) : '') + '</span>' +
            '</button>'
        ).join(''));
    }

    $('#ifdc-test').on('click', function(){
        const $button = $(this);
        const $result = $('#ifdc-test-result');
        $button.prop('disabled', true).text('Testing…');

        $.post(IFDC.ajax, {
            action: 'ifdc_test_connection',
            nonce: IFDC.nonce
        }).done(function(response){
            if (response.success) {
                showResult($result, true, 'Connected successfully to Dash for company “' + response.data.company + '”.');
            } else {
                showResult($result, false, response.data && response.data.message ? response.data.message : 'Connection failed.');
            }
        }).fail(function(xhr){
            showResult($result, false, 'Connection request failed (' + xhr.status + ').');
        }).always(function(){
            $button.prop('disabled', false).text('Test Connection');
        });
    });

    $(document).on('click', '.ifdc-object-link', function(){
        $('.ifdc-object-link').removeClass('is-active');
        $(this).addClass('is-active');
        $('#ifdc-endpoint').val(String($(this).data('endpoint') || ''));
        $('#ifdc-query').val('');
        $('#ifdc-run').trigger('click');
    });

    $('#ifdc-preset').on('change', function(){
        const $option = $(this).find(':selected');
        $('#ifdc-endpoint').val($option.data('endpoint') || '');
        $('#ifdc-query').val($option.data('query') || '');
        $('#ifdc-preset-description').text($option.data('description') || '');
    });

    $('#ifdc-run').on('click', function(){
        const $button = $(this);
        const $output = $('#ifdc-explorer-output');
        const $meta = $('#ifdc-explorer-meta');
        const endpoint = $('#ifdc-endpoint').val().trim();
        const query = $('#ifdc-query').val();

        if (!endpoint) {
            showResult($meta, false, 'Enter an endpoint before running the request.');
            return;
        }
        if (/\{[^}]+\}/.test(endpoint)) {
            showResult($meta, false, 'Replace the placeholder in the endpoint before running the request.');
            return;
        }

        $button.prop('disabled', true).text('Running…');
        $output.text('Loading…');
        $('#ifdc-browser-title').text('Loading…');
        $('#ifdc-table-wrap').html('<div class="ifdc-empty-state"><span class="spinner is-active"></span><h3>Requesting data from Dash</h3></div>');
        $('#ifdc-record-panel').attr('hidden', true);

        $.post(IFDC.ajax, {
            action: 'ifdc_explore',
            nonce: IFDC.nonce,
            endpoint: endpoint,
            query: query,
            force: 1
        }).done(function(response){
            if (response.success) {
                lastPayload = response.data.data;
                $output.text(JSON.stringify(lastPayload, null, 2));
                renderBrowser(lastPayload);
                const m = response.data._ifdc || {};
                showResult($meta, true, 'HTTP ' + (m.status || 200) + ' • ' + (m.response_ms || 0) + ' ms • ' + (m.url || ''));
                addHistory(endpoint, query, response.data);
            } else {
                const details = response.data && response.data.details ? '\n\n' + JSON.stringify(response.data.details, null, 2) : '';
                $output.text((response.data && response.data.message ? response.data.message : 'Request failed.') + details);
                $('#ifdc-table-wrap').html('<div class="ifdc-empty-state is-error"><span class="dashicons dashicons-warning"></span><h3>Request failed</h3><p>Open Raw JSON for the returned error details.</p></div>');
                $('#ifdc-browser-title').text('Request failed');
                showResult($meta, false, response.data && response.data.message ? response.data.message : 'Request failed.');
            }
        }).fail(function(xhr){
            $output.text('Request failed (' + xhr.status + ').');
            $('#ifdc-table-wrap').html('<div class="ifdc-empty-state is-error"><span class="dashicons dashicons-warning"></span><h3>Request failed</h3></div>');
            $('#ifdc-browser-title').text('Request failed');
            showResult($meta, false, 'Request failed (' + xhr.status + ').');
        }).always(function(){
            $button.prop('disabled', false).text('Run Request');
        });
    });

    $('#ifdc-clear').on('click', function(){
        $('#ifdc-endpoint').val('');
        $('#ifdc-query').val('');
        $('#ifdc-preset').val('custom');
        $('#ifdc-preset-description').text('Enter any read-only Dash/DaySmart endpoint.');
        $('#ifdc-explorer-meta').attr('hidden', true);
    });

    $(document).on('click', '.ifdc-inspect', function(){
        inspectRecord(parseInt($(this).data('index'), 10));
    });

    $(document).on('click', '.ifdc-follow-endpoint', function(){
        const value = String($(this).data('endpoint') || '');
        const parts = value.split('?');
        $('#ifdc-endpoint').val(parts[0]);
        $('#ifdc-query').val(parts[1] ? parts[1].replace(/&/g, '\n') : '');
        $('#ifdc-run').trigger('click');
    });

    $(document).on('input', '.ifdc-delete-team-confirmation', function(){
        const $input = $(this);
        const matches = $input.val() === String($input.data('expected') || '');
        $input.closest('.ifdc-danger-zone').find('.ifdc-delete-team').prop('disabled', !matches);
    });

    $(document).on('click', '.ifdc-delete-team', function(){
        const $button = $(this);
        const $zone = $button.closest('.ifdc-danger-zone');
        const teamId = parseInt($zone.data('team-id'), 10);
        const $input = $zone.find('.ifdc-delete-team-confirmation');
        const confirmation = String($input.val() || '');
        const expected = String($input.data('expected') || '');
        const $result = $zone.find('.ifdc-delete-team-result');
        if (!teamId || confirmation !== expected) {
            showResult($result, false, 'The confirmation text must exactly match the displayed team ID.');
            return;
        }
        if (!window.confirm('Permanently delete Dash team #' + teamId + '? This cannot be undone.')) return;

        $button.prop('disabled', true).text('Verifying and deleting…');
        $input.prop('disabled', true);
        showResult($result, true, 'Freshly verifying the team, roster, and schedule-event assignments…');
        $.post(IFDC.ajax, {
            action: 'ifdc_delete_team',
            nonce: IFDC.nonce,
            team_id: teamId,
            confirmation: confirmation
        }).done(function(response){
            if (response.success) {
                showResult($result, true, response.data.message);
                $zone.addClass('is-deleted');
                $button.text('Team Deleted').prop('disabled', true);
                $input.prop('disabled', true);
                $('#ifdc-record-title').text('Team deleted');
            } else {
                showResult($result, false, response.data && response.data.message ? response.data.message : 'The team was not deleted.');
                $input.prop('disabled', false);
                $button.text('Permanently Delete Team').prop('disabled', confirmation !== expected);
            }
        }).fail(function(xhr){
            const response = xhr.responseJSON;
            const message = response && response.data && response.data.message
                ? response.data.message
                : 'The deletion request failed (' + xhr.status + '). Nothing should be retried until the team is checked directly in Dash.';
            showResult($result, false, message);
            $input.prop('disabled', false);
            $button.text('Permanently Delete Team').prop('disabled', confirmation !== expected);
        });
    });

    $('#ifdc-close-record').on('click', function(){
        $('#ifdc-record-panel').attr('hidden', true);
    });

    $(document).on('click', '.ifdc-history-item', function(){
        const item = requestHistory[parseInt($(this).data('index'), 10)];
        if (!item) return;
        $('#ifdc-endpoint').val(item.endpoint);
        $('#ifdc-query').val(item.query);
    });

    $('[data-ifdc-view]').on('click', function(){
        const view = $(this).data('ifdc-view');
        $('[data-ifdc-view]').removeClass('is-active');
        $(this).addClass('is-active');
        $('#ifdc-browser-view').prop('hidden', view !== 'browser');
        $('#ifdc-raw-view').prop('hidden', view !== 'raw');
    });

    $('#ifdc-copy-json').on('click', function(){
        const text = lastPayload ? JSON.stringify(lastPayload, null, 2) : '';
        if (!text) return;
        navigator.clipboard.writeText(text).then(function(){
            $('#ifdc-copy-status').text('Copied').fadeIn().delay(1200).fadeOut();
        });
    });

    $('#ifdc-clear-schema').on('click', function(){
        if (!window.confirm('Clear the schema learned from Explorer requests?')) return;
        const $button = $(this);
        $button.prop('disabled', true);
        $.post(IFDC.ajax, {action:'ifdc_clear_schema', nonce:IFDC.nonce}).done(function(response){
            if (response.success) {
                showResult($('#ifdc-schema-message'), true, response.data.message);
                window.setTimeout(function(){ window.location.reload(); }, 500);
            } else {
                showResult($('#ifdc-schema-message'), false, response.data && response.data.message ? response.data.message : 'Unable to clear schema.');
            }
        }).always(function(){ $button.prop('disabled', false); });
    });

    // Event Assignment ------------------------------------------------------
    let assignmentEvents = [];
    let assignmentTeam = null;
    let assignmentRunning = false;
    let assignmentStopRequested = false;
    let assignmentAutoTeamQuery = '';
    let assignmentAutoCapacity = '';
    let assignmentOverlayReturnFocus = null;
    let assignmentOverlayItemLabel = 'event';
    let standardAssignmentBundle = null;

    function assignmentMonthDetails(value) {
        const match = /^(\d{4})-(\d{2})$/.exec(String(value || ''));
        if (!match) return null;
        const year = parseInt(match[1], 10);
        const month = parseInt(match[2], 10);
        if (month < 1 || month > 12) return null;
        const lastDay = new Date(year, month, 0).getDate();
        return {
            start: match[1] + '-' + match[2] + '-01',
            end: match[1] + '-' + match[2] + '-' + String(lastDay).padStart(2, '0'),
            label: new Date(year, month - 1, 1).toLocaleDateString([], {month:'long', year:'numeric'})
        };
    }

    function assignmentDateDetails() {
        if ($('#ifdc-assignment-date-mode').val() !== 'custom') {
            return assignmentMonthDetails($('#ifdc-assignment-month').val());
        }
        const start = String($('#ifdc-assignment-start').val() || '');
        const end = String($('#ifdc-assignment-end').val() || '');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || !/^\d{4}-\d{2}-\d{2}$/.test(end)) return null;
        return {start:start, end:end, label:start === end ? start : start + ' through ' + end};
    }

    function updateAssignmentDateMode() {
        const custom = $('#ifdc-assignment-date-mode').val() === 'custom';
        $('#ifdc-assignment-month-field').prop('hidden', custom);
        $('#ifdc-assignment-custom-range').prop('hidden', !custom);
    }

    function populateAssignmentMonthSearch(force) {
        const details = assignmentMonthDetails($('#ifdc-assignment-month').val());
        if (!details) return;
        const $query = $('#ifdc-assignment-team-query');
        const current = String($query.val() || '').trim();
        if (force || !current || current === assignmentAutoTeamQuery) {
            $query.val(details.label);
            assignmentAutoTeamQuery = details.label;
        }
    }

    function assignmentCapacityValue() {
        const raw = String($('#ifdc-assignment-capacity').val() || '').trim();
        if (raw === '') return null;
        const value = parseInt(raw, 10);
        return Number.isNaN(value) ? null : Math.min(9999, Math.max(0, value));
    }

    function assignmentEventNameValue() {
        return String($('#ifdc-assignment-event-name').val() || '').trim();
    }

    function assignmentNewEventTypeValue() {
        const raw = String($('#ifdc-assignment-new-event-type').val() || '').trim();
        return raw === '' ? null : raw;
    }

    function suggestedAssignmentCapacity() {
        const context = ($('#ifdc-assignment-name').val() || '') + ' ' + (assignmentTeam ? assignmentTeam.label : '');
        if (String($('#ifdc-assignment-type').val() || '') === '10') return 250;
        if (/hockey|stick\s*(?:&|and)\s*puck/i.test(context)) return 25;
        if (/freestyle/i.test(context)) return 20;
        return null;
    }

    function populateAssignmentCapacity(force) {
        const suggested = suggestedAssignmentCapacity();
        const $capacity = $('#ifdc-assignment-capacity');
        const current = String($capacity.val() || '').trim();
        if (force || !current || current === String(assignmentAutoCapacity)) {
            $capacity.val(suggested === null ? '' : suggested);
            assignmentAutoCapacity = suggested === null ? '' : String(suggested);
        }
    }

    function showAssignmentOverlay(total, operation, itemLabel) {
        assignmentStopRequested = false;
        assignmentOverlayItemLabel = itemLabel || 'event';
        assignmentOverlayReturnFocus = document.activeElement;
        const $overlay = $('#ifdc-assignment-overlay');
        $overlay.removeClass('is-complete has-errors').removeAttr('hidden');
        $overlay.find('.ifdc-assignment-dialog-icon').removeClass('dashicons-yes-alt dashicons-warning').addClass('dashicons-update');
        $('#ifdc-assignment-dialog-title').text('Updating ' + total + ' ' + assignmentOverlayItemLabel + (total === 1 ? '' : 's') + '…');
        $('#ifdc-assignment-dialog-destination').text(operation);
        $('#ifdc-assignment-progress-bar').css('width', '0%');
        $('.ifdc-progress-track').attr('aria-valuenow', 0);
        $('#ifdc-assignment-progress-percent').text('0%');
        $('#ifdc-assignment-progress-count').text('0 of ' + total + ' ' + assignmentOverlayItemLabel + (total === 1 ? '' : 's') + ' reviewed');
        $('#ifdc-assignment-dialog-status').text('Preparing the first batch…');
        $('#ifdc-assignment-dialog-warning').text('Keep this page open while Dash is being updated and each event is verified.');
        $('#ifdc-close-assignment-overlay')
            .removeAttr('hidden')
            .prop('disabled', false)
            .removeClass('button-primary')
            .addClass('button-secondary')
            .text('Stop Working');
        $('body').addClass('ifdc-assignment-overlay-open');
        $overlay.find('.ifdc-assignment-dialog').attr('tabindex', '-1').trigger('focus');
    }

    function updateAssignmentOverlay(completed, total, batch, batchTotal, status) {
        const percent = total ? Math.min(100, Math.round((completed / total) * 100)) : 0;
        $('#ifdc-assignment-progress-bar').css('width', percent + '%');
        $('.ifdc-progress-track').attr('aria-valuenow', percent);
        $('#ifdc-assignment-progress-percent').text(percent + '%');
        $('#ifdc-assignment-progress-count').text(completed + ' of ' + total + ' ' + assignmentOverlayItemLabel + (total === 1 ? '' : 's') + ' reviewed');
        $('#ifdc-assignment-dialog-status').text(status || ('Updating batch ' + batch + ' of ' + batchTotal + '…'));
    }

    function finishAssignmentOverlay(totals, total, completed, stopped) {
        const $overlay = $('#ifdc-assignment-overlay');
        const hasErrors = totals.errors.length > 0;
        $overlay.addClass('is-complete').toggleClass('has-errors', hasErrors);
        $overlay.find('.ifdc-assignment-dialog-icon').removeClass('dashicons-update').addClass(stopped ? 'dashicons-controls-pause' : (hasErrors ? 'dashicons-warning' : 'dashicons-yes-alt'));
        const finalStatus = stopped ? 'Stopped safely after the current batch' : (hasErrors ? 'Finished with some items needing attention' : 'Update complete');
        updateAssignmentOverlay(completed, total, 1, 1, finalStatus);
        $('#ifdc-assignment-dialog-title').text(stopped ? 'Update stopped' : (hasErrors ? 'Update finished with warnings' : 'Update complete'));
        let summary = totals.updated.length + ' updated';
        if (totals.skipped.length) summary += ' • ' + totals.skipped.length + ' skipped';
        if (totals.errors.length) summary += ' • ' + totals.errors.length + ' failed';
        if (stopped && completed < total) summary += ' • ' + (total - completed) + ' not processed';
        $('#ifdc-assignment-dialog-warning').text(summary);
        $('#ifdc-close-assignment-overlay')
            .prop('disabled', false)
            .removeClass('button-secondary')
            .addClass('button-primary')
            .text('Completed')
            .trigger('focus');
    }

    function closeAssignmentOverlay() {
        if (assignmentRunning) {
            assignmentStopRequested = true;
            $('#ifdc-close-assignment-overlay').prop('disabled', true).text('Stopping…');
            $('#ifdc-assignment-dialog-status').text('Stopping safely after the current batch finishes…');
            $('#ifdc-assignment-dialog-warning').text('No additional batches will be started. The current batch will still be verified.');
            return;
        }
        $('#ifdc-assignment-overlay').attr('hidden', true);
        $('body').removeClass('ifdc-assignment-overlay-open');
        if (assignmentOverlayReturnFocus && typeof assignmentOverlayReturnFocus.focus === 'function') assignmentOverlayReturnFocus.focus();
    }

    function formatAssignmentDate(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString([], {weekday:'short', month:'short', day:'numeric', year:'numeric'}) +
            ' at ' + date.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
    }

    function selectedAssignmentIds() {
        return $('.ifdc-assignment-event:checked').map(function(){ return parseInt($(this).val(), 10); }).get().filter(Boolean);
    }

    function updateAssignmentControls() {
        const count = selectedAssignmentIds().length;
        const eventName = assignmentEventNameValue();
        const eventType = assignmentNewEventTypeValue();
        $('#ifdc-assignment-selected-count').text(count + ' event' + (count === 1 ? '' : 's') + ' selected');
        let summary = assignmentTeam ?
            'Destination: ' + assignmentTeam.label + (assignmentCapacityValue() === null ? '' : ' • Capacity: ' + assignmentCapacityValue()) :
            (assignmentCapacityValue() === null ? 'Existing class assignments and capacities preserved' : 'Capacity only: ' + assignmentCapacityValue() + ' • Existing class assignments preserved');
        if (eventName) summary += ' • Event name: ' + eventName;
        if (eventType !== null) summary += ' • Event type: ' + eventType;
        $('#ifdc-assignment-target-summary').text(summary);
        $('#ifdc-apply-event-assignment').prop('disabled', assignmentRunning || !count || (!assignmentTeam && assignmentCapacityValue() === null && !eventName && eventType === null));
    }

    function renderAssignmentEvents(events) {
        assignmentEvents = Array.isArray(events) ? events : [];
        const desiredCapacity = assignmentCapacityValue();
        const desiredName = assignmentEventNameValue();
        const desiredEventType = assignmentNewEventTypeValue();
        const visibleEvents = assignmentEvents.filter(function(event){
            const teamNeedsChange = assignmentTeam && parseInt(event.team_id || 0, 10) !== assignmentTeam.id;
            const capacityNeedsChange = desiredCapacity !== null && parseInt(event.capacity || 0, 10) !== desiredCapacity;
            const nameNeedsChange = desiredName && String(event.name || '') !== desiredName;
            const eventTypeNeedsChange = desiredEventType !== null && String(event.event_type_id || '') !== desiredEventType;
            return !!teamNeedsChange || capacityNeedsChange || !!nameNeedsChange || eventTypeNeedsChange;
        });
        const $wrap = $('#ifdc-assignment-events');
        if (!visibleEvents.length) {
            const text = assignmentEvents.length && (assignmentTeam || desiredCapacity !== null || desiredName || desiredEventType !== null) ?
                'Every matching event already has the requested destination, capacity, event name, and event type.' :
                'Try a different date range, name, or event type.';
            $wrap.html('<div class="ifdc-empty-state"><span class="dashicons dashicons-search"></span><h3>No events need attention</h3><p>' + escapeHtml(text) + '</p></div>');
            $('#ifdc-select-all-events, #ifdc-deselect-all-events').prop('disabled', true);
            updateAssignmentControls();
            return;
        }

        let html = '<div class="ifdc-assignment-table-wrap"><table class="widefat striped ifdc-assignment-table"><thead><tr>' +
            '<td class="check-column"></td><th>Date &amp; time</th><th>Event</th><th>Rink / resource</th><th>Capacity</th><th>Current assignment</th></tr></thead><tbody>';
        visibleEvents.forEach(function(event){
            const assignedElsewhere = !!event.team_id;
            const sameDestination = assignmentTeam && assignedElsewhere && parseInt(event.team_id, 10) === assignmentTeam.id;
            const capacityNeedsChange = desiredCapacity !== null && parseInt(event.capacity || 0, 10) !== desiredCapacity;
            const nameNeedsChange = desiredName && String(event.name || '') !== desiredName;
            const eventTypeNeedsChange = desiredEventType !== null && String(event.event_type_id || '') !== desiredEventType;
            const selectable = !!assignmentTeam || capacityNeedsChange || !!nameNeedsChange || eventTypeNeedsChange;
            let assignmentStatus = '<span class="ifdc-status is-ready">Unassigned</span>';
            if (sameDestination) {
                assignmentStatus = '<span class="ifdc-status is-ready">Destination already assigned</span>';
            } else if (assignedElsewhere && assignmentTeam) {
                assignmentStatus = '<span class="ifdc-status is-warning">Will replace class/team #' + escapeHtml(event.team_id) + '</span>';
            } else if (assignedElsewhere) {
                assignmentStatus = '<span class="ifdc-status is-ready">Class/team #' + escapeHtml(event.team_id) + ' preserved</span>';
            }
            let capacityStatus = event.capacity === null ? '—' : escapeHtml(event.capacity);
            if (desiredCapacity !== null && parseInt(event.capacity || 0, 10) !== desiredCapacity) {
                capacityStatus = '<span class="ifdc-status is-warning">' + escapeHtml(event.capacity === null ? '—' : event.capacity) + ' → ' + escapeHtml(desiredCapacity) + '</span>';
            } else if (desiredCapacity !== null) {
                capacityStatus = '<span class="ifdc-status is-ready">' + escapeHtml(desiredCapacity) + '</span>';
            }
            html += '<tr data-event-id="' + escapeHtml(event.id) + '">' +
                '<th class="check-column"><input type="checkbox" class="ifdc-assignment-event" value="' + escapeHtml(event.id) + '" ' + (selectable ? '' : 'disabled') + '></th>' +
                '<td>' + escapeHtml(formatAssignmentDate(event.start)) + '</td>' +
                '<td><strong>' + escapeHtml(nameNeedsChange ? event.name + ' → ' + desiredName : event.name) + '</strong><br><code>#' + escapeHtml(event.id) + '</code> <span class="description">Type ' + escapeHtml(eventTypeNeedsChange ? (event.event_type_id || '—') + ' → ' + desiredEventType : (event.event_type_id || '—')) + '</span></td>' +
                '<td>' + (event.resource_id ? 'Resource #' + escapeHtml(event.resource_id) : '—') + '</td>' +
                '<td>' + capacityStatus + '</td>' +
                '<td>' + assignmentStatus + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        $wrap.html(html);
        $('#ifdc-select-all-events, #ifdc-deselect-all-events').prop('disabled', false);
        updateAssignmentControls();
    }

    function assignmentTeamLabel(team) {
        const parts = [];
        if (team.season_name) parts.push(team.season_name);
        else if (team.season_id) parts.push('Season #' + team.season_id);
        if (team.level_name) parts.push(team.level_name);
        else if (team.level_id) parts.push('Level #' + team.level_id);
        parts.push(team.name + ' (#' + team.id + ')');
        return parts.join(' → ');
    }

    function normalizedAssignmentMatchText(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/&/g, ' and ')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim()
            .replace(/\s+/g, ' ');
    }

    function assignmentTeamMatchScore(team) {
        const eventName = normalizedAssignmentMatchText($('#ifdc-assignment-name').val());
        const teamName = normalizedAssignmentMatchText(team.name);
        const levelName = normalizedAssignmentMatchText(team.level_name);
        const seasonName = normalizedAssignmentMatchText(team.season_name);
        const month = assignmentMonthDetails($('#ifdc-assignment-month').val());
        const monthLabel = normalizedAssignmentMatchText(month ? month.label : '');
        let score = 0;

        if (eventName) {
            if (teamName === eventName) score += 320;
            else if (teamName.endsWith(' ' + eventName)) score += 260;
            else if (teamName.includes(eventName)) score += 210;

            const tokens = eventName.split(' ').filter(function(token){ return token.length > 1; });
            const matched = tokens.filter(function(token){ return teamName.split(' ').includes(token); }).length;
            if (tokens.length) score += Math.round((matched / tokens.length) * 90);

            const eventIsHockey = /hockey|stick and puck/.test(eventName);
            const teamIsHockey = /hockey|stick and puck/.test(teamName);
            const eventIsFreestyle = /freestyle/.test(eventName);
            const teamIsFreestyle = /freestyle/.test(teamName);
            if (eventIsHockey && teamIsHockey) score += 45;
            if (eventIsFreestyle && teamIsFreestyle) score += 45;
            if ((eventIsHockey && teamIsFreestyle) || (eventIsFreestyle && teamIsHockey)) score -= 180;
        }

        if (monthLabel) {
            if (teamName.startsWith(monthLabel + ' ')) score += 45;
            if (levelName.includes(monthLabel)) score += 60;
            const year = monthLabel.match(/\b\d{4}\b/);
            if (year && seasonName.includes(year[0])) score += 15;
        }
        if (team.inactive) score -= 500;
        return score;
    }

    function bestAssignmentTeamIndex(teams) {
        if (teams.length === 1 && !teams[0].inactive) return 0;
        const ranked = teams.map(function(team, index){ return {index:index, score:assignmentTeamMatchScore(team)}; })
            .sort(function(a, b){ return b.score - a.score; });
        if (!ranked.length || ranked[0].score < 120) return -1;
        if (ranked.length > 1 && ranked[0].score - ranked[1].score < 20) return -1;
        return ranked[0].index;
    }

    function renderAssignmentTeams(teams) {
        const $wrap = $('#ifdc-assignment-team-results');
        if (!teams.length) {
            $wrap.html('<p class="description">No matching classes found.</p>');
            return;
        }
        const bestIndex = bestAssignmentTeamIndex(teams);
        $wrap.html('<div class="ifdc-team-choices">' + teams.map(function(team, index){
            const label = assignmentTeamLabel(team);
            const isBest = index === bestIndex;
            return '<label class="ifdc-team-choice' + (team.inactive ? ' is-inactive' : '') + (isBest ? ' is-best-match' : '') + '">' +
                '<input type="radio" name="ifdc-assignment-team" value="' + escapeHtml(team.id) + '" data-label="' + escapeHtml(label) + '" ' + (team.inactive ? 'disabled' : '') + (isBest ? ' checked' : '') + '>' +
                '<span><strong>' + escapeHtml(team.name) + (isBest ? ' <em class="ifdc-best-match">Best match</em>' : '') + '</strong><small>' + escapeHtml(label) + (team.inactive ? ' — Inactive' : '') + '</small></span>' +
                '</label>';
        }).join('') + '</div>');

        if (bestIndex >= 0) {
            const bestTeam = teams[bestIndex];
            assignmentTeam = {id: parseInt(bestTeam.id, 10), label: assignmentTeamLabel(bestTeam)};
            $('#ifdc-clear-assignment-team').prop('disabled', false);
            populateAssignmentCapacity(false);
            renderAssignmentEvents(assignmentEvents);
        } else {
            assignmentTeam = null;
            $('#ifdc-clear-assignment-team').prop('disabled', true);
            renderAssignmentEvents(assignmentEvents);
        }
    }

    function standardTargetLabel(target) {
        if (!target) return 'No confident destination found';
        const parts = [];
        if (target.season_name) parts.push(target.season_name);
        if (target.level_name) parts.push(target.level_name);
        parts.push(target.name + ' (#' + target.id + ')');
        return parts.join(' → ');
    }

    function selectedStandardGroups() {
        if (!standardAssignmentBundle) return [];
        const selected = $('.ifdc-standard-group:checked').map(function(){ return String($(this).val()); }).get();
        return standardAssignmentBundle.groups.filter(function(group){ return selected.includes(String(group.key)); });
    }

    function actionableStandardEvents(group) {
        return (group.events || []).filter(function(event){
            return !!event.assignment_update || (!!group.rename_enabled && String(event.name || '') !== String(group.new_event_name || ''));
        });
    }

    function updateStandardControls() {
        const groups = selectedStandardGroups();
        const total = groups.reduce(function(sum, group){ return sum + actionableStandardEvents(group).length; }, 0);
        $('#ifdc-apply-standard-assignments').prop('disabled', assignmentRunning || !groups.length || !total || groups.some(function(group){ return !group.target; }));
    }

    function renderStandardAssignmentBundle(bundle) {
        standardAssignmentBundle = bundle;
        const $preview = $('#ifdc-standard-assignment-preview');
        if (!bundle || !Array.isArray(bundle.groups)) {
            $preview.html('<div class="ifdc-empty-state"><h3>Unable to build the monthly preview</h3></div>');
            updateStandardControls();
            return;
        }

        let html = '<div class="ifdc-standard-groups">';
        bundle.groups.forEach(function(group){
            group.rename_enabled = !!group.automate_name;
            group.new_event_name = group.event_name || group.name;
            const canSelect = !!group.target && (group.update_count > 0 || group.rename_count > 0);
            const targetLabel = standardTargetLabel(group.target);
            html += '<section class="ifdc-standard-group-card' + (!group.target ? ' has-error' : '') + '" data-standard-group="' + escapeHtml(group.key) + '">';
            html += '<div class="ifdc-standard-group-heading"><label>' +
                '<input type="checkbox" class="ifdc-standard-group" value="' + escapeHtml(group.key) + '" ' + (canSelect ? (group.manual_only ? '' : 'checked') : 'disabled') + '> ' +
                '<strong>' + escapeHtml(group.name) + '</strong></label>' +
                '<span class="ifdc-status ' + (group.target ? 'is-ready' : 'is-warning') + '">' + (group.target ? 'Destination matched' : 'Needs review') + '</span></div>';
            html += '<p class="ifdc-standard-target"><strong>Destination:</strong> ' + escapeHtml(targetLabel) + '<br><strong>Capacity:</strong> ' + (group.capacity === null ? 'Preserve existing' : (group.capacity_only_if_empty ? 'Set to ' + escapeHtml(group.capacity) + ' only when currently 0 or missing; preserve other values' : escapeHtml(group.capacity))) + (group.manual_only ? '<br><strong>Mode:</strong> Manual preview and confirmation only' : '') + '</p>';
            html += '<div class="ifdc-standard-rename"><label><input type="checkbox" class="ifdc-standard-rename-toggle" data-group="' + escapeHtml(group.key) + '" ' + (group.rename_enabled ? 'checked' : '') + '> <strong>Standardize event name</strong>' + (group.automate_name ? ' <span class="description">(automatic for this group)</span>' : '') + '</label> <input type="text" class="regular-text ifdc-standard-rename-value" data-group="' + escapeHtml(group.key) + '" value="' + escapeHtml(group.new_event_name) + '" ' + (group.rename_enabled ? '' : 'disabled') + '></div>';
            html += '<div class="ifdc-standard-counts"><span><strong>' + escapeHtml(group.found_count) + '</strong> found</span><span><strong>' + escapeHtml(group.ready_count) + '</strong> assignment/capacity ready</span><span><strong>' + escapeHtml(group.update_count) + '</strong> assignment/capacity updates</span><span><strong>' + escapeHtml(group.replacement_count) + '</strong> reassignments</span><span><strong>' + escapeHtml(group.capacity_count) + '</strong> capacity fixes</span><span><strong>' + escapeHtml(group.rename_count) + '</strong> names differ</span></div>';
            if (!group.target) html += '<p class="ifdc-result is-error">A unique destination could not be selected automatically. Use the single-event workflow below for this group.</p>';
            if (group.events.length) {
                html += '<details><summary>Review ' + escapeHtml(group.events.length) + ' event' + (group.events.length === 1 ? '' : 's') + '</summary><div class="ifdc-standard-event-list"><table class="widefat striped"><thead><tr><th>Date &amp; time</th><th>Event</th><th>Assignment</th><th>Capacity</th></tr></thead><tbody>';
                group.events.forEach(function(event){
                    const assignment = group.target && parseInt(event.team_id || 0, 10) === parseInt(group.target.id, 10) ? 'Already correct' : ((event.team_id ? '#' + event.team_id : 'Unassigned') + ' → #' + (group.target ? group.target.id : '—'));
                    const capacity = event.capacity_update ? String(event.capacity || 0) + ' → ' + group.capacity : String(event.capacity || 0) + (group.capacity_only_if_empty && parseInt(event.capacity || 0, 10) !== parseInt(group.capacity, 10) ? ' (manual value preserved)' : '');
                    const eventName = group.rename_enabled && String(event.name) !== String(group.new_event_name) ? event.name + ' → ' + group.new_event_name : event.name;
                    html += '<tr data-standard-event-id="' + escapeHtml(event.id) + '" data-standard-group-key="' + escapeHtml(group.key) + '"><td>' + escapeHtml(formatAssignmentDate(event.start)) + '</td><td><strong class="ifdc-standard-event-name">' + escapeHtml(eventName) + '</strong><br><code>#' + escapeHtml(event.id) + '</code></td><td>' + escapeHtml(assignment) + '</td><td>' + escapeHtml(capacity) + '</td></tr>';
                });
                html += '</tbody></table></div></details>';
            }
            html += '</section>';
        });
        html += '</div>';
        $preview.removeClass('ifdc-empty-state').html(html);
        updateStandardControls();
    }

    $('#ifdc-prepare-standard-assignments').on('click', function(){
        const month = $('#ifdc-standard-assignment-month').val();
        const $button = $(this);
        const $status = $('#ifdc-standard-assignment-status');
        if (!month) {
            showResult($status, false, 'Choose a month before preparing the preview.');
            return;
        }
        $button.prop('disabled', true).text('Preparing…');
        $('#ifdc-apply-standard-assignments').prop('disabled', true);
        showResult($status, true, 'Finding events and destinations for all four session types…');
        $.post(IFDC.ajax, {
            action: 'ifdc_prepare_standard_assignments',
            nonce: IFDC.nonce,
            month: month
        }).done(function(response){
            if (!response.success) {
                showResult($status, false, response.data && response.data.message ? response.data.message : 'Unable to prepare the monthly preview.');
                return;
            }
            renderStandardAssignmentBundle(response.data);
            const missing = response.data.groups.filter(function(group){ return !group.target && parseInt(group.update_count || 0, 10) > 0; }).length;
            let message = response.data.total_updates + ' event update' + (response.data.total_updates === 1 ? '' : 's') + ' prepared for ' + response.data.month_label + '. Nothing has been changed.';
            if (missing) message += ' ' + missing + ' destination' + (missing === 1 ? '' : 's') + ' need manual review.';
            showResult($status, missing === 0, message);
        }).fail(function(xhr){
            const response = xhr.responseJSON;
            showResult($status, false, response && response.data && response.data.message ? response.data.message : 'Preparation failed (' + xhr.status + ').');
        }).always(function(){
            $button.prop('disabled', false).text('Prepare Enabled Sessions');
        });
    });

    $(document).on('change', '.ifdc-standard-group', updateStandardControls);

    $(document).on('change', '.ifdc-standard-rename-toggle', function(){
        if (!standardAssignmentBundle) return;
        const key = String($(this).data('group'));
        const group = standardAssignmentBundle.groups.find(function(item){ return String(item.key) === key; });
        if (!group) return;
        group.rename_enabled = $(this).is(':checked');
        $('.ifdc-standard-rename-value[data-group="' + key + '"]').prop('disabled', !group.rename_enabled);
        $('.ifdc-standard-event-list tr[data-standard-group-key="' + key + '"]').each(function(){
            const id = parseInt($(this).data('standard-event-id'), 10);
            const event = group.events.find(function(item){ return parseInt(item.id, 10) === id; });
            if (!event) return;
            const label = group.rename_enabled && String(event.name) !== String(group.new_event_name) ? event.name + ' → ' + group.new_event_name : event.name;
            $(this).find('.ifdc-standard-event-name').text(label);
        });
        updateStandardControls();
    });

    $(document).on('input', '.ifdc-standard-rename-value', function(){
        if (!standardAssignmentBundle) return;
        const key = String($(this).data('group'));
        const group = standardAssignmentBundle.groups.find(function(item){ return String(item.key) === key; });
        if (!group) return;
        group.new_event_name = String($(this).val() || '').trim();
        $('.ifdc-standard-event-list tr[data-standard-group-key="' + key + '"]').each(function(){
            const id = parseInt($(this).data('standard-event-id'), 10);
            const event = group.events.find(function(item){ return parseInt(item.id, 10) === id; });
            if (!event) return;
            const label = group.rename_enabled && group.new_event_name && String(event.name) !== group.new_event_name ? event.name + ' → ' + group.new_event_name : event.name;
            $(this).find('.ifdc-standard-event-name').text(label);
        });
        updateStandardControls();
    });

    $('#ifdc-standard-assignment-month').on('change', function(){
        standardAssignmentBundle = null;
        $('#ifdc-apply-standard-assignments').prop('disabled', true);
        $('#ifdc-standard-assignment-status').attr('hidden', true);
        $('#ifdc-standard-assignment-preview').addClass('ifdc-empty-state').html('<span class="dashicons dashicons-list-view"></span><h3>No monthly preview loaded</h3><p>Prepare the newly selected month before updating.</p>');
    });

    let completedVisibilityBundle = null;

    function updateCompletedVisibilityControls() {
        const count = $('.ifdc-completed-visibility-group:checked').length;
        $('#ifdc-apply-completed-visibility').prop('disabled', assignmentRunning || count === 0);
    }

    function renderCompletedVisibility(bundle) {
        completedVisibilityBundle = bundle;
        const groups = bundle && Array.isArray(bundle.groups) ? bundle.groups : [];
        let html = '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>Session</th><th>Destination Team</th>' + (bundle.include_previous ? '' : '<th>Events</th>') + '<th>Status</th></tr></thead><tbody>';
        groups.forEach(function(group){
            const selectable = !!group.needs_update;
            const target = group.target ? standardTargetLabel(group.target) : 'No unique destination found';
            let status = !group.target ? 'Needs destination review' : (group.protected_event_count ? group.protected_event_count + ' protected event(s); no changes allowed' : (group.needs_update ? 'Ready to hide online' : 'Inactive and hidden online'));
            html += '<tr><th class="check-column"><input type="checkbox" class="ifdc-completed-visibility-group" value="' + escapeHtml(group.key) + '" ' + (selectable ? 'checked' : 'disabled') + '></th>' +
                '<td><strong>' + escapeHtml(group.name) + '</strong></td><td>' + escapeHtml(target) + '</td>' +
                (bundle.include_previous ? '' : '<td>' + escapeHtml(group.active_event_count) + ' active, ' + escapeHtml(group.inactive_event_count) + ' already inactive</td>') + '<td>' + escapeHtml(status) + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#ifdc-completed-visibility-preview').removeClass('ifdc-empty-state').html(html);
        updateCompletedVisibilityControls();
    }

    $('#ifdc-preview-completed-visibility').on('click', function(){
        const month = $('#ifdc-completed-visibility-month').val();
        const includePrevious = $('#ifdc-completed-visibility-previous').is(':checked');
        const $button = $(this).prop('disabled', true).text('Preparing…');
        const $status = $('#ifdc-completed-visibility-status');
        showResult($status, true, 'Finding completed monthly Teams and assigned events…');
        $.post(IFDC.ajax, {action:'ifdc_preview_completed_visibility', nonce:IFDC.nonce, month:month, include_previous:includePrevious ? 1 : 0})
            .done(function(response){
                if (!response.success) return showResult($status, false, response.data && response.data.message ? response.data.message : 'Unable to prepare visibility preview.');
                renderCompletedVisibility(response.data);
                const ready = response.data.groups.filter(function(group){ return group.needs_update; }).length;
                showResult($status, true, ready + ' session group' + (ready === 1 ? '' : 's') + ' ready for ' + response.data.month_label + '. Nothing has been changed.');
            }).fail(function(xhr){
                const response = xhr.responseJSON;
                showResult($status, false, response && response.data && response.data.message ? response.data.message : 'Visibility preview failed (' + xhr.status + ').');
            }).always(function(){ $button.prop('disabled', false).text('Preview Completed Month'); });
    });

    $(document).on('change', '.ifdc-completed-visibility-group', updateCompletedVisibilityControls);

    $('#ifdc-completed-visibility-month, #ifdc-completed-visibility-previous').on('change', function(){
        completedVisibilityBundle = null;
        $('#ifdc-apply-completed-visibility').prop('disabled', true);
        $('#ifdc-completed-visibility-status').attr('hidden', true);
        $('#ifdc-completed-visibility-preview').addClass('ifdc-empty-state').html('<span class="dashicons dashicons-visibility"></span><h3>No visibility preview loaded</h3><p>Previewing does not change Dash.</p>');
    });

    $('#ifdc-apply-completed-visibility').on('click', function(){
        if (!completedVisibilityBundle || assignmentRunning) return;
        const keys = $('.ifdc-completed-visibility-group:checked').map(function(){ return String($(this).val()); }).get();
        if (!keys.length) return;
        const groups = completedVisibilityBundle.groups.filter(function(group){ return keys.includes(String(group.key)); });
        if (!window.confirm('Make ' + groups.length + ' completed monthly Team' + (groups.length === 1 ? '' : 's') + ' inactive and turn off Online Registration in Dash?\n\nTheir events are not changed. Continue?')) return;

        assignmentRunning = true;
        const $button = $(this).prop('disabled', true).text('Deactivating…');
        const $status = $('#ifdc-completed-visibility-status');
        showAssignmentOverlay(groups.length, completedVisibilityBundle.month_label + ' completed monthly registration cleanup', 'Team');
        $('#ifdc-assignment-dialog-status').text('Updating and verifying completed Teams…');
        $('#ifdc-assignment-dialog-warning').text('Keep this page open while Dash updates and verifies each Team. Events and Levels remain unchanged.');
        $('#ifdc-close-assignment-overlay').prop('disabled', true).text('Working…');
        showResult($status, true, 'Updating and verifying completed Teams…');
        $.post(IFDC.ajax, {
            action:'ifdc_apply_completed_visibility',
            nonce:IFDC.nonce,
            month:completedVisibilityBundle.month,
            include_previous:completedVisibilityBundle.include_previous ? 1 : 0,
            group_keys:keys
        }).done(function(response){
            if (!response.success) return showResult($status, false, response.data && response.data.message ? response.data.message : 'Visibility update failed.');
            const data = response.data || {};
            const errors = data.errors || [];
            const skipped = data.skipped || [];
            let message = (data.updated_teams || []).length + ' Teams deactivated';
            if (skipped.length) message += ', ' + skipped.length + ' skipped';
            if (errors.length) message += ', ' + errors.length + ' failed';
            showResult($status, errors.length === 0, message + '. Refresh the preview to confirm current Dash status.');
            finishAssignmentOverlay({updated:data.updated_teams || [], skipped:skipped, errors:errors}, groups.length, groups.length, false);
            completedVisibilityBundle = null;
        }).fail(function(xhr){
            const response = xhr.responseJSON;
            const message = response && response.data && response.data.message ? response.data.message : 'Visibility update failed (' + xhr.status + ').';
            showResult($status, false, message);
            finishAssignmentOverlay({updated:[], skipped:[], errors:[{message:message}]}, groups.length, groups.length, false);
        }).always(function(){
            assignmentRunning = false;
            $button.prop('disabled', true).text('Deactivate Selected Teams');
        });
    });

    $('#ifdc-apply-standard-assignments').on('click', async function(){
        const groups = selectedStandardGroups();
        if (!groups.length || assignmentRunning) return;
        const total = groups.reduce(function(sum, group){ return sum + actionableStandardEvents(group).length; }, 0);
        if (!total || groups.some(function(group){ return !group.target || (group.rename_enabled && !group.new_event_name); })) return;

        const lines = groups.map(function(group){
            const rename = group.rename_enabled ? ', names → “' + group.new_event_name + '”' : '';
            const capacityText = group.capacity === null ? 'preserve capacity' : (group.capacity_only_if_empty ? 'capacity ' + group.capacity + ' only when currently 0 or missing' : 'capacity ' + group.capacity);
            return group.name + ': ' + actionableStandardEvents(group).length + ' updates → ' + group.target.name + ' (' + capacityText + rename + ')';
        });
        if (!window.confirm('Update the standard monthly sessions?\n\n' + lines.join('\n') + '\n\nTotal: ' + total + ' events. This changes Dash. Continue?')) return;

        assignmentRunning = true;
        assignmentStopRequested = false;
        const $button = $(this).text('Updating…').prop('disabled', true);
        $('#ifdc-prepare-standard-assignments').prop('disabled', true);
        updateAssignmentControls();
        showAssignmentOverlay(total, standardAssignmentBundle.month_label + ' standard sessions • ' + groups.length + ' destination' + (groups.length === 1 ? '' : 's') + ' and capacities');
        const $status = $('#ifdc-standard-assignment-status');
        const totals = {updated: [], skipped: [], errors: []};
        const tasks = [];
        groups.forEach(function(group){
            const actionable = actionableStandardEvents(group);
            const capacityUpdates = actionable.filter(function(event){ return !!event.capacity_update; });
            const capacityPreserved = actionable.filter(function(event){ return !event.capacity_update; });
            for (let i = 0; i < capacityUpdates.length; i += 10) tasks.push({group:group, events:capacityUpdates.slice(i, i + 10), capacity:group.capacity});
            for (let i = 0; i < capacityPreserved.length; i += 10) tasks.push({group:group, events:capacityPreserved.slice(i, i + 10), capacity:null});
        });
        let processedCount = 0;

        for (let i = 0; i < tasks.length; i++) {
            if (assignmentStopRequested) break;
            const task = tasks[i];
            showResult($status, true, 'Updating ' + task.group.name + ' • batch ' + (i + 1) + ' of ' + tasks.length + '…');
            updateAssignmentOverlay(processedCount, total, i + 1, tasks.length, task.group.name + ' • batch ' + (i + 1) + ' of ' + tasks.length);
            const expectedTeamIds = {};
            const expectedCapacities = {};
            const expectedEventNames = {};
            let hasReplacement = false;
            task.events.forEach(function(event){
                expectedTeamIds[event.id] = parseInt(event.team_id || 0, 10);
                expectedCapacities[event.id] = parseInt(event.capacity || 0, 10);
                expectedEventNames[event.id] = event.name || '';
                if (event.team_id && parseInt(event.team_id, 10) !== parseInt(task.group.target.id, 10)) hasReplacement = true;
            });

            try {
                const response = await $.ajax({
                    url: IFDC.ajax,
                    method: 'POST',
                    data: {
                        action: 'ifdc_assign_event_batch',
                        nonce: IFDC.nonce,
                        team_id: task.group.target.id,
                        capacity: task.capacity === null ? '' : task.capacity,
                        event_name: task.group.rename_enabled ? task.group.new_event_name : '',
                        allow_reassign: hasReplacement ? 1 : 0,
                        expected_team_ids: expectedTeamIds,
                        expected_capacities: expectedCapacities,
                        expected_event_names: expectedEventNames,
                        event_ids: task.events.map(function(event){ return event.id; })
                    }
                });
                if (!response.success) throw {responseJSON: response};
                totals.updated = totals.updated.concat(response.data.updated || []);
                totals.skipped = totals.skipped.concat(response.data.skipped || []);
                totals.errors = totals.errors.concat(response.data.errors || []);
                (response.data.updated || []).forEach(function(id){
                    const event = task.group.events.find(function(item){ return parseInt(item.id, 10) === parseInt(id, 10); });
                    if (event) {
                        event.team_id = parseInt(task.group.target.id, 10);
                        if (task.capacity !== null) event.capacity = parseInt(task.capacity, 10);
                        if (task.group.rename_enabled) event.name = task.group.new_event_name;
                    }
                    const $row = $('.ifdc-standard-event-list tr[data-standard-event-id="' + id + '"]').addClass('ifdc-row-updated');
                    $row.find('td').eq(2).html('<span class="ifdc-status is-ready">Assigned to #' + escapeHtml(task.group.target.id) + '</span>');
                    $row.find('td:last').html('<span class="ifdc-status is-ready">' + (task.capacity === null ? escapeHtml(event ? event.capacity : '') + ' (preserved)' : escapeHtml(task.capacity)) + '</span>');
                    if (event) $row.find('.ifdc-standard-event-name').text(event.name);
                });
            } catch (error) {
                const response = error && error.responseJSON;
                const detail = response && response.data && response.data.message ? response.data.message : 'The batch request failed.';
                task.events.forEach(function(event){ totals.errors.push({id:event.id, message:detail}); });
            }
            processedCount += task.events.length;
            updateAssignmentOverlay(processedCount, total, i + 1, tasks.length, 'Finished ' + task.group.name + ' batch ' + (i + 1) + ' of ' + tasks.length);
            if (assignmentStopRequested) break;
        }

        const stopped = assignmentStopRequested && processedCount < total;
        assignmentRunning = false;
        $button.text('Update Selected Groups');
        $('#ifdc-prepare-standard-assignments').prop('disabled', false);
        updateStandardControls();
        updateAssignmentControls();
        let summary = totals.updated.length + ' updated';
        if (totals.skipped.length) summary += ', ' + totals.skipped.length + ' skipped';
        if (totals.errors.length) summary += ', ' + totals.errors.length + ' failed';
        if (stopped) summary += ', ' + (total - processedCount) + ' not processed';
        showResult($status, totals.errors.length === 0 && !stopped, summary + '.');
        finishAssignmentOverlay(totals, total, processedCount, stopped);
    });

    $('#ifdc-search-assignment-events').on('click', function(){
        const $button = $(this);
        const $status = $('#ifdc-assignment-event-status');
        populateAssignmentCapacity(false);
        const range = assignmentDateDetails();
        if (!range) {
            showResult($status, false, 'Choose a valid month or custom date range before searching.');
            return;
        }
        $button.prop('disabled', true).text('Searching…');
        showResult($status, true, 'Searching Dash…');
        $.post(IFDC.ajax, {
            action: 'ifdc_search_assignment_events',
            nonce: IFDC.nonce,
            start: range.start,
            end: range.end,
            name: $('#ifdc-assignment-name').val(),
            event_type: $('#ifdc-assignment-type').val(),
            include_other: $('#ifdc-assignment-include-other').is(':checked') ? 1 : 0,
            only_unlimited: $('#ifdc-assignment-unlimited-only').is(':checked') ? 1 : 0
        }).done(function(response){
            if (!response.success) {
                showResult($status, false, response.data && response.data.message ? response.data.message : 'Search failed.');
                return;
            }
            const data = response.data || {};
            renderAssignmentEvents(data.events || []);
            let message = data.count + ' matching event' + (data.count === 1 ? '' : 's') + ' found. Nothing has been changed.';
            if (data.limited) message += ' The result limit was reached; narrow the date range before updating.';
            showResult($status, true, message);
        }).fail(function(xhr){
            const response = xhr.responseJSON;
            showResult($status, false, response && response.data && response.data.message ? response.data.message : 'Event search failed (' + xhr.status + ').');
        }).always(function(){
            $button.prop('disabled', false).text('Search Events');
        });
    });

    $('#ifdc-event-type-correction-mode').on('click', function(){
        assignmentTeam = null;
        assignmentEvents = [];
        $('input[name="ifdc-assignment-team"]').prop('checked', false);
        $('#ifdc-clear-assignment-team').prop('disabled', true);
        $('#ifdc-assignment-name').val('');
        $('#ifdc-assignment-unlimited-only').prop('checked', false);
        $('#ifdc-assignment-capacity').val('');
        $('#ifdc-assignment-event-name').val('');
        $('#ifdc-assignment-new-event-type').val('');
        $('#ifdc-assignment-events').html('<div class="ifdc-empty-state"><span class="dashicons dashicons-randomize"></span><h3>Bulk event-type correction ready</h3><p>Choose the incorrect current event type, search, then choose the correct type under Step 2 before selecting and updating the matching events.</p></div>');
        $('#ifdc-assignment-event-status, #ifdc-assignment-apply-status').attr('hidden', true);
        $('#ifdc-select-all-events, #ifdc-deselect-all-events').prop('disabled', true);
        updateAssignmentControls();
        $('#ifdc-assignment-type').trigger('focus');
    });

    $('#ifdc-search-assignment-teams').on('click', function(){
        const $button = $(this);
        const $status = $('#ifdc-assignment-team-status');
        $button.prop('disabled', true).text('Searching…');
        showResult($status, true, 'Searching Dash…');
        $.post(IFDC.ajax, {
            action: 'ifdc_search_assignment_teams',
            nonce: IFDC.nonce,
            term: $('#ifdc-assignment-team-query').val()
        }).done(function(response){
            if (!response.success) {
                showResult($status, false, response.data && response.data.message ? response.data.message : 'Class search failed.');
                return;
            }
            const teams = response.data && response.data.teams ? response.data.teams : [];
            renderAssignmentTeams(teams);
            showResult($status, true, teams.length + ' matching class' + (teams.length === 1 ? '' : 'es') + ' found.');
        }).fail(function(xhr){
            const response = xhr.responseJSON;
            showResult($status, false, response && response.data && response.data.message ? response.data.message : 'Class search failed (' + xhr.status + ').');
        }).always(function(){
            $button.prop('disabled', false).text('Search Classes');
        });
    });

    $('#ifdc-assignment-date-mode, #ifdc-assignment-month, #ifdc-assignment-start, #ifdc-assignment-end').on('change', function(){
        updateAssignmentDateMode();
        populateAssignmentMonthSearch(true);
        assignmentTeam = null;
        assignmentEvents = [];
        $('#ifdc-clear-assignment-team').prop('disabled', true);
        $('#ifdc-assignment-team-results').empty();
        $('#ifdc-assignment-team-status, #ifdc-assignment-event-status, #ifdc-assignment-apply-status').attr('hidden', true);
        $('#ifdc-assignment-events').html('<div class="ifdc-empty-state"><span class="dashicons dashicons-calendar-alt"></span><h3>No preview loaded</h3><p>Search the selected dates. Nothing in Dash changes during the search.</p></div>');
        $('#ifdc-select-all-events, #ifdc-deselect-all-events').prop('disabled', true);
        updateAssignmentControls();
    });

    updateAssignmentDateMode();

    $(document).on('change', 'input[name="ifdc-assignment-team"]', function(){
        assignmentTeam = {id: parseInt($(this).val(), 10), label: String($(this).data('label') || '')};
        $('#ifdc-clear-assignment-team').prop('disabled', false);
        populateAssignmentCapacity(false);
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-clear-assignment-team').on('click', function(){
        assignmentTeam = null;
        $('input[name="ifdc-assignment-team"]').prop('checked', false);
        $(this).prop('disabled', true);
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-assignment-name').on('change', function(){
        populateAssignmentCapacity(false);
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-assignment-capacity').on('input', function(){
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-assignment-event-name').on('input', function(){
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-assignment-new-event-type').on('input', function(){
        renderAssignmentEvents(assignmentEvents);
    });

    $('#ifdc-assignment-type').on('change', function(){
        if (String($(this).val() || '') === '10' && !assignmentEventNameValue()) {
            $('#ifdc-assignment-event-name').val('Public Skating');
        }
        populateAssignmentCapacity(true);
        renderAssignmentEvents(assignmentEvents);
    });

    $(document).on('change', '.ifdc-assignment-event', updateAssignmentControls);
    $('#ifdc-select-all-events').on('click', function(){
        $('.ifdc-assignment-event:not(:disabled)').prop('checked', true);
        updateAssignmentControls();
    });
    $('#ifdc-deselect-all-events').on('click', function(){
        $('.ifdc-assignment-event').prop('checked', false);
        updateAssignmentControls();
    });

    $('#ifdc-apply-event-assignment').on('click', async function(){
        const ids = selectedAssignmentIds();
        const capacity = assignmentCapacityValue();
        const eventName = assignmentEventNameValue();
        const eventType = assignmentNewEventTypeValue();
        if (!ids.length || (!assignmentTeam && capacity === null && !eventName && eventType === null) || assignmentRunning) return;
        const replacementCount = ids.filter(function(id){
            const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === id; });
            return assignmentTeam && event && event.team_id && parseInt(event.team_id, 10) !== assignmentTeam.id;
        }).length;
        const capacityChangeCount = capacity === null ? 0 : ids.filter(function(id){
            const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === id; });
            return event && parseInt(event.capacity || 0, 10) !== capacity;
        }).length;
        const nameChangeCount = !eventName ? 0 : ids.filter(function(id){
            const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === id; });
            return event && String(event.name || '') !== eventName;
        }).length;
        const eventTypeChangeCount = eventType === null ? 0 : ids.filter(function(id){
            const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === id; });
            return event && String(event.event_type_id || '') !== eventType;
        }).length;
        let message = assignmentTeam ?
            'Assign ' + ids.length + ' event' + (ids.length === 1 ? '' : 's') + ' to:\n\n' + assignmentTeam.label :
            'Update ' + ids.length + ' event' + (ids.length === 1 ? '' : 's') + ' without changing their class assignments.';
        if (replacementCount) message += '\n\n' + replacementCount + ' existing class assignment' + (replacementCount === 1 ? ' will be replaced.' : 's will be replaced.');
        if (capacity !== null) message += '\n\n' + capacityChangeCount + ' event capacit' + (capacityChangeCount === 1 ? 'y' : 'ies') + ' will be set to ' + capacity + '.';
        if (eventName) message += '\n\n' + nameChangeCount + ' event name' + (nameChangeCount === 1 ? '' : 's') + ' will be set to “' + eventName + '”.';
        if (eventType !== null) message += '\n\n' + eventTypeChangeCount + ' event type' + (eventTypeChangeCount === 1 ? '' : 's') + ' will be set to #' + eventType + '.';
        message += '\n\nThis changes Dash. Continue?';
        if (!window.confirm(message)) return;

        assignmentRunning = true;
        showAssignmentOverlay(ids.length, assignmentTeam ?
            'Destination: ' + assignmentTeam.label + (capacity === null ? '' : ' • Capacity: ' + capacity) + (eventName ? ' • Name: ' + eventName : '') + (eventType === null ? '' : ' • Type: ' + eventType) :
            (capacity === null ? (eventName ? 'Name: ' + eventName : 'Event type: ' + eventType) : 'Capacity: ' + capacity + (eventName ? ' • Name: ' + eventName : '') + (eventType === null ? '' : ' • Type: ' + eventType)) + ' • Existing class assignments preserved');
        updateAssignmentControls();
        const $button = $(this).text('Updating…');
        const $status = $('#ifdc-assignment-apply-status');
        const totals = {updated: [], skipped: [], errors: []};
        const chunks = [];
        for (let i = 0; i < ids.length; i += 10) chunks.push(ids.slice(i, i + 10));
        let processedCount = 0;

        for (let i = 0; i < chunks.length; i++) {
            if (assignmentStopRequested) break;
            showResult($status, true, 'Updating batch ' + (i + 1) + ' of ' + chunks.length + '…');
            updateAssignmentOverlay(processedCount, ids.length, i + 1, chunks.length, 'Updating batch ' + (i + 1) + ' of ' + chunks.length + '…');
            try {
                const expectedTeamIds = {};
                const expectedCapacities = {};
                const expectedEventNames = {};
                const expectedEventTypes = {};
                let chunkHasReplacement = false;
                chunks[i].forEach(function(id){
                    const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === id; });
                    const currentTeamId = event && event.team_id ? parseInt(event.team_id, 10) : 0;
                    expectedTeamIds[id] = currentTeamId;
                    expectedCapacities[id] = event && event.capacity !== null ? parseInt(event.capacity, 10) : 0;
                    expectedEventNames[id] = event ? String(event.name || '') : '';
                    expectedEventTypes[id] = event ? String(event.event_type_id || '') : '';
                    if (assignmentTeam && currentTeamId && currentTeamId !== assignmentTeam.id) chunkHasReplacement = true;
                });
                const response = await $.ajax({
                    url: IFDC.ajax,
                    method: 'POST',
                    data: {
                        action: 'ifdc_assign_event_batch',
                        nonce: IFDC.nonce,
                        team_id: assignmentTeam ? assignmentTeam.id : 0,
                        capacity: capacity === null ? '' : capacity,
                        event_name: eventName,
                        new_event_type: eventType === null ? '' : eventType,
                        allow_reassign: chunkHasReplacement ? 1 : 0,
                        expected_team_ids: expectedTeamIds,
                        expected_capacities: expectedCapacities,
                        expected_event_names: expectedEventNames,
                        expected_event_types: expectedEventTypes,
                        event_ids: chunks[i]
                    }
                });
                if (!response.success) throw {responseJSON: response};
                totals.updated = totals.updated.concat(response.data.updated || []);
                totals.skipped = totals.skipped.concat(response.data.skipped || []);
                totals.errors = totals.errors.concat(response.data.errors || []);
                (response.data.updated || []).forEach(function(id){
                    const event = assignmentEvents.find(function(item){ return parseInt(item.id, 10) === parseInt(id, 10); });
                    if (event && assignmentTeam) event.team_id = assignmentTeam.id;
                    if (event && capacity !== null) event.capacity = capacity;
                    if (event && eventName) event.name = eventName;
                    if (event && eventType !== null) event.event_type_id = eventType;
                    const $row = $('.ifdc-assignment-table tr[data-event-id="' + id + '"]');
                    $row.addClass('ifdc-row-updated').find('.ifdc-assignment-event').prop('checked', false).prop('disabled', true);
                    if (capacity !== null) $row.find('td').eq(3).html('<span class="ifdc-status is-ready">' + escapeHtml(capacity) + '</span>');
                    if (assignmentTeam) $row.find('td:last').html('<span class="ifdc-status is-ready">Assigned to #' + escapeHtml(assignmentTeam.id) + '</span>');
                    if (event && (eventName || eventType !== null)) $row.find('td').eq(1).html('<strong>' + escapeHtml(event.name) + '</strong><br><code>#' + escapeHtml(event.id) + '</code> <span class="description">Type ' + escapeHtml(event.event_type_id || '—') + '</span>');
                });
            } catch (error) {
                const response = error && error.responseJSON;
                const detail = response && response.data && response.data.message ? response.data.message : 'The batch request failed.';
                chunks[i].forEach(function(id){ totals.errors.push({id:id, message:detail}); });
            }
            processedCount += chunks[i].length;
            updateAssignmentOverlay(processedCount, ids.length, i + 1, chunks.length, 'Finished batch ' + (i + 1) + ' of ' + chunks.length);
            if (assignmentStopRequested) break;
        }

        const stopped = assignmentStopRequested && processedCount < ids.length;
        assignmentRunning = false;
        $button.text('Update Selected Events');
        updateAssignmentControls();
        let summary = totals.updated.length + ' updated';
        if (totals.skipped.length) summary += ', ' + totals.skipped.length + ' skipped';
        if (totals.errors.length) summary += ', ' + totals.errors.length + ' failed';
        if (totals.errors.length) {
            summary += '. ' + totals.errors.slice(0, 3).map(function(item){ return 'Event #' + item.id + ': ' + item.message; }).join(' | ');
        }
        if (stopped) summary += '. Stopped with ' + (ids.length - processedCount) + ' events not processed.';
        showResult($status, totals.errors.length === 0, summary);
        finishAssignmentOverlay(totals, ids.length, processedCount, stopped);
    });

    $('#ifdc-close-assignment-overlay').on('click', closeAssignmentOverlay);

    // Default to Teams: it is the most useful root object for current integrations.
    $('#ifdc-preset').val('teams').trigger('change');
    populateAssignmentMonthSearch(true);
    populateAssignmentCapacity(true);
    updateAssignmentControls();
});

jQuery(function($){
    if (!$('#ifdc-find-schedule-gaps').length) return;
    function esc(value) { return $('<div>').text(value == null ? '' : String(value)).html(); }
    function ymd(date) { return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0'); }
    function ranges() {
        if ($('#ifdc-gap-range').val() === 'month') {
            const parts = String($('#ifdc-gap-month').val()).split('-').map(Number);
            if (parts.length !== 2 || !parts[0] || !parts[1]) return null;
            return {start: ymd(new Date(parts[0], parts[1] - 1, 1)), end: ymd(new Date(parts[0], parts[1], 0))};
        }
        const selected = new Date(String($('#ifdc-gap-date').val()) + 'T12:00:00');
        if (Number.isNaN(selected.getTime())) return null;
        const monday = new Date(selected); monday.setDate(selected.getDate() - ((selected.getDay() + 6) % 7));
        const sunday = new Date(monday); sunday.setDate(monday.getDate() + 6);
        return {start: ymd(monday), end: ymd(sunday)};
    }
    function tableRows(items, includeDate, includeCutStatus) {
        let html = '<div class="ifdc-table-wrap"><table class="widefat striped ifdc-gap-table"><thead><tr>' + (includeDate ? '<th>Date</th>' : '') + '<th>Resource</th><th>Open time</th><th>Length</th>' + (includeCutStatus ? '<th>Resurfacing</th>' : '') + '</tr></thead><tbody>';
        items.forEach(function(item){
            const rowClasses = {staffing: 'ifdc-cut-staffing', first: 'ifdc-cut-first', second: 'ifdc-cut-second'};
            html += '<tr class="' + (rowClasses[item.cut_status_class] || '') + '">' + (includeDate ? '<td>' + esc(item.date_label) + '</td>' : '') + '<td><strong>' + esc(item.resource_name) + '</strong></td><td>' + esc(item.start_label + '–' + item.end_label) + '</td><td>' + esc(item.minutes) + ' minutes</td>' + (includeCutStatus ? '<td><span class="ifdc-cut-status is-' + esc(item.cut_status_class || 'none') + '">' + esc(item.cut_status || 'No overlap') + '</span></td>' : '') + '</tr>';
        });
        return html + '</tbody></table></div>';
    }
    function table(title, items, empty, groupByDay) {
        let html = '<div class="ifdc-gap-section"><h3>' + esc(title) + ' <span class="ifdc-gap-count">' + items.length + '</span></h3>';
        if (!items.length) return html + '<p class="description">' + esc(empty) + '</p></div>';
        if (!groupByDay) return html + tableRows(items, true, false) + '</div>';
        const groups = {};
        items.forEach(function(item){ const key = String(item.start || '').slice(0, 10); (groups[key] = groups[key] || []).push(item); });
        Object.keys(groups).sort().forEach(function(key){
            const group = groups[key];
            html += '<div class="ifdc-gap-day"><div class="ifdc-gap-day-heading"><strong>' + esc(group[0].date_label) + '</strong><span>' + group.length + ' ice cut' + (group.length === 1 ? '' : 's') + '</span></div>' + tableRows(group, false, true) + '</div>';
        });
        return html + '</div>';
    }
    $('#ifdc-gap-range').on('change', function(){
        const month = $(this).val() === 'month';
        $('#ifdc-gap-week-label').prop('hidden', month);
        $('#ifdc-gap-month-label').prop('hidden', !month);
    });
    $('#ifdc-find-schedule-gaps').on('click', function(){
        const range = ranges(), $button = $(this), $status = $('#ifdc-gap-status');
        if (!range) { $status.removeClass('is-success').addClass('is-error').prop('hidden', false).text('Choose a valid week or month.'); return; }
        $button.prop('disabled', true).text('Scanning Schedule…');
        $status.removeClass('is-error').addClass('is-success').prop('hidden', false).text('Reading events and calculating both kinds of gaps…');
        $.post(IFDC.ajax, {action:'ifdc_find_schedule_gaps', nonce:IFDC.nonce, start:range.start, end:range.end, day_start:$('#ifdc-gap-day-start').val(), day_end:$('#ifdc-gap-day-end').val(), minimum:$('#ifdc-gap-minutes').val()})
            .done(function(response){
                if (!response.success) { $status.removeClass('is-success').addClass('is-error').text(response.data && response.data.message ? response.data.message : 'Search failed.'); return; }
                const data = response.data || {}, gaps = data.gaps || [], cuts = data.ice_cuts || [];
                $('#ifdc-gap-results').removeClass('ifdc-empty-state').html(table('Schedule gaps (45+ minutes)', gaps, 'No schedule gaps met the selected minimum.', false) + table('Likely ice cuts (15 or 30 minutes)', cuts, 'No likely ice cuts were found between events.', true));
                $('#ifdc-gap-summary').text(range.start + ' through ' + range.end + ' • ' + data.resource_count + ' resources • ' + data.event_count + ' events scanned');
                $('#ifdc-gap-print').prop('hidden', false);
                let message = gaps.length + ' schedule gap' + (gaps.length === 1 ? '' : 's') + ' and ' + cuts.length + ' likely ice cut' + (cuts.length === 1 ? '' : 's') + ' found.';
                if (data.limited) message += ' The display limit was reached.';
                $status.removeClass('is-error').addClass('is-success').text(message);
            }).fail(function(xhr){ const response = xhr.responseJSON; $status.removeClass('is-success').addClass('is-error').text(response && response.data && response.data.message ? response.data.message : 'Schedule search failed (' + xhr.status + ').'); })
            .always(function(){ $button.prop('disabled', false).text('Find Schedule Gaps'); });
    });
    $('#ifdc-gap-print').on('click', function(){ window.print(); });
});
