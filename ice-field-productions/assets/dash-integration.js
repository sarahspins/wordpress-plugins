(() => {
    'use strict';

    const config = window.IFPDashDiscovery || {};
    const selectAll = document.querySelector('.ifp-dash-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            document.querySelectorAll('.ifp-dash-team input[name="team_ids[]"]').forEach((box) => {
                box.checked = selectAll.checked;
            });
        });
    }

    document.querySelectorAll('.ifp-dash-preview-roster').forEach((button) => {
        button.addEventListener('click', async () => {
            const team = button.closest('.ifp-dash-team');
            const output = team ? team.querySelector('.ifp-dash-roster') : null;
            if (!output) return;

            if (!output.hidden && output.dataset.loaded === '1') {
                output.hidden = true;
                button.textContent = 'Preview Roster & Count';
                return;
            }

            output.hidden = false;
            output.innerHTML = `<p>${config.loading || 'Loading roster…'}</p>`;
            button.disabled = true;

            const body = new URLSearchParams({
                action: 'ifp_dash_preview_roster',
                nonce: config.nonce || '',
                team_id: button.dataset.teamId || '',
            });

            try {
                const response = await fetch(config.ajaxUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body,
                    credentials: 'same-origin',
                });
                const result = await response.json();
                if (!result.success) throw new Error(result.data?.message || config.error);

                const customers = Array.isArray(result.data.customers) ? result.data.customers : [];
                let html = `<div class="ifp-dash-roster__heading"><strong>${result.data.count} registered</strong></div>`;
                if (!customers.length) {
                    html += '<p>No registered customers were returned.</p>';
                } else {
                    html += '<table class="widefat striped"><thead><tr><th>Name</th><th>Customer ID</th><th>Email</th><th>Status</th></tr></thead><tbody>';
                    customers.forEach((customer) => {
                        const escape = (value) => {
                            const div = document.createElement('div');
                            div.textContent = value || '';
                            return div.innerHTML;
                        };
                        html += `<tr><td>${escape(customer.name)}</td><td>${escape(customer.id)}</td><td>${escape(customer.email || '—')}</td><td>${escape(customer.status || '—')}</td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                output.innerHTML = html;
                output.dataset.loaded = '1';
                button.textContent = 'Hide Roster';
            } catch (error) {
                output.innerHTML = '';
                const message = document.createElement('p');
                message.className = 'ifp-dash-error';
                message.textContent = error.message || config.error;
                output.appendChild(message);
            } finally {
                button.disabled = false;
            }
        });
    });
})();
