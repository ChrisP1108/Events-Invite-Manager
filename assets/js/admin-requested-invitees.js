/* global ajaxurl, eimRiarAdmin */

/**
 * Requested Invitee Add-Ons admin page.
 *
 * Drives the live-search list table and the details/edit/approve/deny modal.
 * Configuration comes from the eimRiarAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimRiarAdmin ?? {};

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // RiarTable — live-search, sort, pagination
    // -------------------------------------------------------------------------

    class RiarTable {
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
            this.#table        = document.getElementById('eim-riars-table');
            this.#tbody        = document.getElementById('eim-riars-table-body');
            this.#search       = document.getElementById('eim-riar-search');
            this.#field        = document.getElementById('eim-riar-search-field');
            this.#count        = document.getElementById('eim-riar-count');
            this.#spinner      = document.getElementById('eim-riar-loading');
            this.#perPageSel   = document.getElementById('eim-riar-search-per-page');
            this.#paginationNav = document.getElementById('eim-riar-search-pagination');

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
                const url = ajaxUrl('eim_search_requested_invitees', {
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
                console.error('[EIM] Requested invitees search failed:', err);
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

    // -------------------------------------------------------------------------
    // RiarEventTable — live-search, sort, pagination for the per-event section
    // -------------------------------------------------------------------------

    class RiarEventTable {
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
            this.#table         = document.getElementById('eim-event-riars-table');
            this.#tbody         = document.getElementById('eim-event-riars-table-body');
            this.#search        = document.getElementById('eim-event-riar-search');
            this.#field         = document.getElementById('eim-event-riar-search-field');
            this.#count         = document.getElementById('eim-event-riar-count');
            this.#spinner       = document.getElementById('eim-event-riar-loading');
            this.#perPageSel    = document.getElementById('eim-event-riar-search-per-page');
            this.#paginationNav = document.getElementById('eim-event-riar-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.eventTable?.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.eventTable?.sort  || 'created_at';
            this.#order   = this.#table.dataset.order || config.eventTable?.order || 'desc';
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
                const url = ajaxUrl('eim_search_event_requested_invitees', {
                    nonce:    config.eventTable.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                    event_id: config.eventTable.eventId,
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (err) {
                console.error('[EIM] Event requested invitees search failed:', err);
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

    // -------------------------------------------------------------------------
    // RiarModal — details popup with inline editing and approve/deny actions
    // -------------------------------------------------------------------------

    class RiarModal {
        /** @type {HTMLElement|null} */
        #overlay;
        /** @type {HTMLElement|null} */
        #dialog;
        /** @type {HTMLElement|null} */
        #closeBtn;
        /** @type {HTMLFormElement|null} */
        #form;
        /** @type {HTMLButtonElement|null} */
        #saveBtn;
        /** @type {HTMLButtonElement|null} */
        #approveBtn;
        /** @type {HTMLButtonElement|null} */
        #denyBtn;
        /** @type {HTMLElement|null} */
        #saveNotice;
        /** @type {HTMLElement|null} */
        #approvedInfo;
        /** @type {HTMLElement|null} */
        #cgLink;
        /** @type {HTMLElement|null} */
        #inviteeLink;
        /** @type {HTMLElement|null} */
        #imageWrap;
        /** @type {HTMLImageElement|null} */
        #imageEl;

        /** @type {object|null} Current request data */
        #current = null;

        constructor() {
            this.#overlay     = document.getElementById('eim-riar-modal-overlay');
            this.#dialog      = document.getElementById('eim-riar-modal');
            this.#closeBtn    = document.getElementById('eim-riar-modal-close');
            this.#form        = document.getElementById('eim-riar-edit-form');
            this.#saveBtn     = document.getElementById('eim-riar-save-btn');
            this.#approveBtn  = document.getElementById('eim-riar-approve-btn');
            this.#denyBtn     = document.getElementById('eim-riar-deny-btn');
            this.#saveNotice  = document.getElementById('eim-riar-save-notice');
            this.#approvedInfo = document.getElementById('eim-riar-approved-info');
            this.#cgLink      = document.getElementById('eim-riar-cg-link');
            this.#inviteeLink = document.getElementById('eim-riar-invitee-link');
            this.#imageWrap   = document.getElementById('eim-riar-modal-image');
            this.#imageEl     = document.getElementById('eim-riar-modal-img');

            if (!this.#overlay) return;

            this.#closeBtn?.addEventListener('click', () => this.close());
            this.#overlay.addEventListener('click', (e) => {
                if (e.target === this.#overlay) this.close();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && !this.#overlay.hidden) this.close();
            });

            this.#saveBtn?.addEventListener('click', () => this.#save());
            this.#approveBtn?.addEventListener('click', () => this.#approve());
            this.#denyBtn?.addEventListener('click', () => this.#deny());
        }

        /** Opens the modal and populates fields from the row's data-request JSON. */
        open(requestData) {
            this.#current = requestData;
            this.#populate(requestData);
            this.#overlay.hidden = false;
            this.#overlay.removeAttribute('aria-hidden');
            this.#dialog?.focus();
        }

        close() {
            this.#overlay.hidden = true;
            this.#overlay.setAttribute('aria-hidden', 'true');
            this.#current = null;
            this.#hideSaveNotice();
        }

        #populate(req) {
            this.#setField('eim-riar-edit-id',         req.id);
            this.#setField('eim-riar-edit-first-name',  req.firstName);
            this.#setField('eim-riar-edit-last-name',   req.lastName);
            this.#setField('eim-riar-edit-email',       req.email);
            this.#setField('eim-riar-edit-phone',       req.phone);
            this.#setField('eim-riar-edit-street',      req.streetAddress);
            this.#setField('eim-riar-edit-city',        req.city);
            this.#setField('eim-riar-edit-state',       req.state);
            this.#setField('eim-riar-edit-zip',         req.zipCode);
            this.#setField('eim-riar-edit-notes',       req.notes);

            // Connection group link.
            if (this.#cgLink) {
                this.#cgLink.textContent = req.connectionGroupName || '—';
                this.#cgLink.href        = req.connectionGroupUrl  || '#';
            }

            // Image.
            if (this.#imageWrap && this.#imageEl) {
                if (req.imageThumbUrl) {
                    this.#imageEl.src       = req.imageThumbUrl;
                    this.#imageEl.title     = req.firstName + ' ' + req.lastName;
                    this.#imageWrap.style.display = '';
                } else {
                    this.#imageWrap.style.display = 'none';
                }
            }

            // Approved state.
            this.#updateStatusUI(req.status, req.approvedInviteeUrl);
        }

        #updateStatusUI(status, inviteeUrl = null) {
            const isApproved = status === 'approved';
            const isDenied   = status === 'denied';

            if (this.#approvedInfo) {
                this.#approvedInfo.style.display = isApproved ? '' : 'none';
            }
            if (this.#inviteeLink && inviteeUrl) {
                this.#inviteeLink.href = inviteeUrl;
            }

            if (this.#approveBtn) {
                this.#approveBtn.disabled    = isApproved;
                this.#approveBtn.textContent = isApproved ? 'Approved' : 'Approve';
            }
            if (this.#denyBtn) {
                this.#denyBtn.disabled    = isDenied && !isApproved;
                this.#denyBtn.textContent = isDenied ? 'Denied' : 'Deny';
            }
        }

        async #save() {
            if (!this.#current || !this.#form) return;

            const formData = new FormData(this.#form);
            formData.set('action', 'eim_update_invitee_request');
            formData.set('nonce',  config.updateNonce);

            this.#saveBtn.disabled = true;
            this.#hideSaveNotice();

            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });
                const { success, data } = await response.json();

                if (!success) {
                    this.#showSaveNotice('Could not save changes.', 'error');
                    return;
                }

                // Reflect saved data in the table row.
                const row = document.querySelector(`tr[data-riar-id="${this.#current.id}"]`);
                if (row && data?.data) {
                    const saved = data.data;
                    const cells = row.querySelectorAll('td');
                    if (cells[1]) cells[1].textContent = saved.first_name ?? '';
                    if (cells[2]) cells[2].textContent = saved.last_name  ?? '';
                    if (cells[3]) cells[3].textContent = saved.email      ?? '';
                    if (cells[4]) cells[4].textContent = saved.phone      || '—';

                    // Update the cached data-request attribute.
                    const cached = this.#parseRowData(row);
                    if (cached) {
                        Object.assign(cached, {
                            firstName:     saved.first_name ?? cached.firstName,
                            lastName:      saved.last_name  ?? cached.lastName,
                            email:         saved.email      ?? cached.email,
                            phone:         saved.phone      ?? cached.phone,
                            streetAddress: saved.street_address ?? cached.streetAddress,
                            city:          saved.city  ?? cached.city,
                            state:         saved.state ?? cached.state,
                            zipCode:       saved.zip_code ?? cached.zipCode,
                            notes:         saved.notes ?? cached.notes,
                        });
                        row.dataset.request = JSON.stringify(cached);
                    }
                }

                this.#showSaveNotice('Changes saved.', 'success');
            } catch (err) {
                console.error('[EIM] Save request failed:', err);
                this.#showSaveNotice('Save failed. Please try again.', 'error');
            } finally {
                this.#saveBtn.disabled = false;
            }
        }

        async #approve() {
            if (!this.#current) return;

            this.#approveBtn.disabled = true;

            try {
                const body = new URLSearchParams({
                    action: 'eim_approve_invitee_request',
                    nonce:  config.approveNonce,
                    id:     String(this.#current.id),
                });
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                const { success, data } = await response.json();

                if (!success) {
                    this.#showSaveNotice('Could not approve request. Please try again.', 'error');
                    this.#approveBtn.disabled = false;
                    return;
                }

                // Update modal UI.
                this.#current.status           = 'approved';
                this.#current.approvedInviteeUrl = data.invitee_url;
                this.#updateStatusUI('approved', data.invitee_url);

                // Update the status cell in the table row.
                this.#updateRowStatus(this.#current.id, 'approved', data.badge_html);

            } catch (err) {
                console.error('[EIM] Approve request failed:', err);
                this.#showSaveNotice('Approve failed. Please try again.', 'error');
                this.#approveBtn.disabled = false;
            }
        }

        async #deny() {
            if (!this.#current) return;

            this.#denyBtn.disabled = true;

            try {
                const body = new URLSearchParams({
                    action: 'eim_deny_invitee_request',
                    nonce:  config.denyNonce,
                    id:     String(this.#current.id),
                });
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });
                const { success, data } = await response.json();

                if (!success) {
                    this.#showSaveNotice('Could not deny request. Please try again.', 'error');
                    this.#denyBtn.disabled = false;
                    return;
                }

                this.#current.status = 'denied';
                this.#updateStatusUI('denied');

                this.#updateRowStatus(this.#current.id, 'denied', data.badge_html);

            } catch (err) {
                console.error('[EIM] Deny request failed:', err);
                this.#showSaveNotice('Deny failed. Please try again.', 'error');
                this.#denyBtn.disabled = false;
            }
        }

        /** Patches the status badge cell and cached data attribute on the matching row. */
        #updateRowStatus(id, status, badgeHtml) {
            const row = document.querySelector(`tr[data-riar-id="${id}"]`);
            if (!row) return;

            const statusCell = row.querySelector('.eim-riar-status-cell');
            if (statusCell && badgeHtml) statusCell.innerHTML = badgeHtml;

            const cached = this.#parseRowData(row);
            if (cached) {
                cached.status = status;
                row.dataset.request = JSON.stringify(cached);
            }
        }

        #parseRowData(row) {
            try {
                return JSON.parse(row.dataset.request || 'null');
            } catch {
                return null;
            }
        }

        #setField(id, value) {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.tagName === 'TEXTAREA') {
                el.value = value ?? '';
            } else {
                el.value = value ?? '';
            }
        }

        #showSaveNotice(text, type) {
            if (!this.#saveNotice) return;
            this.#saveNotice.textContent  = text;
            this.#saveNotice.style.color  = type === 'error' ? '#d63638' : '#3c763d';
            this.#saveNotice.style.display = '';
            window.clearTimeout(this._noticeTimer);
            this._noticeTimer = window.setTimeout(() => this.#hideSaveNotice(), 4000);
        }

        #hideSaveNotice() {
            if (this.#saveNotice) this.#saveNotice.style.display = 'none';
        }
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    const openModalOnClick = (tbodyId, modal) => {
        document.getElementById(tbodyId)?.addEventListener('click', (e) => {
            const btn = e.target.closest('.eim-riar-details-btn');
            if (!btn) return;
            const row = btn.closest('tr');
            if (!row) return;
            try {
                const data = JSON.parse(row.dataset.request || 'null');
                if (data) modal.open(data);
            } catch (err) {
                console.error('[EIM] Could not parse request data:', err);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        const modal = new RiarModal();

        if (config.table?.enabled) {
            new RiarTable();
            openModalOnClick('eim-riars-table-body', modal);
        }

        if (config.eventTable?.enabled) {
            new RiarEventTable();
            openModalOnClick('eim-event-riars-table-body', modal);
        }
    });
})();
