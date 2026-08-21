(function () {
    'use strict';

    var config = window.IFPAdminNavigation || {};
    var sections = Array.isArray(config.sections) ? config.sections : [];
    var hiddenNativePages = Array.isArray(config.hiddenNativePages) ? config.hiddenNativePages : [];
    var root = document.querySelector('#toplevel_page_ifp-dashboard .wp-submenu');

    if (!root) return;

    if (hiddenNativePages.length) {
        Array.prototype.forEach.call(root.children, function (row) {
            var anchor = row.firstElementChild;
            if (!anchor || anchor.tagName !== 'A') return;

            var href = anchor.href || anchor.getAttribute('href') || '';
            var hidden = hiddenNativePages.some(function (page) {
                return href.indexOf(page) !== -1;
            });

            if (hidden) row.classList.add('ifp-menu-native-detail');
        });
    }

    if (!sections.length) return;

    function matchingAnchor(slug) {
        var rows = root.children;

        for (var i = 0; i < rows.length; i += 1) {
            var candidate = rows[i].firstElementChild;
            if (!candidate || candidate.tagName !== 'A') continue;

            var href = candidate.getAttribute('href') || '';
            if (href.indexOf('page=' + slug) !== -1) return candidate;
        }

        return null;
    }

    sections.forEach(function (section) {
        if (!section || !section.slug || !Array.isArray(section.items) || !section.items.length) return;

        var anchor = matchingAnchor(section.slug);
        if (!anchor) return;

        var row = anchor.parentElement;
        var childId = 'ifp-menu-children-' + section.slug;
        var children = document.createElement('ul');
        var toggle = document.createElement('button');
        var isCurrent = row.classList.contains('current') || anchor.classList.contains('current');

        row.classList.add('ifp-menu-section');
        if (isCurrent) row.classList.add('is-open');

        children.className = 'ifp-menu-children';
        children.id = childId;
        children.setAttribute('aria-label', anchor.textContent.trim() + ' shortcuts');

        section.items.forEach(function (item) {
            var child = document.createElement('li');
            var link = document.createElement('a');

            link.href = item.url;
            link.textContent = item.label;
            child.appendChild(link);
            children.appendChild(child);
        });

        toggle.type = 'button';
        toggle.className = 'ifp-menu-toggle';
        toggle.setAttribute('aria-controls', childId);
        toggle.setAttribute('aria-expanded', isCurrent ? 'true' : 'false');
        toggle.setAttribute('aria-label', isCurrent ? config.collapseLabel : config.expandLabel);
        toggle.innerHTML = '<span aria-hidden="true"></span>';
        toggle.addEventListener('click', function (event) {
            var willOpen = !row.classList.contains('is-open');

            event.preventDefault();
            event.stopPropagation();
            row.classList.toggle('is-open', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            toggle.setAttribute('aria-label', willOpen ? config.collapseLabel : config.expandLabel);
        });

        row.appendChild(toggle);
        row.appendChild(children);
    });
}());
