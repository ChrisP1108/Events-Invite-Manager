/**
 * Shared EIM admin pagination renderer.
 *
 * Exposes window.eimRenderPagination(nav, {total, perPage, page, onPageChange}).
 * Each table class calls this after every AJAX response to update its nav element.
 */
(() => {
    'use strict';

    /**
     * Returns the page-number sequence to display, inserting '…' where gaps occur.
     *
     * @param {number} current  Active page (1-based).
     * @param {number} total    Total number of pages.
     * @returns {Array<number|'…'>}
     */
    function pageRange(current, total) {
        if (total <= 7) {
            return Array.from({ length: total }, (_, i) => i + 1);
        }
        const pages = [1];
        if (current > 4) pages.push('…');
        const lo = Math.max(2, current - 2);
        const hi = Math.min(total - 1, current + 2);
        for (let i = lo; i <= hi; i++) pages.push(i);
        if (current < total - 3) pages.push('…');
        pages.push(total);
        return pages;
    }

    /**
     * Renders pagination controls into the given nav element.
     *
     * Hides the element when ≤ 1 page is needed (i.e. total ≤ perPage).
     *
     * @param {HTMLElement|null} nav           The <nav> placeholder to populate.
     * @param {object}           opts
     * @param {number}           opts.total         Total number of matching rows.
     * @param {number}           opts.perPage        Rows per page.
     * @param {number}           opts.page           Current page (1-based).
     * @param {Function}         opts.onPageChange   Called with the new page number.
     * @returns {void}
     */
    window.eimRenderPagination = (nav, { total, perPage, page, onPageChange }) => {
        if (!nav) return;

        const totalPages = Math.ceil(total / perPage);

        if (totalPages <= 1) {
            nav.hidden = true;
            nav.replaceChildren();
            return;
        }

        nav.hidden = false;
        const frag = document.createDocumentFragment();

        const mkBtn = (label, targetPage, isCurrent, ariaLabel) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'eim-page-btn' +
                (isCurrent  ? ' eim-page-current'  : '') +
                (btn.disabled ? ' eim-page-disabled' : '');
            btn.textContent = label;
            btn.setAttribute('aria-label', ariaLabel || label);
            if (isCurrent) btn.setAttribute('aria-current', 'page');
            btn.disabled = isCurrent || targetPage < 1 || targetPage > totalPages;
            if (!btn.disabled) {
                btn.addEventListener('click', () => onPageChange(targetPage));
            }
            return btn;
        };

        frag.appendChild(mkBtn('‹', page - 1, false, 'Previous page'));

        for (const p of pageRange(page, totalPages)) {
            if (p === '…') {
                const span = document.createElement('span');
                span.className = 'eim-page-ellipsis';
                span.textContent = '…';
                frag.appendChild(span);
            } else {
                frag.appendChild(mkBtn(String(p), p, p === page, `Page ${p}`));
            }
        }

        frag.appendChild(mkBtn('›', page + 1, false, 'Next page'));

        nav.replaceChildren(frag);
    };

    /**
     * Restores a persisted "rows per page" value from localStorage into a
     * per-page <select>, then returns the resolved number.
     *
     * If the restored value differs from the select's initial value, the
     * server-rendered table was built with the old per-page count, so
     * onChange (if given) is called to trigger a re-fetch at the new size.
     *
     * @param {HTMLSelectElement|null} selectEl   The per-page <select> element.
     * @param {string}                 storageKey localStorage key unique to this table.
     * @param {number}                 fallback   Value to use when nothing is stored.
     * @param {Function}               [onChange] Called with the restored value if it differs from the select's initial value.
     * @returns {number}
     */
    window.eimRestorePerPage = (selectEl, storageKey, fallback = 10, onChange) => {
        try {
            const stored = window.localStorage.getItem(storageKey);
            if (stored && selectEl && [...selectEl.options].some((opt) => opt.value === stored) && selectEl.value !== stored) {
                selectEl.value = stored;
                onChange?.(Number(stored));
            }
        } catch { /* localStorage unavailable */ }
        return Number(selectEl?.value || fallback);
    };

    /**
     * Persists a "rows per page" selection to localStorage.
     *
     * @param {string} storageKey localStorage key unique to this table.
     * @param {number} value      Selected rows-per-page value.
     * @returns {void}
     */
    window.eimPersistPerPage = (storageKey, value) => {
        try {
            window.localStorage.setItem(storageKey, String(value));
        } catch { /* localStorage unavailable */ }
    };
})();
