(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[character];
        });
    }

    function parseDate(value) {
        var parts = String(value || '').split('-').map(Number);
        return new Date(parts[0], (parts[1] || 1) - 1, parts[2] || 1, 12, 0, 0, 0);
    }

    function dateValue(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function addDays(value, amount) {
        var date = parseDate(value);
        date.setDate(date.getDate() + amount);
        return dateValue(date);
    }

    function mondayFor(value) {
        var date = parseDate(value);
        var weekday = date.getDay();
        var distance = weekday === 0 ? -6 : 1 - weekday;
        date.setDate(date.getDate() + distance);
        return dateValue(date);
    }

    function prettyDate(value) {
        return parseDate(value).toLocaleDateString([], {
            weekday: 'long',
            month: 'long',
            day: 'numeric'
        });
    }

    function updatedTime(value) {
        if (!value) return '';
        return new Date(value).toLocaleTimeString([], {
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function initialize(root) {
        var config;
        try {
            config = JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (error) {
            config = {};
        }

        var state = {
            view: config.initialView === 'day' ? 'day' : 'week',
            date: config.initialDate || dateValue(new Date()),
            rink: 'all',
            sessionType: 'all',
            data: null,
            weekCache: new Map(),
            hasRendered: false
        };

        var content = root.querySelector('[data-calendar-content]');
        var status = root.querySelector('[data-calendar-status]');
        var dateInput = root.querySelector('[data-calendar-date]');
        var rinkSelect = root.querySelector('[data-calendar-rink]');
        var sessionSelect = root.querySelector('[data-calendar-session]');
        var todayButton = root.querySelector('[data-calendar-today]');
        var weekNav = root.querySelector('[data-week-nav]');
        var weekRange = root.querySelector('[data-week-range]');
        var previousButton = root.querySelector('[data-week-previous]');
        var nextButton = root.querySelector('[data-week-next]');
        var viewButtons = Array.prototype.slice.call(root.querySelectorAll('[data-view]'));
        var initialData = null;
        var initialDataNode = root.querySelector('[data-calendar-initial]');
        if (initialDataNode) {
            try {
                initialData = JSON.parse(initialDataNode.textContent || 'null');
            } catch (ignore) {
                initialData = null;
            }
        }

        function setUpdating(isUpdating) {
            root.classList.toggle('is-updating', isUpdating);
            root.setAttribute('aria-busy', isUpdating ? 'true' : 'false');
        }

        function updateControls() {
            viewButtons.forEach(function (button) {
                var active = button.getAttribute('data-view') === state.view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            weekNav.hidden = state.view !== 'week';
            dateInput.value = state.date;
            rinkSelect.value = state.rink;
            if (sessionSelect) sessionSelect.value = state.sessionType;
            if (state.data) weekRange.textContent = state.data.weekLabel || '';
        }

        function sessionCategory(event, title) {
            var value = String(title || '').toLocaleLowerCase();
            var eventTypeId = String(event.eventTypeId || '').toLocaleLowerCase();
            var leagueId = String(event.leagueId || '');
            var homeTeamId = String(event.homeTeamId || '');
            var awayTeamId = String(event.awayTeamId || '');
            var hasLeague = leagueId !== '' && leagueId !== '0';
            var hasTeams = homeTeamId !== '' && homeTeamId !== '0' && awayTeamId !== '' && awayTeamId !== '0';

            if (value.indexOf('freestyle') !== -1 || eventTypeId === '9') return 'Freestyle';
            if (value.indexOf('stick & puck') !== -1 || value.indexOf('stick and puck') !== -1 || eventTypeId === 'k') return 'Stick & Puck';
            if (value.indexOf('public ice') !== -1 || value.indexOf('public skate') !== -1 || value.indexOf('public skating') !== -1) return 'Public Skating';
            if (value.indexOf('private hockey coach') !== -1 || value.indexOf('coaches ice') !== -1 || value.indexOf('phci') !== -1) return 'Private Hockey / Coaches Ice';
            if (
                value.indexOf('learn to skate') !== -1 ||
                /(^|\s)lts(\s|$)/i.test(value) ||
                value.indexOf('large group') !== -1 ||
                value.indexOf('snowplow sam') !== -1 ||
                /\bbasic\s*\d/i.test(value) ||
                /\badult\s*\d/i.test(value) ||
                value.indexOf('free skate') !== -1
            ) return 'Learn to Skate';
            if (value.indexOf('camp') !== -1) return 'Camps';
            if (
                value.indexOf('specialty') !== -1 ||
                value.indexOf('artistry') !== -1 ||
                value.indexOf('choreo') !== -1 ||
                value.indexOf('jumps') !== -1 ||
                value.indexOf('spins') !== -1 ||
                value.indexOf('skating skills') !== -1 ||
                value.indexOf('edge class') !== -1
            ) return 'Specialty Classes';
            if (value.indexOf('hockey') !== -1 || hasLeague || hasTeams) return 'Hockey';
            return 'Other';
        }

        function eventCategories(event) {
            var values = Array.isArray(event.sessionTypes) && event.sessionTypes.length
                ? event.sessionTypes
                : [event.title];
            var seen = {};

            return values.map(function (value) {
                return sessionCategory(event, value);
            }).filter(function (category) {
                var key = category.toLocaleLowerCase();
                if (seen[key]) return false;
                seen[key] = true;
                return true;
            });
        }

        function updateSessionOptions() {
            if (!sessionSelect) {
                state.sessionType = 'all';
                return;
            }
            if (!state.data || !Array.isArray(state.data.days)) return;

            var seen = {};
            var sessionTypes = [];
            state.data.days.forEach(function (day) {
                (day.gold || []).concat(day.silver || []).forEach(function (event) {
                    if (Number(event.endMinutes) <= 300) return;
                    eventCategories(event).forEach(function (value) {
                        var key = value.toLocaleLowerCase();
                        if (seen[key]) return;
                        seen[key] = true;
                        sessionTypes.push(value);
                    });
                });
            });

            var preferredOrder = ['Freestyle', 'Stick & Puck', 'Public Skating', 'Private Hockey / Coaches Ice', 'Hockey', 'Learn to Skate', 'Camps', 'Specialty Classes', 'Other'];
            sessionTypes.sort(function (a, b) {
                return preferredOrder.indexOf(a) - preferredOrder.indexOf(b);
            });
            if (state.sessionType !== 'all' && sessionTypes.indexOf(state.sessionType) === -1) {
                state.sessionType = 'all';
            }

            sessionSelect.innerHTML = '<option value="all">All Session Types</option>' + sessionTypes.map(function (value) {
                return '<option value="' + escapeHtml(value) + '">' + escapeHtml(value) + '</option>';
            }).join('');
            sessionSelect.value = state.sessionType;
        }

        function matchesSession(event) {
            if (state.sessionType === 'all') return true;
            return eventCategories(event).indexOf(state.sessionType) !== -1;
        }

        function sessionColorIndex(event) {
            var value = eventCategories(event)[0] || 'Other';

            if (value === 'Freestyle') return 0;
            if (value === 'Stick & Puck') return 1;
            if (value === 'Public Skating') return 2;
            if (value === 'Hockey' || value === 'Private Hockey / Coaches Ice') return 3;
            if (value === 'Learn to Skate') return 4;
            if (value === 'Specialty Classes') return 5;
            if (value === 'Camps') return 6;

            var hash = 0;
            for (var index = 0; index < value.length; index++) {
                hash = ((hash << 5) - hash) + value.charCodeAt(index);
                hash |= 0;
            }
            return 6 + (Math.abs(hash) % 2);
        }

        function eventColorStyle(event) {
            var fallbackColors = ['#dfacf3', '#78d2ee', '#ff565d', '#a9e477', '#ffd787', '#5be1e6', '#5c86a3', '#e5e7eb'];
            var color = String(event.dashColor || fallbackColors[sessionColorIndex(event)] || '#e5e7eb').trim();
            var match = color.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
            if (!match) return '';

            var hex = match[1];
            if (hex.length === 3) {
                hex = hex.charAt(0) + hex.charAt(0) + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2);
            }
            var red = parseInt(hex.slice(0, 2), 16);
            var green = parseInt(hex.slice(2, 4), 16);
            var blue = parseInt(hex.slice(4, 6), 16);
            var strength = Math.max(0, Math.min(100, Number(config.colorStrength == null ? 50 : config.colorStrength))) / 100;
            var borderStrength = Math.min(1, strength + 0.15);
            var visibleRed = Math.round(255 + ((red - 255) * strength));
            var visibleGreen = Math.round(255 + ((green - 255) * strength));
            var visibleBlue = Math.round(255 + ((blue - 255) * strength));
            var text = ((visibleRed * 299 + visibleGreen * 587 + visibleBlue * 114) / 1000) < 145 ? '#ffffff' : '#202a39';
            return '--ifrd-event-bg:rgba(' + red + ',' + green + ',' + blue + ',' + strength.toFixed(2) + ');' +
                '--ifrd-event-border:rgba(' + red + ',' + green + ',' + blue + ',' + borderStrength.toFixed(2) + ');' +
                '--ifrd-event-text:' + text + ';';
        }

        function registrationLinks(event) {
            var seen = {};
            return (Array.isArray(event.registrationLinks) ? event.registrationLinks : []).map(function (link) {
                return {
                    title: String(link && link.title ? link.title : event.title || 'Register'),
                    url: String(link && link.url ? link.url : '')
                };
            }).filter(function (link) {
                if (!/^https?:\/\/[^\s]+$/i.test(link.url) || seen[link.url]) return false;
                seen[link.url] = true;
                return true;
            });
        }

        function registerOptionsAttribute(links) {
            return escapeHtml(JSON.stringify(links));
        }

        function filterBySession(events) {
            return (events || []).filter(function (event) {
                return Number(event.endMinutes) > 300;
            }).filter(matchesSession);
        }

        function selectedDay() {
            if (!state.data || !Array.isArray(state.data.days)) return null;
            for (var index = 0; index < state.data.days.length; index++) {
                if (state.data.days[index].date === state.date) return state.data.days[index];
            }
            return null;
        }

        function eventCard(event) {
            var links = registrationLinks(event);
            var tag = 'article';
            var attributes = '';
            var classes = 'ifrd-calendar-day-event is-' + escapeHtml(event.rinkKey) + ' type-' + sessionColorIndex(event);

            if (links.length === 1) {
                tag = 'a';
                classes += ' is-registerable';
                attributes = ' href="' + escapeHtml(links[0].url) + '" target="_blank" rel="noopener noreferrer" aria-label="Register for ' + escapeHtml(event.title) + '"';
            } else if (links.length > 1) {
                tag = 'button';
                classes += ' is-registerable';
                attributes = ' type="button" data-register-options="' + registerOptionsAttribute(links) + '" aria-haspopup="dialog" aria-label="Choose registration for ' + escapeHtml(event.title) + '"';
            }

            return '<' + tag + ' class="' + classes + '" style="' + eventColorStyle(event) + '"' + attributes + '>' +
                '<span class="ifrd-calendar-day-event-time">' + escapeHtml(event.startLabel) + ' – ' + escapeHtml(event.endLabel) + '</span>' +
                '<span class="ifrd-calendar-day-event-main"><span class="ifrd-calendar-day-event-title">' + escapeHtml(event.title) + '</span>' +
                '<span class="ifrd-calendar-rink-pill">' + escapeHtml(event.rink) + '</span>' +
                (links.length ? '<span class="ifrd-calendar-register-label">Register ↗</span>' : '') + '</span>' +
                '</' + tag + '>';
        }

        function dayRinkSection(key, title, events) {
            var items = events || [];
            var body = items.length
                ? items.map(eventCard).join('')
                : '<div class="ifrd-calendar-empty">No events scheduled.</div>';

            return '<section class="ifrd-calendar-day-rink" data-rink="' + escapeHtml(key) + '">' +
                '<h2>' + escapeHtml(title) + '</h2>' +
                '<div class="ifrd-calendar-day-events">' + body + '</div>' +
                '</section>';
        }

        function rangeForEvents(events) {
            var ends = (events || []).map(function (event) {
                return Number(event.endMinutes);
            }).filter(function (value) {
                return Number.isFinite(value);
            });

            if (!ends.length) return { startHour: 5, endHour: 22 };

            var startHour = 5;
            var endHour = Math.min(24, Math.ceil(Math.max.apply(Math, ends) / 60));
            if (endHour - startHour < 6) endHour = Math.min(24, startHour + 6);
            return { startHour: startHour, endHour: endHour };
        }

        function renderDay() {
            var day = selectedDay();
            if (!day) {
                content.innerHTML = '<div class="ifrd-calendar-empty">No schedule data is available for this date.</div>';
                return;
            }

            var lanes = [];
            if (state.rink === 'all' || state.rink === 'gold') {
                lanes.push({ key: 'gold', title: config.goldTitle || 'Gold Rink', events: filterBySession(day.gold) });
            }
            if (state.rink === 'all' || state.rink === 'silver') {
                lanes.push({ key: 'silver', title: config.silverTitle || 'Silver Rink', events: filterBySession(day.silver) });
            }

            var visibleEvents = [];
            lanes.forEach(function (lane) {
                visibleEvents = visibleEvents.concat(lane.events);
            });
            var visibleCount = visibleEvents.length;

            var summary = '<p class="ifrd-calendar-day-summary">' +
                escapeHtml(visibleCount) + ' schedule ' + (visibleCount === 1 ? 'item' : 'items') + ' for ' + escapeHtml(prettyDate(day.date)) + '.</p>';

            if (!visibleCount) {
                content.innerHTML = summary + '<div class="ifrd-calendar-empty">No events scheduled.</div>';
                return;
            }

            var range = rangeForEvents(visibleEvents);
            var bodyHeight = (range.endHour - range.startHour) * 72;
            var minimumWidth = lanes.length > 1 ? 650 : 390;
            var html = summary + '<div class="ifrd-calendar-day-timeline-scroll"><div class="ifrd-calendar-day-timeline-grid" style="grid-template-columns:70px repeat(' + lanes.length + ',minmax(280px,1fr));min-width:' + minimumWidth + 'px">';

            html += '<div class="ifrd-calendar-time-head">Time</div>';
            lanes.forEach(function (lane) {
                html += '<div class="ifrd-calendar-day-rink-head is-' + escapeHtml(lane.key) + '">' + escapeHtml(lane.title) + '</div>';
            });

            html += '<div class="ifrd-calendar-time-body" style="height:' + bodyHeight + 'px">';
            for (var hour = range.startHour; hour <= range.endHour; hour++) {
                html += '<span class="ifrd-calendar-time-label" style="top:' + ((hour - range.startHour) * 72) + 'px">' + escapeHtml(hourLabel(hour)) + '</span>';
            }
            html += '</div>';

            lanes.forEach(function (lane) {
                html += '<div class="ifrd-calendar-day-body ifrd-calendar-day-timeline-body show-' + escapeHtml(lane.key) + (day.isToday ? ' is-today' : '') + '" style="height:' + bodyHeight + 'px">';
                html += lane.events.map(function (event) { return weekEvent(event, range, true); }).join('');
                html += '</div>';
            });

            html += '</div></div>';

            content.innerHTML = html;
        }

        function filteredEvents(day) {
            if (state.rink === 'gold') return filterBySession(day.gold);
            if (state.rink === 'silver') return filterBySession(day.silver);
            return filterBySession((day.gold || []).concat(day.silver || []));
        }

        function calendarRange() {
            var events = [];
            (state.data.days || []).forEach(function (day) {
                events = events.concat(filteredEvents(day));
            });

            return rangeForEvents(events);
        }

        function hourLabel(hour) {
            var normalized = hour % 24;
            var suffix = normalized >= 12 ? 'pm' : 'am';
            var display = normalized % 12 || 12;
            return display + ':00 ' + suffix;
        }

        function weekEvent(event, range, singleLane) {
            var pixelsPerMinute = 72 / 60;
            var visibleStart = Math.max(Number(event.startMinutes), range.startHour * 60);
            var top = Math.max(0, (visibleStart - (range.startHour * 60)) * pixelsPerMinute);
            var naturalHeight = (Number(event.endMinutes) - visibleStart) * pixelsPerMinute;
            var height = Math.max(29, naturalHeight - 3);
            var left = singleLane ? 2 : (event.rinkKey === 'gold' ? 2 : 51);
            var width = singleLane ? 96 : 47;
            var sizeClass = height < 42 ? ' is-short' : (height >= 78 ? ' is-tall' : '');
            var classes = 'ifrd-calendar-week-event is-' + event.rinkKey + ' type-' + sessionColorIndex(event) + sizeClass;
            var style = 'top:' + top.toFixed(1) + 'px;height:' + height.toFixed(1) + 'px;left:' + left + '%;width:' + width + '%;' + eventColorStyle(event);
            var title = event.startLabel + '–' + event.endLabel + ' — ' + event.rink + ' — ' + event.title;
            var links = registrationLinks(event);
            var tag = 'div';
            var attributes = ' tabindex="0"';

            if (links.length === 1) {
                tag = 'a';
                classes += ' is-registerable';
                attributes = ' href="' + escapeHtml(links[0].url) + '" target="_blank" rel="noopener noreferrer" aria-label="Register for ' + escapeHtml(event.title) + ' — ' + escapeHtml(event.startLabel) + '–' + escapeHtml(event.endLabel) + '"';
                title = 'Register — ' + title;
            } else if (links.length > 1) {
                tag = 'button';
                classes += ' is-registerable';
                attributes = ' type="button" data-register-options="' + registerOptionsAttribute(links) + '" aria-haspopup="dialog" aria-label="Choose registration for ' + escapeHtml(event.title) + '"';
                title = 'Choose registration — ' + title;
            }

            return '<' + tag + ' class="' + classes + '" style="' + style + '" title="' + escapeHtml(title) + '"' + attributes + '>' +
                '<span class="ifrd-calendar-week-event-title">' + escapeHtml(event.title) + '</span>' +
                (links.length ? '<span class="ifrd-calendar-register-icon" aria-hidden="true">↗</span>' : '') +
                '</' + tag + '>';
        }

        function renderWeek() {
            var days = state.data && Array.isArray(state.data.days) ? state.data.days : [];
            if (!days.length) {
                content.innerHTML = '<div class="ifrd-calendar-empty">No weekly schedule data is available.</div>';
                return;
            }

            var range = calendarRange();
            var bodyHeight = (range.endHour - range.startHour) * 72;
            var singleLane = state.rink !== 'all';
            var html = '<div class="ifrd-calendar-week-scroll"><div class="ifrd-calendar-week-grid">';

            html += '<div class="ifrd-calendar-time-head">Time</div>';
            days.forEach(function (day) {
                html += '<div class="ifrd-calendar-day-head' + (day.isToday ? ' is-today' : '') + '">' +
                    '<div class="ifrd-calendar-day-name">' + escapeHtml(day.dayName) + '</div>' +
                    '<div class="ifrd-calendar-day-number">' + escapeHtml(day.dayNumber) + '</div>' +
                    '<div class="ifrd-calendar-lane-labels">' +
                    (state.rink !== 'silver' ? '<span>Gold</span>' : '') +
                    (state.rink !== 'gold' ? '<span>Silver</span>' : '') +
                    '</div></div>';
            });

            html += '<div class="ifrd-calendar-time-body" style="height:' + bodyHeight + 'px">';
            for (var hour = range.startHour; hour <= range.endHour; hour++) {
                html += '<span class="ifrd-calendar-time-label" style="top:' + ((hour - range.startHour) * 72) + 'px">' + escapeHtml(hourLabel(hour)) + '</span>';
            }
            html += '</div>';

            days.forEach(function (day) {
                var events = filteredEvents(day);
                html += '<div class="ifrd-calendar-day-body show-' + escapeHtml(state.rink) + (day.isToday ? ' is-today' : '') + '" style="height:' + bodyHeight + 'px">';
                html += events.map(function (event) { return weekEvent(event, range, singleLane); }).join('');
                html += '</div>';
            });

            html += '</div></div>';
            content.innerHTML = html;
        }

        function render() {
            updateControls();
            if (!state.data) return;
            if (state.view === 'day') renderDay();
            else renderWeek();
            state.hasRendered = true;
        }

        function statusMessage(data) {
            if (data.warning) return data.warning;
            var message = data.total + ' events this week';
            if (data.generatedAt) message += ' • Updated ' + updatedTime(data.generatedAt);
            if (['cached', 'preloaded', 'preloaded-stale'].indexOf(data.cacheStatus) !== -1) message += ' • Cached';
            return message;
        }

        async function fetchWeek(date, bypassMemory) {
            var key = mondayFor(date);
            if (!bypassMemory && state.weekCache.has(key)) return state.weekCache.get(key);

            if (config.staticBaseUrl) {
                try {
                    var staticUrl = String(config.staticBaseUrl).replace(/\/?$/, '/') + 'week-' + encodeURIComponent(key) + '.json';
                    var staticResponse = await fetch(staticUrl, {
                        headers: { 'Accept': 'application/json' },
                        cache: 'no-store'
                    });
                    if (staticResponse.ok) {
                        var staticPayload = await staticResponse.json();
                        if (staticPayload && staticPayload.weekStart === key && Array.isArray(staticPayload.days)) {
                            state.weekCache.set(staticPayload.weekStart, staticPayload);
                            return staticPayload;
                        }
                    }
                } catch (ignore) {}
            }

            var body = new URLSearchParams();
            body.set('action', config.action);
            body.set('nonce', config.nonce);
            body.set('date', date);

            var response = await fetch(config.ajaxUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString(),
                credentials: 'same-origin'
            });
            var payload = await response.json();

            if (!response.ok || !payload.success) {
                var message = payload && payload.data && payload.data.message
                    ? payload.data.message
                    : 'Unable to load the rink schedule.';
                throw new Error(message);
            }

            state.weekCache.set(payload.data.weekStart, payload.data);
            return payload.data;
        }

        async function load(date, bypassMemory) {
            state.date = date;
            updateControls();
            setUpdating(true);
            status.classList.remove('is-warning');
            status.textContent = state.hasRendered ? 'Updating schedule…' : 'Loading schedule…';

            try {
                state.data = await fetchWeek(date, bypassMemory);
                updateSessionOptions();
                status.textContent = statusMessage(state.data);
                status.classList.toggle('is-warning', Boolean(state.data.warning));
                render();
            } catch (error) {
                status.textContent = error.message;
                status.classList.add('is-warning');
                if (!state.hasRendered) {
                    content.innerHTML = '<div class="ifrd-calendar-error">' + escapeHtml(error.message) + '</div>';
                }
            } finally {
                setUpdating(false);
            }
        }

        viewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                state.view = button.getAttribute('data-view') === 'day' ? 'day' : 'week';
                render();
            });
        });

        dateInput.addEventListener('change', function () {
            if (dateInput.value) load(dateInput.value, false);
        });

        rinkSelect.addEventListener('change', function () {
            state.rink = rinkSelect.value || 'all';
            render();
        });

        if (sessionSelect) {
            sessionSelect.addEventListener('change', function () {
                state.sessionType = sessionSelect.value || 'all';
                render();
            });
        }

        var registrationDialog = document.createElement('dialog');
        registrationDialog.className = 'ifrd-calendar-register-dialog';
        registrationDialog.innerHTML = '<div class="ifrd-calendar-register-dialog-inner">' +
            '<button type="button" class="ifrd-calendar-register-close" data-register-close aria-label="Close registration choices">×</button>' +
            '<h2>Choose a registration</h2>' +
            '<div class="ifrd-calendar-register-options" data-register-dialog-options></div>' +
            '</div>';
        root.appendChild(registrationDialog);

        root.addEventListener('click', function (event) {
            var closeButton = event.target.closest('[data-register-close]');
            if (closeButton) {
                if (typeof registrationDialog.close === 'function') registrationDialog.close();
                else registrationDialog.removeAttribute('open');
                return;
            }

            var trigger = event.target.closest('[data-register-options]');
            if (!trigger || !root.contains(trigger)) return;

            var links = [];
            try {
                links = JSON.parse(trigger.getAttribute('data-register-options') || '[]');
            } catch (ignore) {}
            links = registrationLinks({ registrationLinks: links, title: 'Register' });
            if (!links.length) return;

            registrationDialog.querySelector('[data-register-dialog-options]').innerHTML = links.map(function (link) {
                return '<a href="' + escapeHtml(link.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(link.title) + '<span aria-hidden="true">↗</span></a>';
            }).join('');

            if (typeof registrationDialog.showModal === 'function') {
                registrationDialog.showModal();
            } else {
                registrationDialog.setAttribute('open', 'open');
            }
        });

        registrationDialog.addEventListener('click', function (event) {
            if (event.target === registrationDialog) {
                if (typeof registrationDialog.close === 'function') registrationDialog.close();
                else registrationDialog.removeAttribute('open');
            }
        });

        todayButton.addEventListener('click', function () {
            load(config.initialDate || dateValue(new Date()), false);
        });

        previousButton.addEventListener('click', function () {
            load(addDays(state.data ? state.data.weekStart : mondayFor(state.date), -7), false);
        });

        nextButton.addEventListener('click', function () {
            load(addDays(state.data ? state.data.weekStart : mondayFor(state.date), 7), false);
        });

        if (initialData && initialData.weekStart === mondayFor(state.date) && Array.isArray(initialData.days)) {
            state.data = initialData;
            state.weekCache.set(initialData.weekStart, initialData);
            updateSessionOptions();
            status.textContent = statusMessage(initialData);
            status.classList.toggle('is-warning', Boolean(initialData.warning));
            render();
        }

        updateControls();
        load(state.date, false);
        window.setInterval(function () {
            load(state.date, true);
        }, Number(config.refreshMs) || 300000);
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-ifrd-calendar]'), function (root) {
            if (root.getAttribute('data-initialized') === '1') return;
            root.setAttribute('data-initialized', '1');
            initialize(root);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
