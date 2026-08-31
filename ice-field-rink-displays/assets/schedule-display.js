(function () {
    'use strict';

    function initialize(root) {
        if (!root || root.getAttribute('data-ifrd-ready') === '1') return;
        root.setAttribute('data-ifrd-ready', '1');

        var status = root.querySelector('[data-status]');
        var started = Date.now();
        var timer = null;
        function beginConnection() {
            started = Date.now();
            if (timer) window.clearInterval(timer);
            status.textContent = 'Display 2.8.1 • API status: connecting… 0s';
            timer = window.setInterval(function () {
                status.textContent = 'Display 2.8.1 • API status: connecting… ' + Math.floor((Date.now() - started) / 1000) + 's';
            }, 1000);
        }
        function finishConnection(message) {
            if (timer) window.clearInterval(timer);
            timer = null;
            status.textContent = message || '';
        }
        var currentScheduleData = null;
        // Change this when the stored schedule shape or enrichment changes so a
        // TV cannot keep rendering an older snapshot after a plugin upgrade.
        var cacheKey = 'ifrd_schedule_last_success_v228';

        function esc(value) {
            return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character];
            });
        }

        function clock() {
            root.querySelector('[data-time]').textContent = new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
            root.querySelector('[data-date]').textContent = new Date().toLocaleDateString([], {weekday: 'long', month: 'long', day: 'numeric'});
        }

        function badge(event) {
            var now = Date.now();
            var start = new Date(event.start).getTime();
            var end = new Date(event.end).getTime();
            if (end <= now) return ['PAST', 'past'];
            if (start <= now && end > now) return ['ON ICE NOW', 'now'];
            var minutes = (start - now) / 60000;
            if (minutes > 0 && minutes <= 60) return ['UP NEXT', 'next'];
            return ['LATER', 'later'];
        }

        function relativeTime(event, state) {
            var target = state === 'now' ? new Date(event.end).getTime() : new Date(event.start).getTime();
            var minutes = Math.max(0, Math.ceil((target - Date.now()) / 60000));
            if (state === 'now') return minutes < 1 ? 'Ending now' : 'Ends in ' + minutes + ' min';
            if (state === 'next') return minutes < 1 ? 'Starting now' : 'Starts in ' + minutes + ' min';
            return '';
        }

        var rotationPage = {gold: 0, silver: 0};
        var rotationPages = {gold: null, silver: null};
        var rotationSeconds = 12;
        var rotationDeadline = Date.now() + (rotationSeconds * 1000);

        function updateRotationCountdown() {
            var seconds = Math.max(0, Math.ceil((rotationDeadline - Date.now()) / 1000));
            var labels = root.querySelectorAll('.ifrd-schedule-rotation[data-page-label]');
            for (var index = 0; index < labels.length; index += 1) {
                labels[index].textContent = labels[index].getAttribute('data-page-label') + ' • ' + seconds + 's';
            }
        }

        function render(key, items, pageSize, animate) {
            var element = root.querySelector('[data-list="' + key + '"]');
            var now = Date.now();
            var allItems = Array.isArray(items) ? items : [];
            var pastItems = allItems.filter(function (event) { return new Date(event.end).getTime() <= now; });
            var activeItems = allItems.filter(function (event) { return new Date(event.end).getTime() > now; });
            var currentItems = activeItems.filter(function (event) { return new Date(event.start).getTime() <= now; });
            var upcomingItems = activeItems.filter(function (event) { return new Date(event.start).getTime() > now; });
            var upNextItems = upcomingItems.filter(function (event) { return (new Date(event.start).getTime() - now) / 60000 <= 60; });
            var laterItems = upcomingItems.filter(function (event) { return (new Date(event.start).getTime() - now) / 60000 > 60; });
            pageSize = Math.max(1, Number(pageSize) || 8);
            var pinned = [];
            var laterShown = [];
            var pageCount = 1;
            var page = 0;
            if (activeItems.length) {
                pinned = currentItems.concat(upNextItems);
            } else if (pastItems.length) {
                pinned = [pastItems[pastItems.length - 1]];
            }
            if (!pinned.length && !laterItems.length) {
                element.innerHTML = '<div class="ifrd-schedule-empty">No more events today.</div>';
                return;
            }

            function eventHtml(event) {
                var eventBadge = badge(event);
                var metaParts = [];
                if (event.note) metaParts.push(esc(event.note));
                var meta = metaParts.length ? '<div class="ifrd-schedule-meta">' + metaParts.join(' • ') + '</div>' : '';
                var registrationMeta = '';
                if (event.registrantText) {
                    var countText = event.isFull && isFinite(Number(event.registrantCount)) ? (Number(event.registrantCount) === 1 ? '1 registered skater' : Number(event.registrantCount) + ' registered skaters') : event.registrantText;
                    var fullLabel = String(event.fullLabel || 'FULL').replace(/\s*[-–—:]+\s*$/, '');
                    registrationMeta = '<div class="ifrd-schedule-meta ifrd-registration-meta">' + (event.isFull ? '<span class="ifrd-full-inline">' + esc(fullLabel || 'FULL') + '</span>' : '') + '<span>' + esc(countText) + '</span></div>';
                }
                var locker = event.lockerText ? '<div class="ifrd-schedule-meta ifrd-schedule-locker">' + esc(event.lockerText) + '</div>' : '';
                var blocks = event.subBlocks && event.subBlocks.length ? '<div class="ifrd-schedule-subblocks">' + event.subBlocks.map(function (block) {
                    return '<div><strong>' + esc(block.time) + '</strong>' + (block.title ? ' — ' + esc(block.title) : '') + '</div>';
                }).join('') + '</div>' : '';
                var relative = relativeTime(event, eventBadge[1]);
                var badges = '<div class="ifrd-schedule-badges"><div class="ifrd-schedule-badge ' + eventBadge[1] + '">' + eventBadge[0] + '</div></div>';
                return '<article class="ifrd-schedule-event is-' + eventBadge[1] + '"><div class="ifrd-schedule-time">' + esc(event.startLabel) + '<span>to ' + esc(event.endLabel) + '</span>' + (relative ? '<span class="ifrd-schedule-relative">' + esc(relative) + '</span>' : '') + '</div><div><div class="ifrd-schedule-title">' + esc(event.title) + '</div>' + blocks + meta + registrationMeta + locker + '</div>' + badges + '</article>';
            }

            var pinnedHtml = '';
            if (!currentItems.length && pastItems.length && upcomingItems.length) {
                pinnedHtml += '<article class="ifrd-schedule-event ifrd-schedule-resurfacing is-now"><div class="ifrd-schedule-time"></div><div><div class="ifrd-schedule-title">Resurfacing</div></div><div class="ifrd-schedule-badges"><div class="ifrd-schedule-badge now">ON ICE NOW</div></div></article>';
            }
            pinnedHtml += pinned.map(eventHtml).join('');

            function paginateLaterByHeight() {
                if (!laterItems.length) return [];
                var panel = element.closest('.ifrd-schedule-panel');
                var panelRect = panel ? panel.getBoundingClientRect() : null;
                var listRect = element.getBoundingClientRect();
                var availableHeight = panelRect ? Math.max(1, panelRect.bottom - listRect.top - 10) : 0;
                if (!availableHeight) return [laterItems.slice(0, pageSize)];

                var pages = [];
                var current = [];
                for (var eventIndex = 0; eventIndex < laterItems.length; eventIndex += 1) {
                    var trial = current.concat([laterItems[eventIndex]]);
                    var trialHtml = '<div class="ifrd-schedule-group-label"><span>Later:</span></div>' + trial.map(eventHtml).join('');
                    element.innerHTML = '<div class="ifrd-schedule-pinned">' + pinnedHtml + '</div><div class="ifrd-schedule-later-page">' + trialHtml + '</div>';
                    if (element.scrollHeight <= availableHeight || current.length === 0) {
                        current = trial;
                    } else {
                        pages.push(current);
                        current = [laterItems[eventIndex]];
                    }
                }
                if (current.length) pages.push(current);
                return pages;
            }

            if (!animate || !rotationPages[key]) {
                rotationPages[key] = paginateLaterByHeight();
            }
            var pages = rotationPages[key] || [];
            pageCount = Math.max(1, pages.length);
            page = rotationPage[key] % pageCount;
            laterShown = pages[page] || [];
            var pageText = 'Page ' + (page + 1) + ' of ' + pageCount;
            var countdown = Math.max(0, Math.ceil((rotationDeadline - Date.now()) / 1000));
            var pageLabel = pageCount > 1 ? '<span class="ifrd-schedule-rotation" data-page-label="' + pageText + '">' + pageText + ' • ' + countdown + 's</span>' : '';
            var laterHtml = laterShown.length ? '<div class="ifrd-schedule-group-label"><span>Later:</span>' + pageLabel + '</div>' + laterShown.map(eventHtml).join('') : '';

            var existingPinned = element.querySelector('.ifrd-schedule-pinned');
            var existingLater = element.querySelector('.ifrd-schedule-later-page');
            if (animate && pageCount <= 1) {
                return;
            }
            if (animate && existingPinned && existingLater) {
                existingLater.innerHTML = laterHtml;
                existingLater.classList.remove('is-rotating');
                void existingLater.offsetWidth;
                existingLater.classList.add('is-rotating');
            } else {
                element.innerHTML = '<div class="ifrd-schedule-pinned">' + pinnedHtml + '</div><div class="ifrd-schedule-later-page">' + laterHtml + '</div>';
            }
        }

        function localDayKey() {
            var date = new Date();
            return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
        }

        function displaySchedule(data, animate) {
            currentScheduleData = data;
            render('gold', data.goldAll || data.gold || [], data.pageSize || root.getAttribute('data-ifrd-max-visible'), animate);
            render('silver', data.silverAll || data.silver || [], data.pageSize || root.getAttribute('data-ifrd-max-visible'), animate);
            var updated = data.generatedAt ? new Date(data.generatedAt) : (data.cachedAt ? new Date(data.cachedAt) : new Date());
            root.querySelector('[data-updated]').textContent = 'Last updated: ' + updated.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', second: '2-digit'});
        }

        function fetchWithTimeout(url, options, timeout) {
            return Promise.race([
                window.fetch(url, options),
                new Promise(function (_, reject) { window.setTimeout(function () { reject(new Error('Request timed out')); }, timeout); })
            ]);
        }

        function showCachedSchedule() {
            try {
                var cached = JSON.parse(window.localStorage.getItem(cacheKey));
                if (cached && cached.cachedDay === localDayKey()) {
                    displaySchedule(cached);
                    status.textContent = 'Display 2.8.1 • API status: connecting — showing saved schedule';
                    return true;
                }
                if (cached) window.localStorage.removeItem(cacheKey);
            } catch (error) {
                try { window.localStorage.removeItem(cacheKey); } catch (ignore) {}
            }
            return false;
        }

        async function load() {
            beginConnection();
            try {
                var schedule = null;
                var staticUrl = root.getAttribute('data-ifrd-static-url') || '';
                if (staticUrl) {
                    try {
                        var staticResponse = await fetchWithTimeout(staticUrl, {cache: 'no-store'}, 5000);
                        if (staticResponse.ok) schedule = await staticResponse.json();
                    } catch (ignore) {}
                }
                if (!schedule) {
                    var form = new FormData();
                    form.append('action', 'ifrd_schedule_data');
                    var response = await fetchWithTimeout(root.getAttribute('data-ifrd-ajax-url'), {method: 'POST', body: form, cache: 'no-store'}, 30000);
                    if (!response.ok) throw new Error('Schedule refresh failed');
                    var payload = await response.json();
                    if (!payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : 'Unable to load schedule');
                    schedule = payload.data;
                }
                schedule = Object.assign({}, schedule, {cachedAt: Date.now(), cachedDay: localDayKey()});
                displaySchedule(schedule, false);
                try { window.localStorage.setItem(cacheKey, JSON.stringify(schedule)); } catch (ignore) {}
                finishConnection('');
            } catch (error) {
                finishConnection('');
                var gold = root.querySelector('[data-list="gold"]');
                var silver = root.querySelector('[data-list="silver"]');
                var hasVisibleSchedule = (gold && gold.children.length > 0) || (silver && silver.children.length > 0);
                status.textContent = hasVisibleSchedule ? 'API status: refresh failed — showing last schedule' : 'API status: error';
                if (!hasVisibleSchedule && gold) gold.innerHTML = '<div class="ifrd-schedule-error">' + esc(error.message) + '</div>';
                if (window.console && window.console.error) window.console.error(error);
            }
        }

        var currentRefreshVersion = new URL(window.location.href).searchParams.get('screen_refresh') || root.getAttribute('data-ifrd-refresh-version');
        async function checkForScreenRefresh() {
            try {
                var body = new URLSearchParams();
                body.set('action', root.getAttribute('data-ifrd-refresh-action'));
                var response = await window.fetch(root.getAttribute('data-ifrd-ajax-url'), {method: 'POST', headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString(), credentials: 'same-origin', cache: 'no-store'});
                var payload = await response.json();
                var latest = String(payload && payload.success && payload.data && payload.data.version || '');
                if (!latest || latest === currentRefreshVersion) return;
                currentRefreshVersion = latest;
                var target = new URL(window.location.href);
                target.searchParams.set('screen_refresh', latest);
                window.location.replace(target.toString());
            } catch (ignore) {}
        }

        clock();
        window.setInterval(clock, 1000);
        showCachedSchedule();
        load();
        window.setInterval(load, Math.max(30, parseInt(root.getAttribute('data-ifrd-refresh-seconds'), 10) || 300) * 1000);
        window.setInterval(function () { if (currentScheduleData) displaySchedule(currentScheduleData, false); }, 30000);
        window.setInterval(updateRotationCountdown, 1000);
        window.setInterval(function () {
            if (!currentScheduleData) return;
            rotationDeadline = Date.now() + (rotationSeconds * 1000);
            rotationPage.gold += 1;
            rotationPage.silver += 1;
            displaySchedule(currentScheduleData, true);
            updateRotationCountdown();
        }, rotationSeconds * 1000);
        window.setInterval(checkForScreenRefresh, 60000);
        window.setTimeout(checkForScreenRefresh, 5000);
        var resizeTimer = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function () {
                rotationPages.gold = null;
                rotationPages.silver = null;
                if (currentScheduleData) displaySchedule(currentScheduleData, false);
            }, 200);
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(function () {
                rotationPages.gold = null;
                rotationPages.silver = null;
                if (currentScheduleData) displaySchedule(currentScheduleData, false);
            });
        }
        document.addEventListener('visibilitychange', function () { if (!document.hidden) checkForScreenRefresh(); });
    }

    var roots = document.querySelectorAll('.ifrd-schedule-app');
    for (var index = 0; index < roots.length; index += 1) initialize(roots[index]);
}());
