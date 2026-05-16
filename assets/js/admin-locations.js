/* global ajaxurl, eimLocationsAdmin */

/**
 * Admin location list interactions.
 *
 * Drives the Locations page live search and sortable column headers via
 * WordPress AJAX. The nonce and initial table state come from the
 * eimLocationsAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimLocationsAdmin ?? {};

    /**
     * Creates a WordPress admin-ajax URL for the supplied action and params.
     *
     * @param {string} action AJAX action name.
     * @param {Record<string, string|number>} params Query parameters.
     * @returns {URL}
     */
    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);

        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }

        return url;
    };

    /**
     * Returns a debounced wrapper around the provided function.
     *
     * @param {Function} fn Function to debounce.
     * @param {number} delay Delay in milliseconds.
     * @returns {Function}
     */
    const debounce = (fn, delay = 250) => {
        let timer = 0;

        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    /**
     * Manages the AJAX search and sort behaviour for the locations list table.
     */
    class LocationTable {
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

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        constructor() {
            this.#table   = document.getElementById('eim-locations-table');
            this.#tbody   = document.getElementById('eim-locations-table-body');
            this.#search  = document.getElementById('eim-location-search');
            this.#field   = document.getElementById('eim-location-search-field');
            this.#count   = document.getElementById('eim-location-count');
            this.#spinner = document.getElementById('eim-location-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) {
                return;
            }

            this.#sort  = this.#table.dataset.sort  || config.table?.sort  || 'name';
            this.#order = this.#table.dataset.order || config.table?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        /**
         * Fetches matching rows and updates the table body.
         *
         * @returns {Promise<void>}
         */
        async #refresh() {
            this.#setLoading(true);

            try {
                const url = ajaxUrl('eim_search_locations_list', {
                    nonce: config.searchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                    field: this.#field?.value || '',
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();

                if (!success) {
                    return;
                }

                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
            } catch (err) {
                console.error('[EIM] Location search failed:', err);
            } finally {
                this.#setLoading(false);
            }
        }

        /**
         * Shows or hides the WordPress spinner.
         *
         * @param {boolean} isLoading Whether a request is in progress.
         * @returns {void}
         */
        #setLoading(isLoading) {
            if (!this.#spinner) return;

            this.#spinner.classList.toggle('is-active', isLoading);
        }

        /**
         * Updates the visible result count next to the search input.
         *
         * @param {number} count Number of matching locations.
         * @returns {void}
         */
        #updateCount(count) {
            if (!this.#count) return;

            this.#count.textContent = `${count} result${count === 1 ? '' : 's'}`;
        }

        /**
         * Updates sort link state and next-click direction after an AJAX sort.
         *
         * @returns {void}
         */
        #updateSortLinks() {
            if (!this.#table) return;

            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';

                const indicator = link.querySelector('span');
                if (indicator) {
                    indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
                }
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) {
            new LocationTable();
        }
    });
})();
