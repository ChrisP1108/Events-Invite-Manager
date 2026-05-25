/* global ajaxurl, eimGiftsAdmin */

/**
 * Admin gifts page interactions.
 *
 * Drives the Gifts list table live search, sortable columns, and pagination
 * via WordPress AJAX, and the event picker on the add/edit form.
 */
(() => {
    'use strict';

    const config = window.eimGiftsAdmin ?? {};

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

    // =========================================================================
    // Gift image modal + Media Library picker
    // =========================================================================

    class GiftImageModal {
        #overlay = null;
        #image = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;

                const trigger = event.target.closest('.eim-gift-image-thumb');
                if (!trigger) return;

                const fullSrc = trigger.dataset.fullSrc || trigger.getAttribute('href') || '';
                if (!fullSrc) return;

                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || trigger.getAttribute('aria-label') || 'Gift image');
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.#close();
            });
        }

        #ensureModal() {
            if (this.#overlay) return;

            this.#overlay = document.createElement('div');
            this.#overlay.className = 'eim-gift-image-modal-backdrop';
            this.#overlay.hidden = true;

            const modal = document.createElement('div');
            modal.className = 'eim-gift-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-gift-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = '×';

            this.#image = document.createElement('img');
            this.#image.alt = '';

            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-gift-image-modal-caption';

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
            document.body.classList.add('eim-gift-image-modal-open');
        }

        #close() {
            if (!this.#overlay || this.#overlay.hidden) return;
            this.#overlay.hidden = true;
            if (this.#image) this.#image.removeAttribute('src');
            document.body.classList.remove('eim-gift-image-modal-open');
        }
    }

    class GiftImagePicker {
        #field;
        #preview;
        #select;
        #remove;
        #frame = null;

        constructor() {
            this.#field   = document.getElementById('eim_g_image_attachment_id');
            this.#preview = document.getElementById('eim_g_image_preview');
            this.#select  = document.getElementById('eim_g_image_select');
            this.#remove  = document.getElementById('eim_g_image_remove');

            if (!this.#field || !this.#preview || !this.#select || !window.wp?.media) return;

            this.#select.addEventListener('click', () => this.#openMediaFrame());
            this.#remove?.addEventListener('click', () => this.#renderSelection(null));
        }

        #openMediaFrame() {
            if (!this.#frame) {
                this.#frame = window.wp.media({
                    title: 'Select Gift Image',
                    button: { text: 'Use This Image' },
                    library: { type: 'image' },
                    multiple: false,
                });

                this.#frame.on('select', () => {
                    const attachment = this.#frame.state().get('selection').first()?.toJSON();
                    if (!attachment) return;
                    this.#renderSelection({
                        id: attachment.id || 0,
                        title: attachment.title || attachment.filename || 'Gift image',
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
                button.className = 'button-link eim-gift-image-thumb';
                button.dataset.fullSrc = image.fullUrl || image.thumbUrl;
                button.dataset.caption = image.title || 'Gift image';
                button.setAttribute('aria-label', `View full-size image for ${image.title || 'gift'}`);

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

    // =========================================================================
    // GiftTable — AJAX list search, sort, and pagination
    // =========================================================================

    class GiftTable {
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
            this.#table        = document.getElementById('eim-gifts-table');
            this.#tbody        = document.getElementById('eim-gifts-table-body');
            this.#search       = document.getElementById('eim-gift-search');
            this.#field        = document.getElementById('eim-gift-search-field');
            this.#count        = document.getElementById('eim-gift-count');
            this.#spinner      = document.getElementById('eim-gift-loading');
            this.#perPageSel   = document.getElementById('eim-gift-search-per-page');
            this.#paginationNav = document.getElementById('eim-gift-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'name';
            this.#order   = this.#table.dataset.order || config.table?.order || 'asc';
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
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#setLoading(true);
            try {
                const url = ajaxUrl('eim_search_gifts_list', {
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
                console.error('[EIM] Gift search failed:', err);
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

    // =========================================================================
    // EventGiftTable — AJAX registry table on the event edit screen
    // =========================================================================

    class EventGiftTable {
        #table;
        #tbody;
        #search;
        #field;
        #count;
        #spinner;
        #perPageSel;
        #paginationNav;
        #sort;
        #order;
        #page = 1;
        #perPage = 10;

        constructor() {
            this.#table         = document.getElementById('eim-event-gifts-table');
            this.#tbody         = document.getElementById('eim-event-gifts-table-body');
            this.#search        = document.getElementById('eim-event-gifts-search');
            this.#field         = document.getElementById('eim-event-gifts-search-field');
            this.#count         = document.getElementById('eim-event-gifts-count');
            this.#spinner       = document.getElementById('eim-event-gifts-loading');
            this.#perPageSel    = document.getElementById('eim-event-gifts-search-per-page');
            this.#paginationNav = document.getElementById('eim-event-gifts-search-pagination');

            if (!this.#table || !this.#tbody || !config.eventRegistry?.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || 'name';
            this.#order   = this.#table.dataset.order || 'asc';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });
            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#setLoading(true);
            try {
                const url = ajaxUrl('eim_search_event_gifts', {
                    nonce:    config.eventRegistry.searchNonce,
                    event_id: config.eventRegistry.eventId || this.#table?.dataset.eventId || 0,
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
                console.error('[EIM] Event gift search failed:', err);
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

    // =========================================================================
    // EventGiftPicker — autocomplete attach existing gifts to an event
    // =========================================================================

    class EventGiftPicker {
        #input;
        #hidden;
        #selected;
        #dropdown;
        #timer = 0;

        constructor() {
            this.#input    = document.getElementById('eim_event_gift_search');
            this.#hidden   = document.getElementById('eim_event_gift_id');
            this.#selected = document.getElementById('eim_event_gift_selected');

            if (!this.#input || !this.#hidden || !config.suggestGiftsNonce) return;

            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', () => {
                this.#hidden.value = '';
                if (this.#selected) this.#selected.textContent = '';
                window.clearTimeout(this.#timer);
                this.#timer = window.setTimeout(() => this.#search(), 250);
            });
            this.#input.addEventListener('blur', () => {
                window.setTimeout(() => { this.#dropdown.style.display = 'none'; }, 150);
            });
        }

        async #search() {
            const query = this.#input?.value.trim() || '';
            if (query.length < 1) {
                this.#dropdown.style.display = 'none';
                return;
            }

            try {
                const url = ajaxUrl('eim_suggest_gifts', {
                    nonce:            config.suggestGiftsNonce,
                    query,
                    exclude_event_id: config.eventRegistry?.eventId || this.#input?.dataset.eventId || 0,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                this.#render(success ? data : []);
            } catch (err) {
                console.error('[EIM] Gift suggest failed:', err);
                this.#dropdown.style.display = 'none';
            }
        }

        #render(gifts) {
            this.#dropdown.replaceChildren();
            if (!gifts.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching gifts found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const gift of gifts) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const strong = document.createElement('strong');
                    strong.textContent = gift.name || gift.label || '';
                    li.appendChild(strong);
                    li.addEventListener('mousedown', (event) => {
                        event.preventDefault();
                        this.#select(gift);
                    });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        #select(gift) {
            this.#hidden.value = String(gift.id || '');
            if (this.#input) this.#input.value = gift.name || gift.label || '';
            if (this.#selected) this.#selected.textContent = gift.name ? `Selected: ${gift.name}` : '';
            this.#dropdown.style.display = 'none';
        }
    }

    // =========================================================================
    // EventPicker — autocomplete picker + sortable/searchable selected-events list
    // =========================================================================

    class EventPicker {
        /** @type {HTMLElement|null} */
        #container;
        /** @type {HTMLInputElement|null} */
        #searchInput;
        /** @type {HTMLUListElement} */
        #dropdown;
        /** @type {HTMLElement|null} */
        #listWrap;
        /** @type {HTMLTableSectionElement|null} */
        #tbody;
        /** @type {HTMLElement|null} */
        #filterBar;
        /** @type {HTMLInputElement|null} */
        #filterInput;
        /** @type {HTMLElement|null} */
        #countEl;
        /** @type {HTMLElement|null} */
        #hiddenInputs;
        /** @type {string} */
        #inputName;
        /** @type {string} */
        #nonce;
        /** @type {Set<number>} */
        #selectedIds;
        /** @type {string} */
        #sort;
        /** @type {string} */
        #order;
        /** @type {ReturnType<typeof setTimeout>|null} */
        #debounceTimer;

        constructor(containerId, { nonce }) {
            this.#container = document.getElementById(containerId);
            if (!this.#container) return;

            this.#nonce         = nonce;
            this.#inputName     = this.#container.dataset.inputName || 'event_ids[]';
            this.#sort          = 'name';
            this.#order         = 'asc';
            this.#debounceTimer = null;

            this.#searchInput  = this.#container.querySelector('.eim-event-picker-search');
            this.#listWrap     = this.#container.querySelector('.eim-event-picker-list-wrap');
            this.#tbody        = this.#container.querySelector('.eim-event-picker-tbody');
            this.#filterBar    = this.#container.querySelector('.eim-event-picker-filter-bar');
            this.#filterInput  = this.#container.querySelector('.eim-event-picker-filter');
            this.#countEl      = this.#container.querySelector('.eim-event-picker-count');
            this.#hiddenInputs = this.#container.querySelector('.eim-event-picker-hidden-inputs');

            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#searchInput?.parentElement?.appendChild(this.#dropdown);

            this.#selectedIds = new Set(
                [...(this.#tbody?.querySelectorAll('tr[data-event-id]') ?? [])].map(
                    r => Number(r.dataset.eventId)
                ).filter(Boolean)
            );

            this.#bindEvents();
        }

        #bindEvents() {
            this.#searchInput?.addEventListener('input', () => {
                clearTimeout(this.#debounceTimer);
                this.#debounceTimer = setTimeout(() => this.#doSearch(), 250);
            });
            this.#searchInput?.addEventListener('blur', () => {
                setTimeout(() => { this.#dropdown.style.display = 'none'; }, 150);
            });

            this.#filterInput?.addEventListener('input', () => this.#applyFilter());

            for (const link of this.#container.querySelectorAll('.eim-event-sort')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#sortRows();
                    this.#updateSortLinks();
                });
            }

            this.#tbody?.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-event-picker-remove');
                if (btn) {
                    const row = btn.closest('tr');
                    this.#removeEvent(Number(row?.dataset.eventId));
                }
            });
        }

        async #doSearch() {
            const query = this.#searchInput?.value.trim() || '';
            if (query.length < 1) {
                this.#dropdown.style.display = 'none';
                return;
            }
            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action',      'eim_suggest_events');
                url.searchParams.set('nonce',        this.#nonce);
                url.searchParams.set('query',        query);
                url.searchParams.set('exclude_ids', [...this.#selectedIds].join(','));

                const { success, data } = await (
                    await fetch(url, { credentials: 'same-origin' })
                ).json();
                this.#renderDropdown(success ? data : []);
            } catch (err) {
                console.error('[EIM] Event suggest failed:', err);
                this.#dropdown.style.display = 'none';
            }
        }

        #renderDropdown(events) {
            this.#dropdown.replaceChildren();
            if (!events.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching events found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const ev of events) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const strong = document.createElement('strong');
                    strong.textContent = ev.name;
                    li.appendChild(strong);
                    if (ev.start_label) {
                        li.appendChild(document.createTextNode(` — ${ev.start_label}`));
                    }
                    li.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        this.#addEvent(ev);
                    });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        #addEvent(ev) {
            this.#selectedIds.add(ev.id);
            if (this.#searchInput) this.#searchInput.value = '';
            this.#dropdown.style.display = 'none';

            const tr = document.createElement('tr');
            tr.dataset.eventId = String(ev.id);
            tr.dataset.name    = (ev.name || '').toLowerCase();
            tr.dataset.start   = ev.start_raw || '';
            tr.dataset.end     = ev.end_raw   || '';
            tr.innerHTML = `
                <td>${this.#escHtml(ev.name)}</td>
                <td>${this.#escHtml(ev.start_label || '—')}</td>
                <td>${this.#escHtml(ev.end_label   || '—')}</td>
                <td><button type="button" class="button button-small eim-event-picker-remove">Remove</button></td>
            `;
            this.#tbody?.appendChild(tr);

            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = this.#inputName;
            input.value = String(ev.id);
            input.dataset.eventId = String(ev.id);
            this.#hiddenInputs?.appendChild(input);

            this.#updateListUI();
            this.#sortRows();
        }

        #removeEvent(eventId) {
            if (!eventId) return;
            this.#selectedIds.delete(eventId);
            this.#tbody?.querySelector(`tr[data-event-id="${eventId}"]`)?.remove();
            this.#hiddenInputs?.querySelector(`input[data-event-id="${eventId}"]`)?.remove();
            this.#updateListUI();
        }

        #updateListUI() {
            const total = this.#tbody?.querySelectorAll('tr').length ?? 0;
            if (this.#listWrap) this.#listWrap.style.display = total > 0 ? '' : 'none';
            if (this.#filterBar) this.#filterBar.style.display = total >= 2 ? '' : 'none';
            this.#applyFilter();
        }

        #applyFilter() {
            const query = (this.#filterInput?.value || '').toLowerCase().trim();
            let visible = 0;
            for (const row of this.#tbody?.querySelectorAll('tr') ?? []) {
                const matches = !query || (row.dataset.name || '').includes(query);
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }
            if (this.#countEl) {
                this.#countEl.textContent = `${visible} event${visible === 1 ? '' : 's'}`;
            }
        }

        #sortRows() {
            if (!this.#tbody) return;
            const rows = [...this.#tbody.querySelectorAll('tr')];
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

        #updateSortLinks() {
            for (const link of this.#container?.querySelectorAll('.eim-event-sort') ?? []) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }

        #escHtml(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }
    }

    // =========================================================================
    // Boot
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        new GiftImageModal();
        if (config.table?.enabled) new GiftTable();
        if (config.eventRegistry?.enabled) {
            new EventGiftTable();
            new EventGiftPicker();
        }
        if (config.form?.enabled) {
            new GiftImagePicker();
            new EventPicker('eim-gift-event-picker', { nonce: config.suggestEventsNonce || '' });
        }
    });
})();
