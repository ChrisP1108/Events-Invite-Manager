/* global ajaxurl, eimMenuItemsAdmin */

/**
 * Admin Food & Beverages page — drives the two independent live-search tables.
 */
(() => {
    'use strict';

    const config = window.eimMenuItemsAdmin ?? {};

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, String(v));
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

    // -----------------------------------------------------------------------
    // MenuItemTable — live search for one type (food or beverage)
    // -----------------------------------------------------------------------
    class MenuItemTable {
        #type; #table; #tbody; #search; #field; #count; #spinner; #sort; #order;

        constructor(type) {
            this.#type    = type;
            this.#table   = document.getElementById(`eim-menu-${type}-table`);
            this.#tbody   = document.getElementById(`eim-menu-${type}-table-body`);
            this.#search  = document.getElementById(`eim-menu-${type}-search`);
            this.#field   = document.getElementById(`eim-menu-${type}-search-field`);
            this.#count   = document.getElementById(`eim-menu-${type}-count`);
            this.#spinner = document.getElementById(`eim-menu-${type}-loading`);

            if (!this.#table || !this.#tbody || !config.searchNonce) return;

            this.#sort  = this.#table.dataset.sort  || 'label';
            this.#order = this.#table.dataset.order || 'asc';

            this.#search?.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'label';
                    this.#order = link.dataset.order || 'asc';
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_menu_items', {
                    nonce: config.searchNonce,
                    type:  this.#type,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                    field: this.#field?.value  || '',
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
            } catch (e) {
                console.error('[EIM] Menu item search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;

            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';

                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.searchNonce) {
            new MenuItemTable('food');
            new MenuItemTable('beverage');
        }
    });
})();
