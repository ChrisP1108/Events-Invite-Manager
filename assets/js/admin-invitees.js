/* global ajaxurl, eimInviteesAdmin */

/**
 * Admin invitee interactions.
 *
 * Handles the global Invitees page table search/sort flow and the event edit
 * screen's existing-invitee picker. Both flows call WordPress AJAX endpoints
 * using localized nonces from AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimInviteesAdmin ?? {};

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
     * Manages the AJAX search and sort behavior for the global invitee table.
     */
    class InviteeTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLInputElement|null} */
        #search;

        /** @type {HTMLElement|null} */
        #count;

        /** @type {HTMLElement|null} */
        #spinner;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        constructor() {
            this.#table   = document.getElementById('eim-invitees-table');
            this.#tbody   = document.getElementById('eim-invitees-table-body');
            this.#search  = document.getElementById('eim-invitee-search');
            this.#count   = document.getElementById('eim-invitee-count');
            this.#spinner = document.getElementById('eim-invitee-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) {
                return;
            }

            this.#sort  = this.#table.dataset.sort || config.table?.sort || 'last_name';
            this.#order = this.#table.dataset.order || config.table?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort || 'last_name';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        /**
         * Fetches matching table rows and updates the table body.
         *
         * @returns {Promise<void>}
         */
        async #refresh() {
            this.#setLoading(true);

            try {
                const url = ajaxUrl('eim_search_invitees', {
                    nonce: config.searchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
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
                console.error('[EIM] Invitee search failed:', err);
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
         * @param {number} count Number of matching invitees.
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

    /**
     * Manages the event edit screen's AJAX invitee picker.
     */
    class EventInviteePicker {
        /** @type {HTMLInputElement|null} */
        #input;

        /** @type {HTMLInputElement|null} */
        #hidden;

        /** @type {HTMLElement|null} */
        #selected;

        /** @type {HTMLUListElement} */
        #dropdown;

        constructor() {
            this.#input    = document.getElementById('eim_event_invitee_search');
            this.#hidden   = document.getElementById('eim_event_invitee_id');
            this.#selected = document.getElementById('eim_event_invitee_selected');

            if (!this.#input || !this.#hidden || !config.suggestNonce) {
                return;
            }

            this.#dropdown = this.#createDropdown();
            this.#input.parentElement?.classList.add('eim-invitee-picker-positioner');
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', debounce(() => this.#search()));
            this.#input.addEventListener('input', () => {
                this.#hidden.value = '';
                if (this.#selected) this.#selected.textContent = '';
            });
            this.#input.addEventListener('blur', () => {
                window.setTimeout(() => this.#hide(), 150);
            });

            this.#input.closest('form')?.addEventListener('submit', (event) => {
                if (!this.#hidden?.value) {
                    event.preventDefault();
                    window.alert('Please select an invitee from the search results before adding them to this event.');
                }
            });
        }

        /**
         * Fetches invitee suggestions for the current input value.
         *
         * @returns {Promise<void>}
         */
        async #search() {
            const query = this.#input?.value.trim() || '';
            const eventId = this.#input?.dataset.eventId || config.event?.id || 0;

            if (query.length < 2) {
                this.#hide();
                return;
            }

            try {
                const url = ajaxUrl('eim_suggest_invitees', {
                    nonce:    config.suggestNonce,
                    query,
                    event_id: eventId,
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();

                this.#render(success ? data : []);
            } catch (err) {
                console.error('[EIM] Invitee suggestions failed:', err);
                this.#hide();
            }
        }

        /**
         * Renders suggestion results below the input.
         *
         * @param {Array<object>} invitees Matching invitees.
         * @returns {void}
         */
        #render(invitees) {
            this.#dropdown.replaceChildren();

            if (!invitees.length) {
                const empty = document.createElement('li');
                empty.textContent = 'No available invitees found.';
                empty.className = 'eim-invitee-suggestion-empty';
                this.#dropdown.appendChild(empty);
                this.#show();
                return;
            }

            for (const invitee of invitees) {
                const item = document.createElement('li');
                item.className = 'eim-invitee-suggestion';
                item.setAttribute('role', 'option');

                const name = document.createElement('strong');
                name.textContent = invitee.name || '(No name)';
                item.appendChild(name);

                if (invitee.email) {
                    item.appendChild(document.createTextNode(` - ${invitee.email}`));
                }

                if (invitee.phone) {
                    item.appendChild(document.createTextNode(` - ${invitee.phone}`));
                }

                item.addEventListener('mousedown', (event) => {
                    event.preventDefault();
                    this.#select(invitee);
                });

                this.#dropdown.appendChild(item);
            }

            this.#show();
        }

        /**
         * Stores the selected invitee ID and updates the visible selection label.
         *
         * @param {object} invitee Selected invitee payload.
         * @returns {void}
         */
        #select(invitee) {
            if (this.#hidden) {
                this.#hidden.value = String(invitee.id || '');
            }

            if (this.#input) {
                this.#input.value = invitee.name || invitee.label || '';
            }

            if (this.#selected) {
                this.#selected.textContent = invitee.label ? `Selected: ${invitee.label}` : '';
            }

            this.#hide();
        }

        /**
         * Builds the dropdown list element.
         *
         * @returns {HTMLUListElement}
         */
        #createDropdown() {
            const list = document.createElement('ul');
            list.className = 'eim-invitee-suggestions';
            list.setAttribute('role', 'listbox');
            list.style.display = 'none';

            return list;
        }

        /**
         * Shows the suggestions dropdown.
         *
         * @returns {void}
         */
        #show() {
            this.#dropdown.style.display = 'block';
        }

        /**
         * Hides the suggestions dropdown.
         *
         * @returns {void}
         */
        #hide() {
            this.#dropdown.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) {
            new InviteeTable();
        }

        if (config.event?.enabled) {
            new EventInviteePicker();
        }
    });
})();
