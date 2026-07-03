/* global ajaxurl, eimInviteesAdmin */

/**
 * Admin invitee interactions.
 *
 * Handles:
 *  - Global Invitees page table search/sort (InviteeTable)
 *  - Connection Groups page table search (ConnectionGroupTable)
 *  - Event edit screen invitee picker + connection-group checkboxes (EventInviteePicker)
 *  - Connection Groups edit page member picker (ConnectionGroupMemberPicker)
 *  - Connection Groups edit page member drag-and-drop reordering (ConnectionGroupMemberSorter)
 *  - Invited Invitees dropdown "Change Order Number" inline editor (EventGroupManager)
 */
(() => {
    'use strict';

    const config = window.eimInviteesAdmin ?? {};

    /**
     * Build an absolute WordPress AJAX URL for the given action and params.
     *
     * @param {string}                       action The wp_ajax_* action name.
     * @param {Record<string,string|number>} params Additional query-string parameters.
     * @returns {URL} The fully constructed URL object.
     */
    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, String(v));
        }
        return url;
    };

    /**
     * Returns a debounced version of the given function that delays invocation
     * until after `delay` milliseconds have elapsed since the last call.
     *
     * @param {Function} fn    The function to debounce.
     * @param {number}   delay Milliseconds to wait before invoking (default 250).
     * @returns {Function} A new debounced function.
     */
    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    /**
     * Escapes special HTML characters in a string to prevent XSS when
     * inserting user-supplied text into innerHTML.
     *
     * @param {string} str The raw string to escape.
     * @returns {string} The HTML-escaped string.
     */
    const escHtml = (str) => {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    };

    // -----------------------------------------------------------------------
    // LocationImageModal — handles thumbnail clicks for venue/lodging images on the event form.
    // -----------------------------------------------------------------------

    class LocationImageModal {
        #overlay = null;
        #image   = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const trigger = event.target.closest('.eim-location-image-thumb');
                if (!trigger) return;
                const fullSrc = trigger.dataset.fullSrc || '';
                if (!fullSrc) return;
                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || 'Location image');
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.#close();
            });
        }

        #ensureModal() {
            if (this.#overlay) return;
            this.#overlay = document.createElement('div');
            this.#overlay.className = 'eim-invitee-image-modal-backdrop';
            this.#overlay.hidden = true;
            const modal = document.createElement('div');
            modal.className = 'eim-invitee-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-invitee-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = 'x';
            this.#image = document.createElement('img');
            this.#image.alt = '';
            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-invitee-image-modal-caption';
            modal.append(close, this.#image, this.#caption);
            this.#overlay.appendChild(modal);
            document.body.appendChild(this.#overlay);
            close.addEventListener('click', () => this.#close());
            this.#overlay.addEventListener('click', (e) => { if (e.target === this.#overlay) this.#close(); });
        }

        #open(src, caption) {
            this.#ensureModal();
            if (!this.#overlay || !this.#image || !this.#caption) return;
            this.#image.src = src;
            this.#caption.textContent = caption;
            this.#overlay.hidden = false;
            document.body.classList.add('eim-invitee-image-modal-open');
        }

        #close() {
            if (!this.#overlay || this.#overlay.hidden) return;
            this.#overlay.hidden = true;
            if (this.#image) this.#image.removeAttribute('src');
            document.body.classList.remove('eim-invitee-image-modal-open');
        }
    }

    // InviteeImageModal + InviteeImagePicker
    // -----------------------------------------------------------------------

    class InviteeImageModal {
        #overlay = null;
        #image = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;

                const trigger = event.target.closest('.eim-invitee-image-thumb');
                if (!trigger) return;

                const fullSrc = trigger.dataset.fullSrc || trigger.getAttribute('href') || '';
                if (!fullSrc) return;

                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || trigger.getAttribute('aria-label') || 'Invitee image');
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.#close();
            });
        }

        #ensureModal() {
            if (this.#overlay) return;

            this.#overlay = document.createElement('div');
            this.#overlay.className = 'eim-invitee-image-modal-backdrop';
            this.#overlay.hidden = true;

            const modal = document.createElement('div');
            modal.className = 'eim-invitee-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-invitee-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = 'x';

            this.#image = document.createElement('img');
            this.#image.alt = '';

            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-invitee-image-modal-caption';

            modal.append(close, this.#image, this.#caption);
            this.#overlay.appendChild(modal);
            document.body.appendChild(this.#overlay);

            close.addEventListener('click', () => this.#close());
            this.#overlay.addEventListener('click', (event) => {
                if (event.target === this.#overlay) this.#close();
            });
        }

        #open(src, caption) {
            this.#ensureModal();
            if (!this.#overlay || !this.#image || !this.#caption) return;

            this.#image.src = src;
            this.#caption.textContent = caption;
            this.#overlay.hidden = false;
            document.body.classList.add('eim-invitee-image-modal-open');
        }

        #close() {
            if (!this.#overlay || this.#overlay.hidden) return;
            this.#overlay.hidden = true;
            if (this.#image) this.#image.removeAttribute('src');
            document.body.classList.remove('eim-invitee-image-modal-open');
        }
    }

    class InviteeImagePicker {
        #field;
        #preview;
        #select;
        #remove;
        #frame = null;

        constructor() {
            this.#field   = document.getElementById('eim_invitee_image_attachment_id');
            this.#preview = document.getElementById('eim_invitee_image_preview');
            this.#select  = document.getElementById('eim_invitee_image_select');
            this.#remove  = document.getElementById('eim_invitee_image_remove');

            if (!this.#field || !this.#preview || !this.#select || !window.wp?.media) return;

            this.#select.addEventListener('click', () => this.#openMediaFrame());
            this.#remove?.addEventListener('click', () => this.#renderSelection(null));
        }

        #openMediaFrame() {
            if (!this.#frame) {
                this.#frame = window.wp.media({
                    title: 'Select Invitee Image',
                    button: { text: 'Use This Image' },
                    library: { type: 'image' },
                    multiple: false,
                });

                this.#frame.on('select', () => {
                    const attachment = this.#frame.state().get('selection').first()?.toJSON();
                    if (!attachment) return;
                    this.#renderSelection({
                        id: attachment.id || 0,
                        title: attachment.title || attachment.filename || 'Invitee image',
                        thumbUrl: attachment.sizes?.thumbnail?.url || attachment.sizes?.medium?.url || attachment.url || '',
                        fullUrl: attachment.sizes?.full?.url || attachment.url || '',
                    });
                });
            }

            this.#frame.open();
        }

        #renderSelection(image) {
            const hasImage = image && Number(image.id) > 0 && image.thumbUrl;
            this.#field.value = hasImage ? String(image.id) : '';
            this.#preview.replaceChildren();

            if (hasImage) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-link eim-invitee-image-thumb';
                button.dataset.fullSrc = image.fullUrl || image.thumbUrl;
                button.dataset.caption = image.title || 'Invitee image';
                button.setAttribute('aria-label', `View full-size image for ${image.title || 'invitee'}`);

                const img = document.createElement('img');
                img.src = image.thumbUrl;
                img.alt = '';
                img.loading = 'lazy';
                button.appendChild(img);
                this.#preview.appendChild(button);
            } else {
                const empty = document.createElement('span');
                empty.className = 'description';
                empty.textContent = 'No image selected.';
                this.#preview.appendChild(empty);
            }

            if (this.#remove) this.#remove.hidden = !hasImage;
            this.#select.textContent = hasImage
                ? (this.#select.dataset.changeLabel || 'Change Image')
                : (this.#select.dataset.selectLabel || 'Select Image');
        }
    }

    /**
     * Shared RSVP Details modal used by event group member menus and invitee
     * edit screen event-response menus.
     */
    class RsvpDetailsModal {
        /** @type {HTMLElement|null} */
        #overlay = null;

        constructor() {
            document.addEventListener('click', (e) => this.#handleClick(e));
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.#closeEventMenus();
                    this.#close();
                }
            });
        }

        /**
         * Routes clicks for event-response dropdowns, Details buttons, and modal close controls.
         *
         * @param {MouseEvent} e
         * @returns {void}
         */
        #handleClick(e) {
            const eventTrigger = e.target.closest('.eim-event-detail-trigger');
            if (eventTrigger) {
                e.preventDefault();
                e.stopPropagation();
                this.#toggleEventMenu(eventTrigger);
                return;
            }

            const detailsTrigger = e.target.closest('.eim-rsvp-details-trigger');
            if (detailsTrigger) {
                e.preventDefault();
                e.stopPropagation();
                this.#closeEventMenus();
                const memberMenu = detailsTrigger.closest('.eim-member-dropdown-menu');
                if (memberMenu) {
                    memberMenu.hidden = true;
                    memberMenu.previousElementSibling?.setAttribute('aria-expanded', 'false');
                }

                const payload = this.#parsePayload(detailsTrigger.dataset.eimRsvpDetails || '');
                if (payload) this.#open(payload);
                return;
            }

            if (e.target.closest('.eim-rsvp-modal-close') || e.target.classList.contains('eim-rsvp-modal-backdrop')) {
                e.preventDefault();
                this.#close();
                return;
            }

            if (!e.target.closest('.eim-event-detail-dropdown')) {
                this.#closeEventMenus();
            }
        }

        /**
         * Toggles an invitee-edit event Details dropdown.
         *
         * @param {HTMLElement} trigger
         * @returns {void}
         */
        #toggleEventMenu(trigger) {
            const menu = trigger.nextElementSibling;
            if (!menu) return;

            const isOpen = !menu.hidden;
            this.#closeEventMenus();

            if (!isOpen) {
                menu.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
            }
        }

        /**
         * Closes all invitee-edit event Details dropdowns.
         *
         * @returns {void}
         */
        #closeEventMenus() {
            document.querySelectorAll('.eim-event-detail-menu').forEach((menu) => {
                menu.hidden = true;
                menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
            });
        }

        /**
         * Parses a JSON details payload stored in a data attribute.
         *
         * @param {string} raw
         * @returns {object|null}
         */
        #parsePayload(raw) {
            if (!raw) return null;
            try {
                return JSON.parse(raw);
            } catch (err) {
                console.error('[EIM] Could not parse RSVP details payload:', err);
                return null;
            }
        }

        /**
         * Opens the modal for the given details payload.
         *
         * @param {{title?: string, sections?: Array<{heading?: string, rows?: Array<{label?: string, value?: string}>}>}} payload
         * @returns {void}
         */
        #open(payload) {
            this.#close();

            const overlay = document.createElement('div');
            overlay.className = 'eim-rsvp-modal-backdrop';
            overlay.setAttribute('role', 'presentation');

            const modal = document.createElement('div');
            modal.className = 'eim-rsvp-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');

            const header = document.createElement('div');
            header.className = 'eim-rsvp-modal-header';

            const title = document.createElement('h2');
            title.textContent = payload.title || 'RSVP Details';
            header.appendChild(title);

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-rsvp-modal-close';
            close.setAttribute('aria-label', 'Close RSVP details');
            close.textContent = 'Close';
            header.appendChild(close);
            modal.appendChild(header);

            const body = document.createElement('div');
            body.className = 'eim-rsvp-modal-body';

            for (const section of payload.sections || []) {
                const sectionEl = document.createElement('section');
                sectionEl.className = 'eim-rsvp-modal-section';

                if (section.heading) {
                    const heading = document.createElement('h3');
                    heading.textContent = section.heading;
                    sectionEl.appendChild(heading);
                }

                const list = document.createElement('dl');
                for (const row of section.rows || []) {
                    const dt = document.createElement('dt');
                    dt.textContent = row.label || '';
                    const dd = document.createElement('dd');
                    const value = row.value === null || row.value === undefined ? '' : String(row.value).trim();
                    dd.textContent = value || 'Not provided';
                    list.append(dt, dd);
                }
                sectionEl.appendChild(list);
                body.appendChild(sectionEl);
            }

            modal.appendChild(body);
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            this.#overlay = overlay;
            close.focus();
        }

        /**
         * Closes the active modal.
         *
         * @returns {void}
         */
        #close() {
            this.#overlay?.remove();
            this.#overlay = null;
        }
    }

    // -----------------------------------------------------------------------
    // AccordionSortTable — client-side column sort for group accordion tables
    // -----------------------------------------------------------------------

    /**
     * Enables click-to-sort on every <th data-sort="N"> inside an
     * .eim-accordion-sortable table. Sorts <tbody> rows by each cell's
     * data-val attribute, toggling asc/desc on repeated clicks.
     */
    class AccordionSortTable {
        constructor() {
            document.addEventListener('click', (e) => {
                const th = e.target.closest('th[data-sort]');
                if (!th) return;
                const table = th.closest('.eim-accordion-sortable');
                if (!table) return;
                this.#sort(table, th);
            });
        }

        /**
         * Sorts the table rows by the column index encoded in th[data-sort].
         *
         * @param {HTMLTableElement} table
         * @param {HTMLElement}      th
         */
        #sort(table, th) {
            const colIndex  = parseInt(th.dataset.sort, 10);
            const prevOrder = th.dataset.order || '';
            const newOrder  = prevOrder === 'asc' ? 'desc' : 'asc';

            // Reset all headers in this table, then set the clicked one.
            table.querySelectorAll('th[data-sort]').forEach((h) => {
                h.dataset.order = '';
                const ind = h.querySelector('.eim-sort-indicator');
                if (ind) ind.textContent = '';
            });
            th.dataset.order = newOrder;
            const ind = th.querySelector('.eim-sort-indicator');
            if (ind) ind.textContent = newOrder === 'asc' ? ' ▲' : ' ▼';

            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const rows = [...tbody.querySelectorAll('tr')];
            rows.sort((a, b) => {
                const aVal = a.cells[colIndex]?.dataset.val ?? '';
                const bVal = b.cells[colIndex]?.dataset.val ?? '';
                const cmp  = aVal.localeCompare(bVal, undefined, { numeric: true, sensitivity: 'base' });
                return newOrder === 'asc' ? cmp : -cmp;
            });

            rows.forEach((row) => tbody.appendChild(row));
        }
    }

    // -----------------------------------------------------------------------
    // InviteeTable — global Invitees list search/sort
    // -----------------------------------------------------------------------

    /**
     * Manages the AJAX search and sort behaviour for the global invitees list table.
     */
    class InviteeTable {
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
            this.#table        = document.getElementById('eim-invitees-table');
            this.#tbody        = document.getElementById('eim-invitees-table-body');
            this.#search       = document.getElementById('eim-invitee-search');
            this.#field        = document.getElementById('eim-invitee-search-field');
            this.#count        = document.getElementById('eim-invitee-count');
            this.#spinner      = document.getElementById('eim-invitee-loading');
            this.#perPageSel   = document.getElementById('eim-invitee-search-per-page');
            this.#paginationNav = document.getElementById('eim-invitee-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'last_name';
            this.#order   = this.#table.dataset.order || config.table?.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_invitees', 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_invitees', this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'last_name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        /**
         * Fetches fresh table rows from the server using the current search query,
         * field, sort column, and sort direction, then replaces the tbody contents.
         *
         * @returns {Promise<void>}
         */
        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_invitees', {
                    nonce:    config.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (e) {
                console.error('[EIM] Invitee search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
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

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent    = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const span = link.querySelector('span');
                if (span) span.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // -----------------------------------------------------------------------
    // ConnectionGroupTable — Connection Groups list live search
    // -----------------------------------------------------------------------

    /**
     * Manages the AJAX search and sort behaviour for the connection groups list table.
     */
    class ConnectionGroupTable {
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
            this.#table        = document.getElementById('eim-connection-groups-table');
            this.#tbody        = document.getElementById('eim-connection-groups-table-body');
            this.#search       = document.getElementById('eim-connection-group-search');
            this.#field        = document.getElementById('eim-connection-group-search-field');
            this.#count        = document.getElementById('eim-connection-group-count');
            this.#spinner      = document.getElementById('eim-connection-group-loading');
            this.#perPageSel   = document.getElementById('eim-connection-group-search-per-page');
            this.#paginationNav = document.getElementById('eim-connection-group-search-pagination');

            if (!this.#tbody || !this.#search || !config.connectionGroupSearchNonce) return;

            this.#sort    = this.#table?.dataset.sort  || config.connectionGroupTable?.sort  || 'name';
            this.#order   = this.#table?.dataset.order || config.connectionGroupTable?.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_connection_groups', 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_connection_groups', this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of (this.#table?.querySelectorAll('.eim-sort-link') ?? [])) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table?.dataset.total || 0));
        }

        /**
         * Fetches fresh table rows from the server using the current search query,
         * field, sort column, and sort direction, then replaces the tbody contents.
         *
         * @returns {Promise<void>}
         */
        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_connection_groups', {
                    nonce:    config.connectionGroupSearchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#renderPagination(Number(data.total || 0));
            } catch (e) {
                console.error('[EIM] Connection group search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
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

    // -----------------------------------------------------------------------
    // EventInviteePicker — event edit add-invitee flow with group checkboxes
    // -----------------------------------------------------------------------

    /**
     * Handles the add-invitee search flow on the event edit screen, including
     * connected-invitees checkboxes that appear after an invitee is selected.
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

        /** @type {HTMLElement|null} */
        #connectWrap;

        /** @type {HTMLElement|null} */
        #connectList;

        /**
         * Locates all required DOM elements and registers input, blur, and form
         * submit listeners. Aborts silently if the picker is not present on the page.
         */
        constructor() {
            this.#input       = document.getElementById('eim_event_invitee_search');
            this.#hidden      = document.getElementById('eim_event_invitee_id');
            this.#selected    = document.getElementById('eim_event_invitee_selected');
            this.#connectWrap = document.getElementById('eim-connected-invitees-wrap');
            this.#connectList = document.getElementById('eim-connected-invitees-list');

            if (!this.#input || !this.#hidden || !config.suggestNonce) return;

            this.#dropdown = this.#makeList();
            this.#input.parentElement?.classList.add('eim-invitee-picker-positioner');
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', debounce(() => this.#search()));
            this.#input.addEventListener('input', () => {
                this.#hidden.value = '';
                if (this.#selected) this.#selected.textContent = '';
                this.#hideConnections();
            });
            this.#input.addEventListener('blur', () => setTimeout(() => this.#dropdown.style.display = 'none', 150));

            this.#input.closest('form')?.addEventListener('submit', (e) => {
                if (!this.#hidden?.value) {
                    e.preventDefault();
                    alert('Please select an invitee from the search results before adding them to this event.');
                }
            });
        }

        /**
         * Queries the server for invitees matching the current input value and
         * renders the results into the suggestion dropdown.
         *
         * @returns {Promise<void>}
         */
        async #search() {
            const query   = this.#input?.value.trim() || '';
            const eventId = this.#input?.dataset.eventId || config.event?.id || 0;
            if (query.length < 2) { this.#dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_invitees', {
                    nonce: config.suggestNonce, query, event_id: eventId,
                }), { credentials: 'same-origin' })).json();
                this.#renderDropdown(success ? data : []);
            } catch (e) {
                console.error('[EIM] Invitee suggest failed:', e);
                this.#dropdown.style.display = 'none';
            }
        }

        /**
         * Populates the suggestion dropdown with the given invitee objects.
         *
         * @param {Array<object>} invitees Array of invitee result objects from the server.
         * @returns {void}
         */
        #renderDropdown(invitees) {
            this.#dropdown.replaceChildren();
            if (!invitees.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No available invitees found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const inv of invitees) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = inv.name || '';
                    li.appendChild(name);
                    if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                    if (inv.phone) li.appendChild(document.createTextNode(` - ${inv.phone}`));
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); this.#select(inv); });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        /**
         * Commits the chosen invitee to the hidden field, updates the visible
         * selection label, closes the dropdown, and fetches any connections.
         *
         * @param {object} inv The invitee object returned by the server.
         * @returns {void}
         */
        #select(inv) {
            if (this.#hidden)   this.#hidden.value = String(inv.id || '');
            if (this.#input)    this.#input.value  = inv.name || inv.label || '';
            if (this.#selected) this.#selected.textContent = inv.label ? `Selected: ${inv.label}` : '';
            this.#dropdown.style.display = 'none';
            this.#fetchConnections(inv.id);
        }

        /**
         * Fetches the connections associated with the given invitee for the current
         * event and renders them as checkboxes.
         *
         * @param {number} inviteeId The ID of the selected invitee.
         * @returns {Promise<void>}
         */
        async #fetchConnections(inviteeId) {
            if (!inviteeId || !this.#connectWrap || !this.#connectList) return;
            const eventId = this.#input?.dataset.eventId || config.event?.id || 0;

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_get_connections_for_event', {
                    nonce: config.suggestNonce, invitee_id: inviteeId, event_id: eventId,
                }), { credentials: 'same-origin' })).json();

                if (!success || !data?.length) { this.#hideConnections(); return; }
                this.#renderConnections(data);
            } catch (e) {
                console.error('[EIM] Connections fetch failed:', e);
                this.#hideConnections();
            }
        }

        /**
         * Renders the connected-invitee rows as labelled checkboxes inside the
         * connections wrapper, then makes the wrapper visible.
         *
         * @param {Array<object>} connections Array of connection objects from the server.
         * @returns {void}
         */
        #renderConnections(connections) {
            this.#connectList.replaceChildren();
            for (const conn of connections) {
                const row = document.createElement('div');
                row.className = 'eim-connected-invitee-row';
                const groupLabel = conn.group_name
                    ? `<span style="color:#646970;font-size:11px;"> (${escHtml(conn.group_name)})</span>`
                    : '';
                if (conn.already_invited) {
                    row.innerHTML = `<input type="checkbox" disabled>
                        <label>${escHtml(conn.name)}${groupLabel}
                            <span class="eim-already-invited">(already invited)</span>
                        </label>`;
                } else {
                    const id = `eim-conn-${conn.id}`;
                    row.innerHTML = `<input type="checkbox" id="${id}"
                        name="connected_invitee_ids[]" value="${Number(conn.id)}" checked>
                        <label for="${id}">${escHtml(conn.name)}${groupLabel}</label>`;
                }
                this.#connectList.appendChild(row);
            }
            this.#connectWrap.style.display = 'block';
        }

        /**
         * Hides the connected-invitees wrapper and clears its contents.
         *
         * @returns {void}
         */
        #hideConnections() {
            if (this.#connectWrap) this.#connectWrap.style.display = 'none';
            if (this.#connectList) this.#connectList.replaceChildren();
        }

        /**
         * Creates and returns a styled, hidden `<ul>` element to serve as the
         * autocomplete suggestion dropdown.
         *
         * @returns {HTMLUListElement} The newly created dropdown list element.
         */
        #makeList() {
            const ul = document.createElement('ul');
            ul.className = 'eim-invitee-suggestions';
            ul.setAttribute('role', 'listbox');
            ul.style.display = 'none';
            return ul;
        }
    }

    // -----------------------------------------------------------------------
    // ConnectionGroupMemberPicker — member autocomplete on group edit page
    // -----------------------------------------------------------------------

    /**
     * Handles member autocomplete on the connection group edit page.
     */
    class ConnectionGroupMemberPicker {
        /** @type {HTMLInputElement|null} */
        #input;

        /** @type {HTMLInputElement|null} */
        #hidden;

        /** @type {HTMLElement|null} */
        #selected;

        /** @type {HTMLUListElement} */
        #dropdown;

        /** @type {number} */
        #groupId;

        /** @type {number[]} */
        #existingIds;

        /**
         * Locates all required DOM elements, reads the group ID and existing member
         * IDs from data attributes, then wires up input, blur, and submit listeners.
         */
        constructor() {
            this.#input    = document.getElementById('eim_cg_member_search');
            this.#hidden   = document.getElementById('eim_cg_member_invitee_id');
            this.#selected = document.getElementById('eim_cg_member_selected');

            if (!this.#input || !this.#hidden || !config.suggestNonce) return;

            this.#groupId     = Number(this.#input.dataset.groupId || 0);
            this.#existingIds = (this.#input.dataset.existingIds || '')
                .split(',').map(Number).filter(Boolean);

            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#input.parentElement?.classList.add('eim-invitee-picker-positioner');
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', debounce(() => this.#search()));
            this.#input.addEventListener('blur', () => setTimeout(() => this.#dropdown.style.display = 'none', 150));

            this.#input.closest('form')?.addEventListener('submit', (e) => {
                if (!this.#hidden?.value) {
                    e.preventDefault();
                    alert('Please select an invitee from the search results.');
                }
            });
        }

        /**
         * Queries the server for invitees matching the current input value,
         * excluding already-existing members, then renders the dropdown.
         *
         * @returns {Promise<void>}
         */
        async #search() {
            const query = this.#input?.value.trim() || '';
            if (query.length < 2) { this.#dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_cg_members', {
                    nonce:       config.suggestNonce,
                    query,
                    group_id:    this.#groupId,
                    exclude_ids: this.#existingIds.join(','),
                }), { credentials: 'same-origin' })).json();

                this.#render(success ? data : []);
            } catch (e) {
                console.error('[EIM] Member suggest failed:', e);
                this.#dropdown.style.display = 'none';
            }
        }

        /**
         * Populates the suggestion dropdown with the given invitee objects.
         *
         * @param {Array<object>} invitees Array of invitee result objects from the server.
         * @returns {void}
         */
        #render(invitees) {
            this.#dropdown.replaceChildren();
            if (!invitees.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching invitees found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const inv of invitees) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = inv.name || '';
                    li.appendChild(name);
                    if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); this.#select(inv); });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        /**
         * Commits the chosen invitee to the hidden field and updates the visible
         * selection label, then closes the dropdown.
         *
         * @param {object} inv The invitee object returned by the server.
         * @returns {void}
         */
        #select(inv) {
            if (this.#hidden)   this.#hidden.value = String(inv.id || '');
            if (this.#input)    this.#input.value  = inv.name || inv.label || '';
            if (this.#selected) this.#selected.textContent = inv.label ? `Selected: ${inv.label}` : '';
            this.#dropdown.style.display = 'none';
        }
    }

    // -----------------------------------------------------------------------
    // EventGroupManager — member dropdown + add-member per group row
    // Uses full event delegation so it works after AJAX tbody replacement.
    // -----------------------------------------------------------------------

    /**
     * Handles the member dropdown toggle, add-member search, and add-connection
     * flows on the groups table using full event delegation so all interactions
     * survive AJAX tbody replacements.
     */
    class EventGroupManager {
        /**
         * Per-input debounce timer handles keyed by the input element itself,
         * so concurrent searches on different rows don't interfere.
         *
         * @type {WeakMap}
         */
        #debounceMap = new WeakMap();

        /**
         * Registers document-level delegated listeners for click, focusout,
         * input, and submit events. Aborts if the groups table is not on the page.
         */
        constructor() {
            if (!document.getElementById('eim-event-groups-table')) return;

            document.addEventListener('click',    (e) => this.#handleClick(e));
            document.addEventListener('focusout', (e) => this.#handleFocusOut(e));
            document.addEventListener('input',    (e) => this.#handleInput(e));
            document.addEventListener('submit',   (e) => this.#handleSubmit(e));
        }

        /**
         * Delegated click handler — routes clicks on dropdown triggers, add-member
         * toggles/cancels, and add-connection toggles/cancels to the appropriate
         * private method. Falls through to `#closeAll` for unrelated clicks.
         *
         * @param {MouseEvent} e The native click event.
         * @returns {void}
         */
        #handleClick(e) {
            const trigger = e.target.closest('.eim-member-dropdown-trigger');
            if (trigger) { this.#toggleDropdown(e, trigger); return; }

            const orderTrigger = e.target.closest('.eim-change-order-trigger');
            if (orderTrigger) { e.stopPropagation(); this.#showOrderEditor(orderTrigger); return; }

            const orderCancel = e.target.closest('.eim-cancel-order');
            if (orderCancel) { e.stopPropagation(); this.#hideOrderEditor(orderCancel); return; }

            const orderSave = e.target.closest('.eim-save-order');
            if (orderSave) { e.stopPropagation(); this.#saveOrder(orderSave); return; }

            const addToggle = e.target.closest('.eim-add-member-toggle');
            if (addToggle) { this.#showRow(`eim-add-member-row-${addToggle.dataset.groupId}`, `eim-add-member-search-${addToggle.dataset.groupId}`); return; }

            const addCancel = e.target.closest('.eim-add-member-cancel');
            if (addCancel) { this.#hideAddMember(addCancel.dataset.groupId); return; }

            const connToggle = e.target.closest('.eim-add-connection-toggle');
            if (connToggle) { this.#showRow(`eim-add-connection-row-${connToggle.dataset.groupId}`, null, `.eim-connection-select`); return; }

            const connCancel = e.target.closest('.eim-add-connection-cancel');
            if (connCancel) { this.#hideAddConnection(connCancel.dataset.groupId); return; }

            this.#closeAll();
        }

        /**
         * Delegated focusout handler — hides the member-search suggestion dropdown
         * when focus leaves a `.eim-group-member-search` input.
         *
         * @param {FocusEvent} e The native focusout event.
         * @returns {void}
         */
        #handleFocusOut(e) {
            const input = e.target.closest('.eim-group-member-search');
            if (!input) return;
            setTimeout(() => {
                const drop = input.parentElement?.querySelector('.eim-invitee-suggestions');
                if (drop) drop.style.display = 'none';
            }, 150);
        }

        /**
         * Delegated input handler — triggers a debounced member search when the
         * user types into a `.eim-group-member-search` input.
         *
         * @param {InputEvent} e The native input event.
         * @returns {void}
         */
        #handleInput(e) {
            const input = e.target.closest('.eim-group-member-search');
            if (input) this.#debouncedSearch(input);
        }

        /**
         * Delegated submit handler — prevents form submission when an add-member
         * or add-connection form is submitted without a valid selection.
         *
         * @param {SubmitEvent} e The native submit event.
         * @returns {void}
         */
        #handleSubmit(e) {
            const form = e.target.closest('.eim-add-member-form');
            if (!form) return;
            const hidden = form.querySelector('.eim-add-member-invitee-id');
            const select = form.querySelector('.eim-connection-select');
            if (hidden && !hidden.value) {
                e.preventDefault();
                alert('Please select an invitee from the search results.');
            } else if (select && !select.value) {
                e.preventDefault();
                alert('Please select a connection from the list.');
            }
        }

        /**
         * Toggles the member dropdown menu for the given trigger button, closing
         * all other open dropdowns first.
         *
         * @param {MouseEvent}  e   The originating click event (used to stop propagation).
         * @param {HTMLElement} btn The dropdown trigger button that was clicked.
         * @returns {void}
         */
        #toggleDropdown(e, btn) {
            e.stopPropagation();
            const menu   = btn.nextElementSibling;
            if (!menu) return;
            const isOpen = !menu.hidden;
            this.#closeAll();
            if (!isOpen) {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        /**
         * Closes all open member dropdown menus and resets their trigger
         * `aria-expanded` attributes to "false".
         *
         * @returns {void}
         */
        #closeAll() {
            document.querySelectorAll('.eim-member-dropdown-menu').forEach(m => {
                m.hidden = true;
                m.previousElementSibling?.setAttribute('aria-expanded', 'false');
            });
            document.querySelectorAll('.eim-member-order-editor').forEach(editor => { editor.hidden = true; });
        }

        /**
         * Reveals the inline "change order number" editor for a member, replacing
         * the visible "Change Order Number" menu item, and focuses its input.
         *
         * @param {HTMLElement} trigger The `.eim-change-order-trigger` button that was clicked.
         * @returns {void}
         */
        #showOrderEditor(trigger) {
            const editor = trigger.nextElementSibling;
            if (!editor || !editor.classList.contains('eim-member-order-editor')) return;
            editor.hidden = false;
            editor.querySelector('.eim-order-input')?.focus();
        }

        /**
         * Hides the inline order editor without saving.
         *
         * @param {HTMLElement} cancelBtn The `.eim-cancel-order` button that was clicked.
         * @returns {void}
         */
        #hideOrderEditor(cancelBtn) {
            const editor = cancelBtn.closest('.eim-member-order-editor');
            if (editor) editor.hidden = true;
        }

        /**
         * Saves a member's new order number via AJAX and refreshes every member's
         * order badge in the group, since moving one member renumbers the rest.
         *
         * @param {HTMLElement} saveBtn The `.eim-save-order` button that was clicked.
         * @returns {Promise<void>}
         */
        async #saveOrder(saveBtn) {
            const editor  = saveBtn.closest('.eim-member-order-editor');
            const trigger = editor?.previousElementSibling;
            const input   = editor?.querySelector('.eim-order-input');
            const status  = editor?.querySelector('.eim-order-save-status');
            if (!editor || !trigger || !input) return;

            const groupId   = trigger.dataset.groupId;
            const inviteeId = trigger.dataset.inviteeId;
            const newOrder  = parseInt(input.value, 10);

            if (!groupId || !inviteeId || !newOrder || newOrder < 1) {
                if (status) { status.textContent = 'Enter a valid number.'; status.style.color = '#d63638'; }
                return;
            }

            saveBtn.disabled = true;
            if (status) { status.textContent = ''; }

            try {
                const body = new FormData();
                body.append('action',     'eim_save_member_order');
                body.append('nonce',      config.event?.memberOrderNonce || '');
                body.append('group_id',   groupId);
                body.append('invitee_id', inviteeId);
                body.append('sort_order', String(newOrder));

                const resp = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
                const { success, data } = await resp.json();

                if (success && data?.order_map) {
                    this.#applyOrderMap(groupId, data.order_map);
                    editor.hidden = true;
                } else if (status) {
                    status.textContent = 'Error';
                    status.style.color = '#d63638';
                }
            } catch (err) {
                console.error('[EIM] Member order save failed:', err);
                if (status) { status.textContent = 'Error'; status.style.color = '#d63638'; }
            } finally {
                saveBtn.disabled = false;
            }
        }

        /**
         * Updates every member's order badge and editor input within one group
         * to reflect a freshly-saved order map.
         *
         * @param {string}                  groupId  The invitation group ID whose badges should refresh.
         * @param {Object<string, number>}  orderMap invitee_id => new 1-based position.
         * @returns {void}
         */
        #applyOrderMap(groupId, orderMap) {
            document.querySelectorAll(`.eim-change-order-trigger[data-group-id="${CSS.escape(String(groupId))}"]`).forEach((trigger) => {
                const newPosition = orderMap[trigger.dataset.inviteeId];
                if (newPosition === undefined) return;

                trigger.dataset.currentOrder = String(newPosition);

                const badge = trigger.closest('.eim-member-dropdown')?.querySelector('.eim-member-order-badge');
                if (badge) badge.textContent = `#${newPosition}`;

                const input = trigger.nextElementSibling?.querySelector('.eim-order-input');
                if (input) input.value = String(newPosition);
            });
        }

        /**
         * Makes the row identified by `rowId` visible and focuses an element
         * within it, either by ID or by CSS selector.
         *
         * @param {string}      rowId         The `id` attribute of the row to show.
         * @param {string|null} focusId       The `id` of the element to focus, or null.
         * @param {string|null} focusSelector A CSS selector within the row to focus, or null.
         * @returns {void}
         */
        #showRow(rowId, focusId, focusSelector) {
            const row = document.getElementById(rowId);
            if (!row) return;
            row.style.display = '';
            if (focusId) document.getElementById(focusId)?.focus();
            else if (focusSelector) row.querySelector(focusSelector)?.focus();
        }

        /**
         * Hides the add-member inline row for the given group and clears its inputs.
         *
         * @param {string|number} groupId The group ID whose add-member row should be hidden.
         * @returns {void}
         */
        #hideAddMember(groupId) {
            const row = document.getElementById(`eim-add-member-row-${groupId}`);
            if (row) row.style.display = 'none';
            const input  = document.getElementById(`eim-add-member-search-${groupId}`);
            const hidden = document.getElementById(`eim-add-member-invitee-id-${groupId}`);
            if (input)  input.value  = '';
            if (hidden) hidden.value = '';
        }

        /**
         * Hides the add-connection inline row for the given group and resets its select.
         *
         * @param {string|number} groupId The group ID whose add-connection row should be hidden.
         * @returns {void}
         */
        #hideAddConnection(groupId) {
            const row = document.getElementById(`eim-add-connection-row-${groupId}`);
            if (!row) return;
            row.style.display = 'none';
            const sel = row.querySelector('.eim-connection-select');
            if (sel) sel.value = '';
        }

        /**
         * Schedules a debounced member search for the given input, cancelling any
         * pending search for that same input.
         *
         * @param {HTMLInputElement} input The search input that received the keystroke.
         * @returns {void}
         */
        #debouncedSearch(input) {
            clearTimeout(this.#debounceMap.get(input) || 0);
            this.#debounceMap.set(input, setTimeout(() => this.#searchMember(input), 250));
        }

        /**
         * Performs an AJAX invitee search scoped to the current event and renders
         * results into a lazily-created dropdown beneath the given input.
         *
         * @param {HTMLInputElement} input The search input whose value drives the query.
         * @returns {Promise<void>}
         */
        async #searchMember(input) {
            const query   = input.value.trim();
            const eventId = input.dataset.eventId || 0;
            const groupId = input.dataset.groupId || 0;

            let drop = input.parentElement?.querySelector('.eim-invitee-suggestions');
            if (!drop) {
                drop = document.createElement('ul');
                drop.className = 'eim-invitee-suggestions';
                drop.setAttribute('role', 'listbox');
                drop.style.display = 'none';
                input.parentElement?.appendChild(drop);
            }

            if (query.length < 2) { drop.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_invitees', {
                    nonce: config.suggestNonce, query, event_id: eventId,
                }), { credentials: 'same-origin' })).json();

                drop.replaceChildren();
                const items = success ? data : [];

                if (!items.length) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion-empty';
                    li.textContent = 'No available invitees found.';
                    drop.appendChild(li);
                } else {
                    for (const inv of items) {
                        const li = document.createElement('li');
                        li.className = 'eim-invitee-suggestion';
                        li.setAttribute('role', 'option');
                        const name = document.createElement('strong');
                        name.textContent = inv.name || '';
                        li.appendChild(name);
                        if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                        li.addEventListener('mousedown', (ev) => {
                            ev.preventDefault();
                            input.value = inv.name || inv.label || '';
                            drop.style.display = 'none';
                            const hidden = document.getElementById(`eim-add-member-invitee-id-${groupId}`);
                            if (hidden) hidden.value = String(inv.id || '');
                        });
                        drop.appendChild(li);
                    }
                }
                drop.style.display = 'block';
            } catch (err) {
                console.error('[EIM] Member search failed:', err);
                drop.style.display = 'none';
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventGroupsTable — AJAX search/filter + column sort for the Invited Invitees table
    // -----------------------------------------------------------------------

    /**
     * Manages the AJAX search and sort behaviour for the invited-groups table
     * on the event edit screen.
     */
    class EventGroupsTable {
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
            this.#table        = document.getElementById('eim-event-groups-table');
            this.#tbody        = document.getElementById('eim-event-groups-table-body');
            this.#search       = document.getElementById('eim-event-groups-search');
            this.#field        = document.getElementById('eim-event-groups-search-field');
            this.#count        = document.getElementById('eim-event-groups-count');
            this.#spinner      = document.getElementById('eim-event-groups-loading');
            this.#perPageSel   = document.getElementById('eim-event-groups-search-per-page');
            this.#paginationNav = document.getElementById('eim-event-groups-search-pagination');

            if (!this.#table || !this.#tbody || !config.event?.groupsSortNonce) return;

            this.#sort    = this.#table.dataset.sort  || 'name';
            this.#order   = this.#table.dataset.order || 'asc';
            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_event_groups', 10, () => this.#refresh());

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_event_groups', this.#perPage);
                this.#page = 1;
                this.#refresh();
            });
            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_sort_event_groups', {
                    nonce:    config.event.groupsSortNonce,
                    event_id: config.event.id || 0,
                    sort:     this.#sort,
                    order:    this.#order,
                    query:    this.#search?.value || '',
                    field:    this.#field?.value  || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#renderPagination(Number(data.total || 0));
            } catch (e) {
                console.error('[EIM] Event groups sort/search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
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

    // -----------------------------------------------------------------------
    // EventMenuItemFilter — client-side search for the event's assigned food/beverage tables
    // -----------------------------------------------------------------------

    /**
     * Provides client-side filtering for the event's assigned food and beverage tables.
     * No server round-trip is required — rows are shown or hidden by matching
     * `data-label` and `data-description` attributes against the search query.
     */
    class EventMenuItemFilter {
        /**
         * Initialises filters for both the "food" and "beverage" table types.
         */
        constructor() {
            this.#initType('food');
            this.#initType('beverage');
        }

        /**
         * Wires up the search input and field dropdown for the given item type,
         * binding them to the `#filter` method via debounce.
         *
         * @param {string} type The item type to initialise — "food" or "beverage".
         * @returns {void}
         */
        #initType(type) {
            const search  = document.getElementById(`eim-event-${type}-item-search`);
            const field   = document.getElementById(`eim-event-${type}-item-search-field`);
            const count   = document.getElementById(`eim-event-${type}-item-count`);
            const tbody   = document.getElementById(`eim-event-${type}-items-body`);

            if (!search || !tbody) return;

            const run = () => this.#filter(search, field, count, tbody);
            search.addEventListener('input', debounce(run));
            field?.addEventListener('change', run);
        }

        /**
         * Filters table rows in-place by matching the search query against the
         * relevant column data attributes, then updates the visible result count.
         *
         * @param {HTMLInputElement}         search The search text input.
         * @param {HTMLSelectElement|null}   field  The column-select dropdown, or null for all columns.
         * @param {HTMLElement|null}         count  The element that displays the result count.
         * @param {HTMLTableSectionElement}  tbody  The table body whose rows will be toggled.
         * @returns {void}
         */
        #filter(search, field, count, tbody) {
            const query = search.value.toLowerCase().trim();
            const col   = field?.value || '';

            const dataRows = [...tbody.querySelectorAll('tr[data-label]')];
            let visible    = 0;

            for (const row of dataRows) {
                let matches;
                if (query === '') {
                    matches = true;
                } else if (col === 'label') {
                    matches = row.dataset.label.includes(query);
                } else if (col === 'description') {
                    matches = row.dataset.description.includes(query);
                } else {
                    matches = row.dataset.label.includes(query) || row.dataset.description.includes(query);
                }
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }

            if (count) count.textContent = `${visible} result${visible === 1 ? '' : 's'}`;

            let emptyRow = tbody.querySelector('.eim-filter-empty');
            if (dataRows.length > 0 && visible === 0 && query !== '') {
                if (!emptyRow) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'eim-filter-empty';
                    const td = document.createElement('td');
                    td.colSpan = tbody.closest('table')?.tHead?.rows[0]?.cells.length || 3;
                    td.textContent = 'No results found based upon search criteria.';
                    emptyRow.appendChild(td);
                    tbody.appendChild(emptyRow);
                }
                emptyRow.style.display = '';
            } else if (emptyRow) {
                emptyRow.style.display = 'none';
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventAssignmentSorter — drag/order + column sort for event lodging/menu tables
    // -----------------------------------------------------------------------

    /**
     * Enables drag-to-reorder and column-sort for event lodging and menu item
     * assignment tables. Persists the new order to the server after each drag.
     */
    class EventAssignmentSorter {
        /**
         * Finds all `.eim-sortable-assignment-list` tables on the page and
         * initialises drag-and-drop and column sort for each one.
         */
        constructor() {
            if (!config.event?.assignmentSortNonce) return;

            for (const table of document.querySelectorAll('.eim-sortable-assignment-list')) {
                this.#initTable(table);
            }
        }

        /**
         * Attaches sort-link click listeners and drag-and-drop row listeners to a
         * single sortable assignment table.
         *
         * @param {HTMLTableElement} table The table element to initialise.
         * @returns {void}
         */
        #initTable(table) {
            const tbody = table.tBodies?.[0];
            if (!tbody) return;

            for (const link of table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    const sort  = link.dataset.sort || 'order';
                    const order = link.dataset.order || 'asc';
                    this.#sortRows(table, sort, order);
                    this.#updateSortLinks(table, sort, order);
                });
            }

            let dragging = null;

            for (const row of tbody.querySelectorAll('.eim-sortable-row')) {
                const handle = row.querySelector('.eim-drag-handle');
                if (!handle) continue;

                row.draggable = false;

                handle.addEventListener('mousedown', () => {
                    row.draggable = true;
                });

                handle.addEventListener('mouseup', () => {
                    if (!row.classList.contains('is-dragging')) row.draggable = false;
                });

                handle.addEventListener('touchstart', () => {
                    row.draggable = true;
                }, { passive: true });

                row.addEventListener('dragstart', (event) => {
                    dragging = row;
                    row.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', row.dataset.id || '');
                });

                row.addEventListener('dragend', () => {
                    row.classList.remove('is-dragging');
                    row.draggable = false;

                    if (!dragging) return;
                    dragging = null;
                    this.#renumberRows(tbody);
                    this.#saveOrder(table);
                });
            }

            tbody.addEventListener('dragover', (event) => {
                if (!dragging) return;

                event.preventDefault();
                const after = this.#dragAfterElement(tbody, event.clientY);

                if (after === null) {
                    tbody.appendChild(dragging);
                } else {
                    tbody.insertBefore(dragging, after);
                }
            });
        }

        /**
         * Returns the closest non-dragging row below the given vertical cursor
         * position, or `null` if the dragged item should be appended at the end.
         *
         * @param {HTMLTableSectionElement} tbody The table body being reordered.
         * @param {number}                  y     The current clientY cursor coordinate.
         * @returns {HTMLElement|null} The row to insert before, or null to append.
         */
        #dragAfterElement(tbody, y) {
            const rows = [...tbody.querySelectorAll('.eim-sortable-row:not(.is-dragging)')]
                .filter((row) => row.style.display !== 'none');

            return rows.reduce((closest, row) => {
                const box    = row.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset, row };
                }

                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
        }

        /**
         * Updates the `data-order` dataset attribute and visible order cell of
         * every sortable row to reflect their current DOM positions.
         *
         * @param {HTMLTableSectionElement} tbody The table body whose rows should be renumbered.
         * @returns {void}
         */
        #renumberRows(tbody) {
            const rows = [...tbody.querySelectorAll('.eim-sortable-row')];

            rows.forEach((row, index) => {
                const order = String(index + 1);
                row.dataset.order = order;

                const cell = row.querySelector('.eim-order-cell');
                if (cell) cell.textContent = order;
            });
        }

        /**
         * Sorts the sortable rows within the table's first tbody by the given
         * column key and direction, reappending them to the tbody in sorted order.
         *
         * @param {HTMLTableElement} table The table whose rows should be sorted.
         * @param {string}           sort  The `data-*` key on each row to sort by.
         * @param {string}           order "asc" for ascending, "desc" for descending.
         * @returns {void}
         */
        #sortRows(table, sort, order) {
            const tbody = table.tBodies?.[0];
            if (!tbody) return;

            const multiplier = order === 'desc' ? -1 : 1;
            const rows = [...tbody.querySelectorAll('.eim-sortable-row')];

            rows.sort((a, b) => {
                const aVal = a.dataset[sort] || '';
                const bVal = b.dataset[sort] || '';

                if (sort === 'order') {
                    return multiplier * ((Number(aVal) || 0) - (Number(bVal) || 0));
                }

                return multiplier * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });

            for (const row of rows) {
                tbody.appendChild(row);
            }
        }

        /**
         * Updates each sort link's `data-order` attribute and indicator glyph to
         * reflect the newly active sort column and direction.
         *
         * @param {HTMLTableElement} table The table whose sort links should be updated.
         * @param {string}           sort  The active sort column key.
         * @param {string}           order The active sort direction ("asc" or "desc").
         * @returns {void}
         */
        #updateSortLinks(table, sort, order) {
            table.dataset.sort  = sort;
            table.dataset.order = order;

            for (const link of table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === sort;
                link.dataset.order = isCurrent && order === 'asc' ? 'desc' : 'asc';

                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (order === 'asc' ? '^' : 'v') : '';
            }
        }

        /**
         * Persists the current row order of the given table to the server via AJAX.
         * Handles both lodging and menu-item table types.
         *
         * @param {HTMLTableElement} table The table whose row order should be saved.
         * @returns {Promise<void>}
         */
        async #saveOrder(table) {
            const rows = [...(table.tBodies?.[0]?.querySelectorAll('.eim-sortable-row') ?? [])];
            const ids  = rows.map((row) => row.dataset.id).filter(Boolean);

            if (ids.length < 2) return;

            const body = new URLSearchParams();
            body.set('nonce', config.event.assignmentSortNonce);
            body.set('event_id', config.event.id || 0);

            if (table.dataset.kind === 'lodging') {
                body.set('action', 'eim_sort_event_lodging');
            } else {
                body.set('action', 'eim_sort_event_menu_items');
                body.set('type', table.dataset.type || 'food');
            }

            ids.forEach((id) => body.append('ids[]', id));

            const status = table.parentElement?.querySelector('.eim-sort-status');
            if (status) status.textContent = 'Saving order...';

            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                const { success } = await response.json();

                if (status) {
                    status.textContent = success ? 'Order saved.' : 'Could not save order.';
                    window.setTimeout(() => { status.textContent = ''; }, 2400);
                }
            } catch (error) {
                console.error('[EIM] Assignment order save failed:', error);
                if (status) status.textContent = 'Could not save order.';
            }
        }
    }

    // -----------------------------------------------------------------------
    // LodgingNotesEditor — inline AJAX save for per-event lodging notes
    // -----------------------------------------------------------------------

    class LodgingNotesEditor {
        constructor() {
            if (!config.event?.lodgingNotesNonce) return;
            this._timers = new Map();
            document.addEventListener('input', (e) => {
                if (e.target.matches('.eim-lodging-notes')) this._schedule(e.target);
            });
        }

        _schedule(textarea) {
            const id = textarea.dataset.lodgingId;
            if (!id) return;
            clearTimeout(this._timers.get(id));
            this._timers.set(id, setTimeout(() => this._save(textarea), 600));
        }

        async _save(textarea) {
            const id     = textarea.dataset.lodgingId;
            const status = textarea.closest('div')?.querySelector('.eim-lodging-notes-status');
            if (status) status.textContent = 'Saving…';

            const body = new URLSearchParams();
            body.set('action',     'eim_save_lodging_notes');
            body.set('nonce',      config.event.lodgingNotesNonce);
            body.set('event_id',   config.event.id || 0);
            body.set('lodging_id', id);
            body.set('notes',      textarea.value);

            try {
                const resp = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                const { success } = await resp.json();
                if (status) {
                    status.textContent = success ? 'Saved.' : 'Could not save.';
                    window.setTimeout(() => { if (status) status.textContent = ''; }, 2400);
                }
            } catch {
                if (status) status.textContent = 'Could not save.';
            }
        }
    }

    // -----------------------------------------------------------------------
    // MenuItemPicker — autocomplete for food/beverage pickers on the event edit page
    // -----------------------------------------------------------------------

    /**
     * Autocomplete picker for assigning food/beverage items to an event from the
     * menu library. One instance handles all `.eim-menu-item-search` inputs on the page.
     */
    class MenuItemPicker {
        /**
         * Stores metadata for each initialised search input so they can be
         * referenced independently during search and render operations.
         *
         * @type {Array<object>}
         */
        #inputs = [];

        /**
         * Finds all `.eim-menu-item-search` inputs on the page and initialises
         * an autocomplete dropdown for each one. Aborts if the suggest nonce is absent.
         */
        constructor() {
            if (!config.suggestMenuItemsNonce) return;

            for (const input of document.querySelectorAll('.eim-menu-item-search')) {
                this.#initInput(input);
            }
        }

        /**
         * Creates a suggestion dropdown for the given input, wires up input, blur,
         * and form-submit listeners, and stores the input metadata in `#inputs`.
         *
         * @param {HTMLInputElement} input The menu-item search input to initialise.
         * @returns {void}
         */
        #initInput(input) {
            const type     = input.dataset.type || 'food';
            const hiddenId = `eim_${type}_item_id`;
            const labelId  = `eim_${type}_item_selected`;
            const hidden   = document.getElementById(hiddenId);
            const label    = document.getElementById(labelId);

            const dropdown = document.createElement('ul');
            dropdown.className = 'eim-invitee-suggestions';
            dropdown.setAttribute('role', 'listbox');
            dropdown.style.display = 'none';
            input.parentElement?.classList.add('eim-invitee-picker-positioner');
            input.parentElement?.appendChild(dropdown);

            input.addEventListener('input', debounce(() => this.#search(input, type, dropdown, hidden, label)));
            input.addEventListener('input', () => {
                if (hidden) hidden.value = '';
                if (label)  label.textContent = '';
            });
            input.addEventListener('blur', () => setTimeout(() => { dropdown.style.display = 'none'; }, 150));

            input.closest('form')?.addEventListener('submit', (e) => {
                if (!hidden?.value) {
                    e.preventDefault();
                    alert('Please select an item from the search results before adding it.');
                }
            });

            this.#inputs.push({ input, type, dropdown, hidden, label });
        }

        /**
         * Queries the server for menu items of the given type matching the input
         * value, then delegates rendering to `#renderDropdown`.
         *
         * @param {HTMLInputElement}      input    The search input providing the query.
         * @param {string}               type     The item type ("food" or "beverage").
         * @param {HTMLUListElement}      dropdown The suggestion list to populate.
         * @param {HTMLInputElement|null} hidden   The hidden ID field to populate on selection.
         * @param {HTMLElement|null}      label    The element showing the selected item label.
         * @returns {Promise<void>}
         */
        async #search(input, type, dropdown, hidden, label) {
            const query = input.value.trim();
            if (query.length < 1) { dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_menu_items', {
                    nonce: config.suggestMenuItemsNonce,
                    type,
                    query,
                }), { credentials: 'same-origin' })).json();

                this.#renderDropdown(success ? data : [], dropdown, input, hidden, label);
            } catch (e) {
                console.error('[EIM] Menu item suggest failed:', e);
                dropdown.style.display = 'none';
            }
        }

        /**
         * Populates the suggestion dropdown with the given menu item objects and
         * makes it visible.
         *
         * @param {Array<object>}         items    Array of menu item result objects from the server.
         * @param {HTMLUListElement}      dropdown The suggestion list to populate.
         * @param {HTMLInputElement}      input    The search input (used to fill on selection).
         * @param {HTMLInputElement|null} hidden   The hidden ID field to populate on selection.
         * @param {HTMLElement|null}      label    The element showing the selected item label.
         * @returns {void}
         */
        #renderDropdown(items, dropdown, input, hidden, label) {
            dropdown.replaceChildren();
            if (!items.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching items found.';
                dropdown.appendChild(li);
            } else {
                for (const item of items) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = item.label || '';
                    li.appendChild(name);
                    if (item.description) li.appendChild(document.createTextNode(` — ${item.description}`));
                    li.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        input.value  = item.label || '';
                        if (hidden) hidden.value = String(item.id || '');
                        if (label)  label.textContent = `Selected: ${item.label}`;
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(li);
                }
            }
            dropdown.style.display = 'block';
        }
    }

    // -----------------------------------------------------------------------
    // -----------------------------------------------------------------------
    // ConnectionGroupMembersTable — client-side sort + filter for group edit page
    // -----------------------------------------------------------------------

    /**
     * Adds client-side text filtering and column sorting to the connection group
     * members table on the group edit page. Activates only when the table exists.
     */
    class ConnectionGroupMembersTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLInputElement|null} */
        #search;

        /** @type {HTMLElement|null} */
        #count;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /**
         * Locates the members table and binds event listeners. Silently aborts
         * when the table is not present (i.e. the list view or add form).
         */
        constructor() {
            this.#table  = document.getElementById('eim-cg-members-table');
            this.#tbody  = document.getElementById('eim-cg-members-tbody');
            this.#search = document.getElementById('eim-cg-members-search');
            this.#count  = document.getElementById('eim-cg-members-count');

            if (!this.#table || !this.#tbody) return;

            this.#sort  = this.#table.dataset.sort  || 'name';
            this.#order = this.#table.dataset.order || 'asc';

            this.#search?.addEventListener('input', debounce(() => this.#applyFilter(), 150));

            for (const link of this.#table.querySelectorAll('.eim-cg-member-sort')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#sortRows();
                    this.#updateSortLinks();
                });
            }
        }

        /**
         * Filters visible rows by the current search-input value, matching against
         * the data-name and data-email attributes on each row.
         *
         * @returns {void}
         */
        #applyFilter() {
            const query = (this.#search?.value || '').toLowerCase().trim();
            let visible = 0;

            for (const row of this.#tbody.querySelectorAll('tr[data-name]')) {
                const matches = !query
                    || (row.dataset.name  || '').includes(query)
                    || (row.dataset.email || '').includes(query);
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }

            if (this.#count) {
                this.#count.textContent = `${visible} member${visible === 1 ? '' : 's'}`;
            }
        }

        /**
         * Sorts the tbody rows in-place by the current sort key (name or email).
         *
         * @returns {void}
         */
        #sortRows() {
            const rows = [...this.#tbody.querySelectorAll('tr[data-name]')];
            const mul  = this.#order === 'desc' ? -1 : 1;
            const key  = this.#sort;

            rows.sort((a, b) => {
                const aVal = a.dataset[key] || '';
                const bVal = b.dataset[key] || '';
                return mul * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });

            for (const row of rows) this.#tbody.appendChild(row);
            this.#applyFilter();
        }

        /**
         * Refreshes the sort-link `data-order` attributes and indicator arrows
         * to reflect the current sort column and direction.
         *
         * @returns {void}
         */
        #updateSortLinks() {
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-cg-member-sort')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // -----------------------------------------------------------------------
    // ConnectionGroupMemberSorter — drag-and-drop member reordering
    // -----------------------------------------------------------------------

    /**
     * Wires up native HTML5 drag-and-drop reordering for a connection group's
     * member table, mirroring EventAssignmentSorter's mechanics (used for event
     * lodging/menu items). Kept as its own class rather than folded into
     * EventAssignmentSorter since that class is tightly coupled to
     * config.event.id/assignmentSortNonce, while this table is scoped to
     * config.connectionGroup instead.
     *
     * Dragging is disabled while the member search filter is active, since
     * reordering a filtered subset would produce a confusing result.
     */
    class ConnectionGroupMemberSorter {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {HTMLInputElement|null} */
        #search;

        /** @type {HTMLElement|null} */
        #dragging = null;

        /**
         * Locates the sortable members table and binds drag listeners. Silently
         * aborts when the table isn't on the page or reordering isn't enabled.
         */
        constructor() {
            this.#table = document.querySelector('.eim-cg-sortable-members');
            if (!this.#table || !config.connectionGroup?.memberOrderSortNonce) return;

            this.#tbody = this.#table.tBodies?.[0];
            if (!this.#tbody) return;

            this.#search = document.getElementById('eim-cg-members-search');
            this.#initRows();
        }

        /**
         * Returns true when the search filter is currently narrowing the row set.
         *
         * @returns {boolean}
         */
        #isFiltering() {
            return (this.#search?.value || '').trim().length > 0;
        }

        /**
         * Attaches drag handle and drag/drop listeners to every sortable row.
         *
         * @returns {void}
         */
        #initRows() {
            for (const row of this.#tbody.querySelectorAll('.eim-sortable-row')) {
                const handle = row.querySelector('.eim-drag-handle');
                if (!handle) continue;

                row.draggable = false;

                handle.addEventListener('mousedown', () => {
                    if (!this.#isFiltering()) row.draggable = true;
                });

                handle.addEventListener('mouseup', () => {
                    if (!row.classList.contains('is-dragging')) row.draggable = false;
                });

                handle.addEventListener('touchstart', () => {
                    if (!this.#isFiltering()) row.draggable = true;
                }, { passive: true });

                row.addEventListener('dragstart', (event) => {
                    this.#dragging = row;
                    row.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', row.dataset.id || '');
                });

                row.addEventListener('dragend', () => {
                    row.classList.remove('is-dragging');
                    row.draggable = false;

                    if (!this.#dragging) return;
                    this.#dragging = null;
                    this.#renumberRows();
                    this.#saveOrder();
                });
            }

            this.#tbody.addEventListener('dragover', (event) => {
                if (!this.#dragging) return;

                event.preventDefault();
                const after = this.#dragAfterElement(event.clientY);

                if (after === null) {
                    this.#tbody.appendChild(this.#dragging);
                } else {
                    this.#tbody.insertBefore(this.#dragging, after);
                }
            });
        }

        /**
         * Returns the closest non-dragging row below the given vertical cursor
         * position, or `null` if the dragged item should be appended at the end.
         *
         * @param {number} y The current clientY cursor coordinate.
         * @returns {HTMLElement|null}
         */
        #dragAfterElement(y) {
            const rows = [...this.#tbody.querySelectorAll('.eim-sortable-row:not(.is-dragging)')]
                .filter((row) => row.style.display !== 'none');

            return rows.reduce((closest, row) => {
                const box    = row.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset, row };
                }

                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
        }

        /**
         * Updates the `data-order` dataset attribute and visible order cell of
         * every sortable row to reflect their current DOM positions.
         *
         * @returns {void}
         */
        #renumberRows() {
            const rows = [...this.#tbody.querySelectorAll('.eim-sortable-row')];

            rows.forEach((row, index) => {
                const order = String(index + 1);
                row.dataset.order = order;

                const cell = row.querySelector('.eim-order-cell');
                if (cell) cell.textContent = order;
            });
        }

        /**
         * Persists the current row order to the server via AJAX.
         *
         * @returns {Promise<void>}
         */
        async #saveOrder() {
            const rows = [...this.#tbody.querySelectorAll('.eim-sortable-row')];
            const ids  = rows.map((row) => row.dataset.id).filter(Boolean);

            if (ids.length < 2) return;

            const body = new URLSearchParams();
            body.set('action', 'eim_sort_cg_members');
            body.set('nonce', config.connectionGroup.memberOrderSortNonce);
            body.set('connection_group_id', config.connectionGroup.id || 0);
            ids.forEach((id) => body.append('ids[]', id));

            const status = this.#table.parentElement?.querySelector('.eim-sort-status');
            if (status) status.textContent = 'Saving order...';

            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                const { success } = await response.json();

                if (status) {
                    status.textContent = success ? 'Order saved.' : 'Could not save order.';
                    window.setTimeout(() => { status.textContent = ''; }, 2400);
                }
            } catch (error) {
                console.error('[EIM] Connection group member order save failed:', error);
                if (status) status.textContent = 'Could not save order.';
            }
        }
    }

    // -----------------------------------------------------------------------
    // GroupAccordion — toggles the seating accordion row per invitation group
    // -----------------------------------------------------------------------

    class GroupAccordion {
        constructor() {
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-seat-accordion-toggle');
                if (!btn) return;
                const groupId = btn.dataset.groupId;
                if (!groupId) return;

                const row = document.getElementById(`eim-seat-accordion-row-${groupId}`);
                if (!row) return;

                const isOpen = btn.getAttribute('aria-expanded') === 'true';
                if (isOpen) {
                    row.style.display = 'none';
                    btn.setAttribute('aria-expanded', 'false');
                    btn.textContent = '▶';
                } else {
                    row.style.display = '';
                    btn.setAttribute('aria-expanded', 'true');
                    btn.textContent = '▼';
                }
            });
        }

        /** Opens the accordion for the given group ID, scrolling it into view. */
        static open(groupId) {
            const toggleBtn = document.querySelector(`.eim-seat-accordion-toggle[data-group-id="${groupId}"]`);
            const row = document.getElementById(`eim-seat-accordion-row-${groupId}`);
            if (!row || !toggleBtn) return;

            row.style.display = '';
            toggleBtn.setAttribute('aria-expanded', 'true');
            toggleBtn.textContent = '▼';
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    // -----------------------------------------------------------------------
    // SeatAssignmentManager — AJAX seat saves + seating table dynamic updates
    // -----------------------------------------------------------------------

    class SeatAssignmentManager {
        #nonce;

        constructor(nonce) {
            this.#nonce = nonce;

            // Save on button click
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-save-seat');
                if (!btn) return;
                const groupId   = btn.dataset.groupId;
                const inviteeId = btn.dataset.inviteeId;
                const input     = document.querySelector(`.eim-seat-input[data-group-id="${groupId}"][data-invitee-id="${inviteeId}"]`);
                if (input) this.#save(groupId, inviteeId, input, btn);
            });

            // Save on Enter key in seat input
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;
                const input = e.target.closest('.eim-seat-input');
                if (!input) return;
                e.preventDefault();
                const groupId   = input.dataset.groupId;
                const inviteeId = input.dataset.inviteeId;
                const btn       = document.querySelector(`.eim-save-seat[data-group-id="${groupId}"][data-invitee-id="${inviteeId}"]`);
                this.#save(groupId, inviteeId, input, btn);
            });
        }

        async #save(groupId, inviteeId, input, btn) {
            const seat       = input.value.trim();
            const statusEl   = input.closest('div')?.querySelector('.eim-seat-save-status');
            const origBtn    = btn?.textContent;

            if (btn) { btn.disabled = true; btn.textContent = '…'; }
            if (statusEl) statusEl.textContent = '';

            try {
                const body = new FormData();
                body.append('action',          'eim_save_seat_assignment');
                body.append('nonce',           this.#nonce);
                body.append('group_id',        groupId);
                body.append('invitee_id',      inviteeId);
                body.append('seat_assignment', seat);

                const resp = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
                const { success } = await resp.json();

                if (success) {
                    input.dataset.original = seat;
                    if (statusEl) {
                        statusEl.textContent = '✓ Saved';
                        statusEl.style.color = '#00a32a';
                        setTimeout(() => { statusEl.textContent = ''; }, 2500);
                    }
                    this.#updateSeatingTable(groupId, inviteeId, seat, input);
                } else {
                    if (statusEl) { statusEl.textContent = 'Error'; statusEl.style.color = '#d63638'; }
                }
            } catch (err) {
                console.error('[EIM] Seat save failed:', err);
                if (statusEl) { statusEl.textContent = 'Error'; statusEl.style.color = '#d63638'; }
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = origBtn || 'Save'; }
            }
        }

        #updateSeatingTable(groupId, inviteeId, seat, accordionInput) {
            const tbody      = document.getElementById('eim-seating-assignments-tbody');
            const countEl    = document.getElementById('eim-seating-count');
            const emptyRow   = document.getElementById('eim-seating-empty-row');
            if (!tbody) return;

            const existing = tbody.querySelector(`tr[data-invitee-id="${inviteeId}"][data-group-id="${groupId}"]`);

            if (!seat) {
                // Clear seat — remove row if present
                existing?.remove();
            } else if (existing) {
                // Update existing row
                existing.dataset.seat = seat.toLowerCase();
                const cells = existing.querySelectorAll('td');
                if (cells[5]) cells[5].textContent = seat;
            } else {
                // Add new row — collect data from the accordion sub-table
                const accRow    = document.getElementById(`eim-seat-accordion-row-${groupId}`);
                const memberRow = accRow?.querySelector(`tr[data-invitee-id="${inviteeId}"]`);
                if (!memberRow) return;

                const firstName  = memberRow.querySelectorAll('td')[1]?.textContent || '';
                const lastName   = memberRow.querySelectorAll('td')[2]?.textContent || '';
                const email      = memberRow.querySelectorAll('td')[3]?.textContent || '';
                const groupLabel = accRow?.dataset.groupLabel || '';

                const tr = document.createElement('tr');
                tr.dataset.inviteeId = inviteeId;
                tr.dataset.groupId   = groupId;
                tr.dataset.firstName = firstName.toLowerCase();
                tr.dataset.lastName  = lastName.toLowerCase();
                tr.dataset.email     = email.toLowerCase();
                tr.dataset.phone     = '';
                tr.dataset.groupName = groupLabel.toLowerCase();
                tr.dataset.seat      = seat.toLowerCase();

                const escHtml = s => { const d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; };

                tr.innerHTML = `
                    <td>${escHtml(firstName)}</td>
                    <td>${escHtml(lastName)}</td>
                    <td>${escHtml(email)}</td>
                    <td>—</td>
                    <td>${escHtml(groupLabel)}</td>
                    <td>${escHtml(seat)}</td>
                `;
                emptyRow?.remove();
                tbody.appendChild(tr);
            }

            seatingTableInstance?.refresh();
        }
    }

    // -----------------------------------------------------------------------
    // SeatingAssignmentsTable — client-side filter, sort, and pagination
    // -----------------------------------------------------------------------

    class SeatingAssignmentsTable {
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
        /** @type {HTMLSelectElement|null} */
        #perPageSel;
        /** @type {HTMLElement|null} */
        #paginationNav;
        /** @type {string} */
        #sort = 'lastName';
        /** @type {string} */
        #order = 'asc';
        /** @type {number} */
        #page = 1;
        /** @type {number} */
        #perPage = 10;

        constructor() {
            this.#table        = document.getElementById('eim-seating-assignments-table');
            this.#tbody        = document.getElementById('eim-seating-assignments-tbody');
            this.#search       = document.getElementById('eim-seating-search');
            this.#field        = document.getElementById('eim-seating-field');
            this.#count        = document.getElementById('eim-seating-count');
            this.#perPageSel   = document.getElementById('eim-seating-search-per-page');
            this.#paginationNav = document.getElementById('eim-seating-search-pagination');

            if (!this.#tbody) return;

            this.#perPage = window.eimRestorePerPage(this.#perPageSel, 'eim_per_page_seating', 10);

            this.#search?.addEventListener('input', () => { this.#page = 1; this.#update(); });
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#update(); });
            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                window.eimPersistPerPage('eim_per_page_seating', this.#perPage);
                this.#page = 1;
                this.#update();
            });

            this.#table?.addEventListener('click', (e) => {
                const link = e.target.closest('.eim-seating-sort');
                if (!link) return;
                e.preventDefault();
                this.#sort  = link.dataset.sort  || 'lastName';
                this.#order = link.dataset.order || 'asc';
                this.#page  = 1;
                this.#sortRows();
                this.#updateSortLinks();
                this.#update();
            });

            this.#update();
        }

        /** Called after a seat save to refresh the visible rows. */
        refresh() {
            this.#update();
        }

        #update() {
            const query   = (this.#search?.value || '').toLowerCase().trim();
            const field   = this.#field?.value || '';
            const allRows = [...(this.#tbody?.querySelectorAll('tr[data-first-name]') ?? [])];

            if (allRows.length === 0) {
                if (this.#count) this.#count.textContent = '0 assignments';
                this.#renderPagination(0);
                return;
            }

            const matching = allRows.filter(row => !query || this.#rowMatches(row, query, field));

            for (const row of allRows) row.style.display = 'none';

            const start = (this.#page - 1) * this.#perPage;
            for (const row of matching.slice(start, start + this.#perPage)) row.style.display = '';

            // No-results placeholder when filtering
            let noResultsRow = this.#tbody?.querySelector('.eim-filter-empty');
            if (matching.length === 0 && query !== '') {
                if (!noResultsRow) {
                    noResultsRow = document.createElement('tr');
                    noResultsRow.className = 'eim-filter-empty';
                    const td = document.createElement('td');
                    td.colSpan = 6;
                    td.textContent = 'No results found based upon search criteria.';
                    noResultsRow.appendChild(td);
                    this.#tbody?.appendChild(noResultsRow);
                }
                noResultsRow.style.display = '';
            } else if (noResultsRow) {
                noResultsRow.style.display = 'none';
            }

            if (this.#count) {
                this.#count.textContent = `${matching.length} assignment${matching.length === 1 ? '' : 's'}`;
            }

            this.#renderPagination(matching.length);
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (p) => { this.#page = p; this.#update(); },
            });
        }

        #sortRows() {
            if (!this.#tbody) return;
            const rows = [...this.#tbody.querySelectorAll('tr[data-first-name]')];
            const mul  = this.#order === 'desc' ? -1 : 1;
            const key  = this.#sort;
            rows.sort((a, b) => {
                const aVal = a.dataset[key] || '';
                const bVal = b.dataset[key] || '';
                return mul * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });
            for (const row of rows) this.#tbody.appendChild(row);
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-seating-sort')) {
                const isCurrent    = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }

        #rowMatches(row, query, field) {
            const fieldMap = {
                first_name: 'firstName',
                last_name:  'lastName',
                email:      'email',
                phone:      'phone',
                group_name: 'groupName',
                seat:       'seat',
            };
            if (field && fieldMap[field]) {
                return (row.dataset[fieldMap[field]] || '').includes(query);
            }
            return ['firstName', 'lastName', 'email', 'phone', 'groupName', 'seat'].some(
                key => (row.dataset[key] || '').includes(query)
            );
        }
    }

    // =========================================================================
    // EventMessagesTab — messages list filter + popup with read/delete controls
    // =========================================================================

    class EventMessagesTab {
        /** @type {HTMLInputElement|null} */
        #filterInput;
        /** @type {HTMLTableSectionElement|null} */
        #tbody;
        /** @type {HTMLElement|null} */
        #modal;
        /** @type {HTMLElement|null} */
        #modalTitle;
        /** @type {HTMLElement|null} */
        #modalBody;
        /** @type {number} */
        #currentEventId;
        /** @type {number} */
        #currentGroupId;
        /** @type {boolean} */
        #unreadOnly = false;

        constructor() {
            this.#filterInput   = document.getElementById('eim-messages-filter');
            this.#tbody         = document.getElementById('eim-messages-tbody');
            this.#modal         = document.getElementById('eim-messages-modal');
            this.#modalTitle    = document.getElementById('eim-messages-modal-title');
            this.#modalBody     = document.getElementById('eim-messages-modal-body');
            this.#currentEventId = config.event?.id || 0;

            if (!this.#tbody) return;

            this.#filterInput?.addEventListener('input', () => this.#applyFilter());

            this.#tbody.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-messages-open');
                if (btn) {
                    this.#openModal(
                        Number(btn.dataset.groupId),
                        btn.dataset.groupName || '',
                        btn.dataset.unreadOnly === '1',
                    );
                }
            });

            document.getElementById('eim-messages-modal-close')
                    ?.addEventListener('click', () => this.#closeModal());
            document.getElementById('eim-messages-modal-backdrop')
                    ?.addEventListener('click', () => this.#closeModal());
        }

        // ── Filter ────────────────────────────────────────────────────────────

        #applyFilter() {
            const query = (this.#filterInput?.value || '').toLowerCase().trim();
            for (const row of this.#tbody?.querySelectorAll('tr') ?? []) {
                row.style.display = !query || (row.dataset.nameLower || '').includes(query) ? '' : 'none';
            }
        }

        // ── Modal ─────────────────────────────────────────────────────────────

        async #openModal(groupId, groupName, unreadOnly) {
            if (!this.#modal) return;
            this.#currentGroupId = groupId;
            this.#unreadOnly     = unreadOnly;

            const label = unreadOnly ? `Unread Messages — ${groupName}` : `Messages — ${groupName}`;
            if (this.#modalTitle) this.#modalTitle.textContent = label;
            if (this.#modalBody)  this.#modalBody.innerHTML    = '<p style="color:#999;">Loading…</p>';
            this.#modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action',   'eim_get_group_messages');
                url.searchParams.set('nonce',    config.event?.getMessagesNonce || '');
                url.searchParams.set('event_id', String(this.#currentEventId));
                url.searchParams.set('group_id', String(groupId));

                const { success, data } = await fetch(url, { credentials: 'same-origin' }).then(r => r.json());

                if (success) {
                    this.#renderMessages(data.messages || [], unreadOnly);
                } else {
                    if (this.#modalBody) this.#modalBody.innerHTML = '<p style="color:#d63638;">Failed to load messages.</p>';
                }
            } catch (err) {
                console.error('[EIM] Get messages failed:', err);
                if (this.#modalBody) this.#modalBody.innerHTML = '<p style="color:#d63638;">Unexpected error.</p>';
            }
        }

        #closeModal() {
            if (this.#modal) this.#modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        #renderMessages(messages, unreadOnly) {
            if (!this.#modalBody) return;

            // When unreadOnly, show only unread invitee messages (admin replies are always read)
            const filtered = unreadOnly
                ? messages.filter(m => !m.is_read && !m.is_admin_reply)
                : messages;

            const msgList = document.createElement('div');
            msgList.style.cssText = 'display:flex;flex-direction:column;gap:8px;margin-bottom:12px;min-height:40px;';

            if (!filtered.length) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:#999;margin:0;';
                empty.textContent = unreadOnly ? 'No unread messages.' : 'No messages yet.';
                msgList.appendChild(empty);
            } else {
                for (const msg of filtered) {
                    msgList.appendChild(this.#createBubble(msg));
                }
            }

            this.#modalBody.replaceChildren(msgList, this.#buildReplyArea());
        }

        #createBubble(msg) {
            const isAdmin = !!msg.is_admin_reply;

            const wrap = document.createElement('div');
            wrap.dataset.messageId = String(msg.id);
            wrap.style.cssText = `display:flex;flex-direction:column;align-items:${isAdmin ? 'flex-end' : 'flex-start'};`;

            const bubble = document.createElement('div');
            bubble.className = 'eim-msg-bubble';
            bubble.style.cssText = [
                'max-width:80%',
                'padding:8px 12px',
                `border-radius:${isAdmin ? '12px 12px 2px 12px' : '12px 12px 12px 2px'}`,
                `background:${isAdmin ? '#2271b1' : '#f0f0f1'}`,
                `color:${isAdmin ? '#fff' : '#1d2327'}`,
                'font-size:13px',
                'white-space:pre-wrap',
                'word-break:break-word',
            ].join(';');
            bubble.textContent = msg.message;

            const meta = document.createElement('div');
            meta.style.cssText = 'font-size:11px;color:#999;margin-top:3px;display:flex;gap:8px;align-items:center;';

            const dateSpan = document.createElement('span');
            dateSpan.textContent = this.#formatDate(msg.created_at);
            meta.appendChild(dateSpan);

            if (!isAdmin) {
                // Read checkbox for invitee messages only
                const chkLabel = document.createElement('label');
                chkLabel.style.cssText = 'display:flex;align-items:center;gap:3px;cursor:pointer;';

                const chk = document.createElement('input');
                chk.type    = 'checkbox';
                chk.checked = !!msg.is_read;
                chk.title   = msg.is_read ? 'Mark as unread' : 'Mark as read';

                const chkText = document.createElement('span');
                chkText.textContent = msg.is_read ? 'Read' : 'Unread';

                chk.addEventListener('change', () => {
                    this.#toggleRead(msg.id, chk, wrap, chkText);
                });

                chkLabel.append(chk, chkText);
                meta.appendChild(chkLabel);
            }

            const del = document.createElement('button');
            del.type        = 'button';
            del.textContent = 'Delete';
            del.className   = 'button-link';
            del.style.cssText = 'color:#d63638;font-size:11px;';
            del.addEventListener('click', () => this.#deleteMessage(msg.id, wrap));
            meta.appendChild(del);

            wrap.append(bubble, meta);
            return wrap;
        }

        #buildReplyArea() {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'border-top:1px solid #f0f0f1;padding-top:12px;margin-top:4px;';

            const textarea = document.createElement('textarea');
            textarea.rows        = 3;
            textarea.placeholder = 'Type a reply…';
            textarea.style.cssText = 'width:100%;box-sizing:border-box;font-size:13px;resize:vertical;';

            const footer = document.createElement('div');
            footer.style.cssText = 'margin-top:6px;display:flex;justify-content:flex-end;align-items:center;gap:10px;';

            const statusEl = document.createElement('span');
            statusEl.style.cssText = 'font-size:12px;color:#d63638;';

            const sendBtn = document.createElement('button');
            sendBtn.type      = 'button';
            sendBtn.className = 'button button-primary';
            sendBtn.textContent = 'Send Reply';

            sendBtn.addEventListener('click', async () => {
                const text = textarea.value.trim();
                if (!text) return;
                sendBtn.disabled    = true;
                sendBtn.textContent = 'Sending…';
                statusEl.textContent = '';
                const ok = await this.#sendReply(text);
                if (ok) {
                    textarea.value = '';
                } else {
                    statusEl.textContent = 'Failed to send reply.';
                    sendBtn.disabled    = false;
                    sendBtn.textContent = 'Send Reply';
                }
            });

            footer.append(statusEl, sendBtn);
            wrap.append(textarea, footer);
            return wrap;
        }

        // ── Actions ───────────────────────────────────────────────────────────

        async #sendReply(text) {
            try {
                const body = new FormData();
                body.append('action',   'eim_reply_to_message');
                body.append('nonce',    config.event?.replyNonce || '');
                body.append('event_id', String(this.#currentEventId));
                body.append('group_id', String(this.#currentGroupId));
                body.append('message',  text);

                const { success, data } = await fetch(ajaxurl, {
                    method: 'POST', credentials: 'same-origin', body,
                }).then(r => r.json());

                if (success) {
                    this.#renderMessages(data.messages || [], this.#unreadOnly);
                    this.#refreshRowCounts();
                    return true;
                }
                return false;
            } catch (err) {
                console.error('[EIM] Reply send failed:', err);
                return false;
            }
        }

        async #toggleRead(messageId, chk, wrap, chkText) {
            const isRead = chk.checked;
            chk.disabled = true;

            try {
                const body = new FormData();
                body.append('action',     'eim_mark_message_read');
                body.append('nonce',      config.event?.markReadNonce || '');
                body.append('message_id', String(messageId));
                body.append('is_read',    isRead ? '1' : '0');

                const { success } = await fetch(ajaxurl, {
                    method: 'POST', credentials: 'same-origin', body,
                }).then(r => r.json());

                if (success) {
                    chk.title = isRead ? 'Mark as unread' : 'Mark as read';
                    wrap.style.opacity = isRead ? '0.6' : '1';
                    if (chkText) chkText.textContent = isRead ? 'Read' : 'Unread';
                    this.#refreshRowCounts();
                } else {
                    chk.checked = !isRead;
                }
            } catch (err) {
                console.error('[EIM] Mark read failed:', err);
                chk.checked = !isRead;
            } finally {
                chk.disabled = false;
            }
        }

        async #deleteMessage(messageId, wrap) {
            if (!window.confirm('Delete this message? This cannot be undone.')) return;

            try {
                const body = new FormData();
                body.append('action',     'eim_delete_message');
                body.append('nonce',      config.event?.deleteMessageNonce || '');
                body.append('message_id', String(messageId));

                const { success } = await fetch(ajaxurl, {
                    method: 'POST', credentials: 'same-origin', body,
                }).then(r => r.json());

                if (success) {
                    wrap.remove();
                    const msgList = this.#modalBody?.querySelector('div[style*="flex-direction:column"]');
                    if (msgList && !msgList.querySelector('.eim-msg-bubble')) {
                        const empty = document.createElement('p');
                        empty.style.cssText = 'color:#999;margin:0;';
                        empty.textContent = 'No messages remaining.';
                        msgList.replaceChildren(empty);
                    }
                    this.#refreshRowCounts();
                }
            } catch (err) {
                console.error('[EIM] Delete message failed:', err);
            }
        }

        /** After a read-toggle, delete, or reply, re-fetches counts and updates the table row badges. */
        async #refreshRowCounts() {
            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action',   'eim_get_group_messages');
                url.searchParams.set('nonce',    config.event?.getMessagesNonce || '');
                url.searchParams.set('event_id', String(this.#currentEventId));
                url.searchParams.set('group_id', String(this.#currentGroupId));

                const { success, data } = await fetch(url, { credentials: 'same-origin' }).then(r => r.json());
                if (!success) return;

                // Count only invitee messages (admin replies don't need action)
                const msgs   = (data.messages || []).filter(m => !m.is_admin_reply);
                const total  = msgs.length;
                const unread = msgs.filter(m => !m.is_read).length;

                const row = this.#tbody?.querySelector(`tr[data-group-id="${this.#currentGroupId}"]`);
                if (!row) return;

                const cells = row.querySelectorAll('td');
                const totalCell  = cells[3];
                const unreadCell = cells[4];

                if (totalCell) {
                    const btn = totalCell.querySelector('.eim-messages-open');
                    if (btn) btn.textContent = String(total);
                }
                if (unreadCell) {
                    if (unread > 0) {
                        let btn = unreadCell.querySelector('.eim-messages-open');
                        if (!btn) {
                            btn = document.createElement('button');
                            btn.type      = 'button';
                            btn.className = 'button button-small eim-messages-open';
                            btn.style.cssText = 'background:#d63638;border-color:#b32d2e;color:#fff;';
                            btn.dataset.groupId    = String(this.#currentGroupId);
                            btn.dataset.groupName  = row.dataset.groupName || '';
                            btn.dataset.unreadOnly = '1';
                            unreadCell.innerHTML   = '';
                            unreadCell.appendChild(btn);
                        }
                        btn.textContent = String(unread);
                    } else {
                        unreadCell.innerHTML = '<span style="color:#999;">0</span>';
                    }
                }
            } catch (err) {
                console.error('[EIM] Refresh counts failed:', err);
            }
        }

        // ── Helpers ───────────────────────────────────────────────────────────

        #formatDate(mysqlDatetime) {
            if (!mysqlDatetime) return '';
            const d = new Date(mysqlDatetime.replace(' ', 'T') + 'Z');
            return isNaN(d) ? mysqlDatetime : d.toLocaleString();
        }
    }

    // =========================================================================
    // InviteEmailSendPanel — test send and send-all for the invite email tab
    // =========================================================================

    class InviteEmailSendPanel {
        /** @type {HTMLButtonElement|null} */
        #sendAllBtn;
        /** @type {HTMLElement|null} */
        #sendAllResult;
        /** @type {HTMLInputElement|null} */
        #testInput;
        /** @type {HTMLButtonElement|null} */
        #testBtn;
        /** @type {HTMLElement|null} */
        #testResult;

        constructor() {
            this.#sendAllBtn    = document.getElementById('eim-invite-send-all');
            this.#sendAllResult = document.getElementById('eim-invite-send-all-result');
            this.#testInput     = document.getElementById('eim-invite-test-email');
            this.#testBtn       = document.getElementById('eim-invite-send-test');
            this.#testResult    = document.getElementById('eim-invite-send-test-result');

            this.#sendAllBtn?.addEventListener('click', () => this.#handleSendAll());
            this.#testBtn?.addEventListener('click',    () => this.#handleSendTest());
        }

        async #handleSendAll() {
            const eventId = this.#sendAllBtn?.dataset.eventId;
            if (!eventId) return;

            if (!window.confirm('Send invite emails to all unsent invitation groups for this event? This cannot be undone.')) {
                return;
            }

            this.#setLoading(this.#sendAllBtn, true);
            this.#showResult(this.#sendAllResult, '', '');

            try {
                const body = new FormData();
                body.append('action',   'eim_send_all_invites_ajax');
                body.append('nonce',    config.event?.inviteAllNonce || '');
                body.append('event_id', eventId);

                const { success, data } = await fetch(ajaxurl, {
                    method: 'POST', credentials: 'same-origin', body,
                }).then(r => r.json());

                if (success) {
                    const { sent, failed, total } = data;
                    const msg = failed > 0
                        ? `Sent ${sent} of ${total}. ${failed} failed — check your server mail configuration.`
                        : `Successfully sent to all ${sent} group${sent === 1 ? '' : 's'}.`;
                    this.#showResult(this.#sendAllResult, msg, failed > 0 ? 'warning' : 'success');
                    if (this.#sendAllBtn) {
                        this.#sendAllBtn.textContent = 'Send to All Unsent (0)';
                        this.#sendAllBtn.disabled    = true;
                    }
                } else {
                    this.#showResult(this.#sendAllResult, data?.message || 'Failed to send.', 'error');
                    this.#setLoading(this.#sendAllBtn, false);
                }
            } catch (err) {
                console.error('[EIM] Send all invites failed:', err);
                this.#showResult(this.#sendAllResult, 'Unexpected error. Check the browser console.', 'error');
                this.#setLoading(this.#sendAllBtn, false);
            }
        }

        async #handleSendTest() {
            const eventId = this.#testBtn?.dataset.eventId;
            const email   = this.#testInput?.value.trim() || '';

            if (!email) {
                this.#showResult(this.#testResult, 'Please enter an email address.', 'error');
                return;
            }

            this.#setLoading(this.#testBtn, true);
            this.#showResult(this.#testResult, '', '');

            try {
                const body = new FormData();
                body.append('action',     'eim_send_invite_test');
                body.append('nonce',      config.event?.inviteTestNonce || '');
                body.append('event_id',   eventId || '');
                body.append('test_email', email);

                const { success, data } = await fetch(ajaxurl, {
                    method: 'POST', credentials: 'same-origin', body,
                }).then(r => r.json());

                if (success) {
                    this.#showResult(this.#testResult, `Test email sent to ${data.email}.`, 'success');
                } else {
                    this.#showResult(this.#testResult, data?.message || 'Failed to send.', 'error');
                }
            } catch (err) {
                console.error('[EIM] Send invite test failed:', err);
                this.#showResult(this.#testResult, 'Unexpected error. Check the browser console.', 'error');
            } finally {
                this.#setLoading(this.#testBtn, false);
            }
        }

        #setLoading(btn, isLoading) {
            if (!btn) return;
            btn.disabled         = isLoading;
            btn.dataset.origText = btn.dataset.origText ?? btn.textContent;
            btn.textContent      = isLoading ? 'Sending…' : (btn.dataset.origText || '');
        }

        #showResult(el, message, type) {
            if (!el) return;
            if (!message) { el.style.display = 'none'; return; }
            el.textContent  = message;
            el.style.color  = type === 'error' ? '#d63638' : type === 'warning' ? '#996800' : '#008000';
            el.style.display = 'inline';
        }
    }

    // Boot
    // -----------------------------------------------------------------------

    /** @type {SeatingAssignmentsTable|null} Shared reference so SeatAssignmentManager can trigger refreshes. */
    let seatingTableInstance = null;

    document.addEventListener('DOMContentLoaded', () => {
        new LocationImageModal();
        new InviteeImageModal();
        new InviteeImagePicker();
        new RsvpDetailsModal();
        new AccordionSortTable();
        if (config.table?.enabled)                new InviteeTable();
        if (config.connectionGroupTable?.enabled) new ConnectionGroupTable();
        if (config.event?.enabled)                new EventInviteePicker();
        if (config.connectionGroup?.enabled)      new ConnectionGroupMemberPicker();
        new EventGroupManager();
        new ConnectionGroupMembersTable();
        new ConnectionGroupMemberSorter();
        if (config.event?.enabled) {
            new EventGroupsTable();
            new MenuItemPicker();
            new EventMenuItemFilter();
            new EventAssignmentSorter();
            new LodgingNotesEditor();
            new GroupAccordion();
            new SeatAssignmentManager(config.event?.seatNonce || '');
            seatingTableInstance = new SeatingAssignmentsTable();
            new InviteEmailSendPanel();
            new EventMessagesTab();
        }
    });
})();
