/* global ajaxurl, eimMessagesAdmin */

/**
 * Global Messages admin sub-page.
 *
 * Drives the live-search/sort/pagination table and handles inline
 * mark-read/unread and delete actions per row.
 *
 * Configuration comes from the eimMessagesAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimMessagesAdmin ?? {};

    // ── Utilities ─────────────────────────────────────────────────────────────

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        return url;
    };

    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    // ── GlobalMessagesTable — live-search, sort, pagination ───────────────────

    class GlobalMessagesTable {
        /** @type {HTMLTableElement|null} */
        #table;
        /** @type {HTMLTableSectionElement|null} */
        #tbody;
        /** @type {HTMLInputElement|null} */
        #search;
        /** @type {HTMLSelectElement|null} */
        #field;
        /** @type {HTMLElement|null} */
        #count;
        /** @type {HTMLElement|null} */
        #spinner;
        /** @type {HTMLSelectElement|null} */
        #perPageSel;
        /** @type {HTMLElement|null} */
        #paginationNav;
        /** @type {string} */
        #sort;
        /** @type {string} */
        #order;
        /** @type {number} */
        #page = 1;
        /** @type {number} */
        #perPage = 10;

        constructor() {
            this.#table         = document.getElementById('eim-global-messages-table');
            this.#tbody         = document.getElementById('eim-global-messages-table-body');
            this.#search        = document.getElementById('eim-global-messages-search');
            this.#field         = document.getElementById('eim-global-messages-search-field');
            this.#count         = document.getElementById('eim-global-messages-count');
            this.#spinner       = document.getElementById('eim-global-messages-loading');
            this.#perPageSel    = document.getElementById('eim-global-messages-search-per-page');
            this.#paginationNav = document.getElementById('eim-global-messages-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'created_at';
            this.#order   = this.#table.dataset.order || config.table?.order || 'desc';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'created_at';
                    this.#order = link.dataset.order || 'desc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#setLoading(true);
            try {
                const url = ajaxUrl('eim_search_messages', {
                    nonce:    config.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (err) {
                console.error('[EIM] Messages search failed:', err);
            } finally {
                this.#setLoading(false);
            }
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (p) => { this.#page = p; this.#refresh(); },
            });
        }

        #setLoading(isLoading) {
            this.#spinner?.classList.toggle('is-active', isLoading);
        }

        #updateCount(count) {
            if (this.#count) this.#count.textContent = `${count} result${count === 1 ? '' : 's'}`;
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // ── Inline row actions ────────────────────────────────────────────────────

    async function toggleRead(btn) {
        const messageId = btn.dataset.messageId;
        const isRead    = btn.dataset.isRead === '1';
        const newRead   = !isRead;

        btn.disabled = true;

        try {
            const body = new URLSearchParams({
                action:     'eim_mark_message_read',
                nonce:      config.markReadNonce,
                message_id: messageId,
                is_read:    newRead ? '1' : '0',
            });
            const response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const { success } = await response.json();
            if (!success) return;

            const row = btn.closest('tr');

            btn.dataset.isRead = newRead ? '1' : '0';
            btn.textContent    = newRead ? 'Mark Unread' : 'Mark Read';

            const badge = row?.querySelector('.eim-msg-status-badge');
            if (badge) {
                badge.textContent = newRead ? 'Read' : 'Unread';
                if (newRead) {
                    badge.style.background = '#f0f0f1';
                    badge.style.color      = '#646970';
                } else {
                    badge.style.background = '#fff3cd';
                    badge.style.color      = '#856404';
                }
            }

            if (row) row.dataset.isRead = newRead ? '1' : '0';
        } catch (err) {
            console.error('[EIM] Mark-read failed:', err);
        } finally {
            btn.disabled = false;
        }
    }

    async function deleteMessage(btn) {
        const messageId = btn.dataset.messageId;
        if (!window.confirm('Delete this message? This cannot be undone.')) return;

        btn.disabled = true;

        try {
            const body = new URLSearchParams({
                action:     'eim_delete_message',
                nonce:      config.deleteNonce,
                message_id: messageId,
            });
            const response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const { success } = await response.json();
            if (!success) { btn.disabled = false; return; }

            btn.closest('tr')?.remove();
        } catch (err) {
            console.error('[EIM] Delete message failed:', err);
            btn.disabled = false;
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        if (!config.table?.enabled) return;

        new GlobalMessagesTable();

        document.getElementById('eim-global-messages-table-body')?.addEventListener('click', (e) => {
            const toggleBtn = e.target.closest('.eim-msg-toggle-read');
            if (toggleBtn) { toggleRead(toggleBtn); return; }

            const deleteBtn = e.target.closest('.eim-msg-delete');
            if (deleteBtn) deleteMessage(deleteBtn);
        });
    });
})();
