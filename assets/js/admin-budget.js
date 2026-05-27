/* global ajaxurl, eimBudgetAdmin */

/**
 * Admin budget page interactions.
 *
 * Handles three independent features driven by eimBudgetAdmin config:
 *   - BudgetPlansTable  — AJAX search + sort on the plans list view
 *   - LineItemsTable    — AJAX search + sort on the plan detail view
 *   - CategoryTable     — client-side sort on the category summary table
 */
(() => {
    'use strict';

    const config = window.eimBudgetAdmin ?? {};

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
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
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

    // =========================================================================
    // BudgetPlansTable — AJAX search + sort for the plans list
    // =========================================================================

    /**
     * Manages the AJAX search and sort behaviour for the budget plans list table.
     */
    class BudgetPlansTable {
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
            this.#table        = document.getElementById('eim-budget-plans-table');
            this.#tbody        = document.getElementById('eim-budget-plans-table-body');
            this.#search       = document.getElementById('eim-budget-plan-search');
            this.#field        = document.getElementById('eim-budget-plan-search-field');
            this.#count        = document.getElementById('eim-budget-plan-count');
            this.#spinner      = document.getElementById('eim-budget-plan-loading');
            this.#perPageSel   = document.getElementById('eim-budget-plan-search-per-page');
            this.#paginationNav = document.getElementById('eim-budget-plan-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || 'name';
            this.#order   = this.#table.dataset.order || 'asc';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_budget_plans', {
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
            } catch (err) {
                console.error('[EIM] Budget plan search failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
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
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // LineItemsTable — AJAX search + sort for line items on plan detail view
    // =========================================================================

    /**
     * Manages the AJAX search and sort behaviour for the line items table on the
     * plan detail view.
     */
    class LineItemsTable {
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
        #planId;
        /** @type {number} */
        #page = 1;
        /** @type {number} */
        #perPage = 10;

        constructor() {
            this.#table        = document.getElementById('eim-line-items-table');
            this.#tbody        = document.getElementById('eim-line-items-table-body');
            this.#search       = document.getElementById('eim-line-item-search');
            this.#field        = document.getElementById('eim-line-item-search-field');
            this.#count        = document.getElementById('eim-line-item-count');
            this.#spinner      = document.getElementById('eim-line-item-loading');
            this.#perPageSel   = document.getElementById('eim-line-item-search-per-page');
            this.#paginationNav = document.getElementById('eim-line-item-search-pagination');

            if (!this.#table || !this.#tbody || !config.lineItemNonce) return;

            this.#planId  = Number(this.#table.dataset.planId || config.planId || 0);
            this.#sort    = this.#table.dataset.sort  || config.lineItems?.sort  || 'sort_order';
            this.#order   = this.#table.dataset.order || config.lineItems?.order || 'asc';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });
            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'sort_order';
                    this.#order = link.dataset.order || 'asc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_budget_line_items', {
                    nonce:    config.lineItemNonce,
                    plan_id:  this.#planId,
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
            } catch (err) {
                console.error('[EIM] Line item search failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
            }
        }

        /**
         * Refreshes the sort-link indicators and their `data-order` attributes to
         * reflect the current sort column and direction.
         *
         * @returns {void}
         */
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
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // CategoryTable — client-side sort for the category summary table
    // =========================================================================

    /**
     * Manages client-side column sorting for the budget category summary table.
     * Rows are sorted in-place using `data-val` attributes on each `<td>`.
     */
    class CategoryTable {
        /** @type {HTMLTableElement|null} */
        #table;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /** @type {string} */
        #sort;

        /** @type {string} */
        #order;

        /**
         * Locates the category summary table in the DOM and wires up sort-link
         * click listeners. Aborts silently if the table is not present.
         */
        constructor() {
            this.#table = document.getElementById('eim-budget-category-table');
            this.#tbody = document.getElementById('eim-budget-category-tbody');

            if (!this.#table || !this.#tbody) return;

            this.#sort  = this.#table.dataset.sort  || 'category';
            this.#order = this.#table.dataset.order || 'asc';

            for (const link of this.#table.querySelectorAll('.eim-cat-sort')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'category';
                    this.#order = link.dataset.order || 'asc';
                    this.#sortRows();
                    this.#updateLinks();
                });
            }
        }

        /**
         * Sorts the tbody rows in-place using the `data-val` attribute of the
         * column matching the current sort key.
         *
         * @returns {void}
         */
        #sortRows() {
            const rows = [...this.#tbody.querySelectorAll('tr')];
            const colIndex = { category: 0, estimated: 1, paid: 2 }[this.#sort] ?? 0;
            const isNumeric = this.#sort !== 'category';
            const mul = this.#order === 'desc' ? -1 : 1;

            rows.sort((a, b) => {
                const aVal = a.querySelectorAll('td')[colIndex]?.dataset.val ?? '';
                const bVal = b.querySelectorAll('td')[colIndex]?.dataset.val ?? '';
                if (isNumeric) return mul * (Number(aVal) - Number(bVal));
                return mul * aVal.localeCompare(bVal);
            });

            for (const row of rows) this.#tbody.appendChild(row);
        }

        /**
         * Refreshes the sort-link indicators and their `data-order` attributes to
         * reflect the current sort column and direction.
         *
         * @returns {void}
         */
        #updateLinks() {
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-cat-sort')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // =========================================================================
    // LineItemImageModal — full-size lightbox for line item images
    // =========================================================================

    class LineItemImageModal {
        #overlay = null;
        #image = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const trigger = event.target.closest('.eim-li-image-thumb');
                if (!trigger) return;
                const fullSrc = trigger.dataset.fullSrc || '';
                if (!fullSrc) return;
                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || 'Line item image');
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') this.#close();
            });
        }

        #ensureModal() {
            if (this.#overlay) return;
            this.#overlay = document.createElement('div');
            this.#overlay.className = 'eim-li-image-modal-backdrop';
            this.#overlay.hidden = true;
            const modal = document.createElement('div');
            modal.className = 'eim-li-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-li-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = 'x';
            this.#image = document.createElement('img');
            this.#image.alt = '';
            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-li-image-modal-caption';
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
            document.body.classList.add('eim-li-image-modal-open');
        }

        #close() {
            if (!this.#overlay || this.#overlay.hidden) return;
            this.#overlay.hidden = true;
            if (this.#image) this.#image.removeAttribute('src');
            document.body.classList.remove('eim-li-image-modal-open');
        }
    }

    // =========================================================================
    // LineItemImagePicker — WordPress media picker for line item images
    // =========================================================================

    class LineItemImagePicker {
        #field;
        #preview;
        #select;
        #remove;
        #frame = null;

        constructor() {
            this.#field   = document.getElementById('eim_li_image_attachment_id');
            this.#preview = document.getElementById('eim_li_image_preview');
            this.#select  = document.getElementById('eim_li_image_select');
            this.#remove  = document.getElementById('eim_li_image_remove');

            if (!this.#field || !this.#preview || !this.#select || !window.wp?.media) return;

            window.eimLineItemImagePicker = this;

            this.#select.addEventListener('click', () => this.#openMediaFrame());
            this.#remove?.addEventListener('click', () => this.#renderSelection(null));
        }

        setSelection(image) {
            this.#renderSelection(image && Number(image.id) > 0 ? image : null);
        }

        clearSelection() {
            this.#renderSelection(null);
        }

        #openMediaFrame() {
            if (!this.#frame) {
                this.#frame = window.wp.media({
                    title: 'Select Line Item Image',
                    button: { text: 'Use This Image' },
                    library: { type: 'image' },
                    multiple: false,
                });
                this.#frame.on('select', () => {
                    const attachment = this.#frame.state().get('selection').first()?.toJSON();
                    if (!attachment) return;
                    this.#renderSelection({
                        id: attachment.id || 0,
                        title: attachment.title || attachment.filename || 'Line item image',
                        thumbUrl: attachment.sizes?.thumbnail?.url || attachment.sizes?.medium?.url || attachment.url || '',
                        fullUrl: attachment.sizes?.full?.url || attachment.url || '',
                    });
                });
            }
            this.#frame.open();
        }

        #renderSelection(image) {
            const hasImage = image && Number(image.id) > 0 && image.thumbUrl;
            this.#field.value = hasImage ? String(image.id) : '0';
            this.#preview.replaceChildren();

            if (hasImage) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-link eim-li-image-thumb';
                button.dataset.fullSrc = image.fullUrl || image.thumbUrl;
                button.dataset.caption = image.title || 'Line item image';
                button.setAttribute('aria-label', `View full-size image for ${image.title || 'line item'}`);
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
    // LineItemEditForm — pre-fill add form when Edit is clicked on a row
    // =========================================================================

    /**
     * Pre-fills the add/edit line item form when an "Edit" link is clicked on a
     * table row, and resets the form back to "Add" mode on cancel.
     */
    class LineItemEditForm {
        /** @type {HTMLElement|null} */
        #formWrapper;

        /** @type {HTMLElement|null} */
        #formTitle;

        /** @type {HTMLFormElement|null} */
        #form;

        /** @type {HTMLInputElement|null} */
        #submitBtn;

        /** @type {HTMLElement|null} */
        #cancelWrap;

        /** @type {HTMLInputElement|null} */
        #itemIdInput;

        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        /**
         * Locates all required form and table elements, then registers a delegated
         * click listener on the tbody so Edit links work after AJAX refreshes.
         */
        constructor() {
            this.#formWrapper = document.getElementById('eim-budget-line-item-form');
            this.#formTitle   = document.getElementById('eim-li-form-title');
            this.#form        = this.#formWrapper?.querySelector('form');
            this.#submitBtn   = document.getElementById('eim-li-submit');
            this.#cancelWrap  = document.getElementById('eim-li-cancel-wrap');
            this.#itemIdInput = this.#form?.querySelector('[name="line_item_id"]');
            this.#tbody       = document.getElementById('eim-line-items-table-body');

            if (!this.#form || !this.#tbody) return;

            // Use event delegation so it works after AJAX refreshes the tbody.
            this.#tbody.addEventListener('click', (e) => {
                const link = e.target.closest('.eim-edit-line-item');
                if (!link) return;
                e.preventDefault();
                this.#populate(link.dataset);
                this.#formWrapper?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });

            // Handle Edit clicks from the Payments tab — switch to Line Items tab first,
            // then populate and scroll once the panel is visible.
            document.addEventListener('click', (e) => {
                const link = e.target.closest('.eim-edit-line-item');
                if (!link) return;
                if (!link.closest('#eim-btab-payments')) return;
                e.preventDefault();
                window.eimBudgetPlanActivateTab?.('line-items');
                this.#populate(link.dataset);
                // requestAnimationFrame ensures scrollIntoView runs after the tab panel's
                // display:block is applied (the class toggle is synchronous; rAF fires
                // after the next layout pass).
                requestAnimationFrame(() => {
                    this.#formWrapper?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            document.getElementById('eim-li-cancel')?.addEventListener('click', (e) => {
                e.preventDefault();
                this.#reset();
            });
        }

        /**
         * Pre-fills every form field using the `data-*` attributes read from the
         * clicked edit link, and switches the form into "Edit" mode.
         *
         * @param {DOMStringMap} d The `dataset` object from the `.eim-edit-line-item` element.
         * @returns {void}
         */
        #populate(d) {
            this.#setField('line_item_id',      d.id || '0');
            this.#setField('global_item_id',    d.globalItemId || '0');
            this.#setField('label',             d.label || '');
            this.#setField('event_id',          d.eventId || '0');
            this.#setField('unit_cost',         d.unitCost || '');
            this.#setField('paid_amount',       d.paid || '0.00');
            this.#setField('website_url',       d.websiteUrl || '');
            this.#setField('payment_deadline',  d.paymentDeadline || '');
            this.#setField('notes',             d.notes || '');

            const qtyMode = this.#form.querySelector('[name="quantity_mode"]');
            if (qtyMode) {
                qtyMode.value = d.quantityMode || 'fixed';
                qtyMode.dispatchEvent(new Event('change'));
            }
            this.#setField('quantity', d.quantity || '1');

            const vendorPicker = window.eimVendorPickers?.['eim-li-vendor-picker'];
            vendorPicker?.setValue(Number(d.vendorId || 0), d.vendorName || '');

            const catPicker = window.eimCategoryPickers?.['eim-li-cat-picker'];
            if (catPicker) {
                try {
                    const cats = JSON.parse(d.categories || '[]');
                    catPicker.setSelected(cats);
                } catch { catPicker.clear(); }
            }

            const imagePicker = window.eimLineItemImagePicker;
            if (imagePicker) {
                const attachId = Number(d.imageAttachmentId || 0);
                if (attachId > 0 && d.imageThumbUrl) {
                    imagePicker.setSelection({
                        id: attachId,
                        title: d.imageTitle || d.label || 'Line item image',
                        thumbUrl: d.imageThumbUrl,
                        fullUrl: d.imageFullUrl || d.imageThumbUrl,
                    });
                } else {
                    imagePicker.clearSelection();
                }
            }

            if (this.#formTitle)  this.#formTitle.textContent = 'Edit Line Item';
            if (this.#submitBtn)  this.#submitBtn.value = 'Update Line Item';
            if (this.#cancelWrap) this.#cancelWrap.style.display = '';
        }

        /**
         * Clears all form fields and switches the form back to "Add Line Item" mode.
         *
         * @returns {void}
         */
        #reset() {
            this.#setField('line_item_id',     '0');
            this.#setField('global_item_id',   '0');
            this.#setField('label',            '');
            this.#setField('event_id',         '0');
            this.#setField('unit_cost',        '');
            this.#setField('paid_amount',      '0.00');
            this.#setField('website_url',      '');
            this.#setField('payment_deadline', '');
            this.#setField('notes',            '');
            this.#setField('quantity',         '1');

            const qtyMode = this.#form.querySelector('[name="quantity_mode"]');
            if (qtyMode) {
                qtyMode.value = 'fixed';
                qtyMode.dispatchEvent(new Event('change'));
            }

            window.eimVendorPickers?.['eim-li-vendor-picker']?.clear();
            window.eimCategoryPickers?.['eim-li-cat-picker']?.clear();
            window.eimLineItemImagePicker?.clearSelection();

            if (this.#formTitle)  this.#formTitle.textContent = 'Add Line Item';
            if (this.#submitBtn)  this.#submitBtn.value = 'Add Line Item';
            if (this.#cancelWrap) this.#cancelWrap.style.display = 'none';
        }

        /**
         * Sets the value of the form field with the given name attribute.
         *
         * @param {string} name  The `name` attribute of the form control to update.
         * @param {string} value The value to assign.
         * @returns {void}
         */
        #setField(name, value) {
            const el = this.#form?.querySelector(`[name="${name}"]`);
            if (el) el.value = value;
        }
    }

    // =========================================================================
    // LineItemLibraryPicker — suggest autocomplete to pick an existing global item
    // =========================================================================

    /**
     * Typeahead picker that lets the user choose an existing global budget item
     * on the "Add Line Item" form inside a budget plan detail page.
     *
     * When an item is selected, the global fields (label, vendor, unit cost,
     * website URL, notes, image) are pre-filled from the library record and
     * global_item_id is set so the save handler knows to link (not create).
     * Clearing the selection resets global_item_id to 0 and leaves the form
     * fields editable for creating a brand-new item.
     */
    class LineItemLibraryPicker {
        #searchInput;
        #dropdown;
        #selectedBar;
        #selectedLabel;
        #clearBtn;
        #globalItemIdField;
        #debounceTimer = null;

        constructor() {
            this.#searchInput      = document.getElementById('eim_li_library_search');
            this.#dropdown         = document.getElementById('eim-li-library-dropdown');
            this.#selectedBar      = document.getElementById('eim-li-library-selected');
            this.#selectedLabel    = document.getElementById('eim-li-library-selected-label');
            this.#clearBtn         = document.getElementById('eim-li-library-clear');
            this.#globalItemIdField = document.getElementById('eim_li_global_item_id');

            if (!this.#searchInput || !this.#dropdown || !config.suggestItemsNonce) return;

            this.#searchInput.addEventListener('input', () => {
                clearTimeout(this.#debounceTimer);
                this.#debounceTimer = setTimeout(() => this.#doSearch(), 250);
            });
            this.#searchInput.addEventListener('blur', () => {
                setTimeout(() => { this.#dropdown.style.display = 'none'; }, 160);
            });
            this.#clearBtn?.addEventListener('click', (e) => {
                e.preventDefault();
                this.#clearSelection();
            });
        }

        async #doSearch() {
            const query = this.#searchInput?.value.trim() || '';
            if (query.length < 1) { this.#dropdown.style.display = 'none'; return; }

            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action', 'eim_suggest_budget_items');
                url.searchParams.set('nonce',   config.suggestItemsNonce);
                url.searchParams.set('query',   query);
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                this.#renderDropdown(success ? data : []);
            } catch (err) {
                console.error('[EIM] Budget item suggest failed:', err);
                this.#dropdown.style.display = 'none';
            }
        }

        #renderDropdown(items) {
            this.#dropdown.replaceChildren();
            if (!items.length) {
                const empty = document.createElement('div');
                empty.style.cssText = 'padding:10px 14px;color:#646970;font-size:13px;';
                empty.textContent = 'No matching items found.';
                this.#dropdown.appendChild(empty);
            } else {
                for (const item of items) {
                    const row = document.createElement('div');
                    row.style.cssText = 'padding:8px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
                    row.addEventListener('mouseover', () => { row.style.background = '#f0f6ff'; });
                    row.addEventListener('mouseout',  () => { row.style.background = ''; });
                    const name = document.createElement('strong');
                    name.textContent = item.label;
                    row.appendChild(name);
                    if (item.vendor_name) {
                        const vendor = document.createElement('span');
                        vendor.style.cssText = 'color:#646970;font-size:12px;margin-left:8px;';
                        vendor.textContent = item.vendor_name;
                        row.appendChild(vendor);
                    }
                    const cost = document.createElement('span');
                    cost.style.cssText = 'float:right;color:#2271b1;font-size:12px;';
                    cost.textContent = item.unit_cost_fmt;
                    row.appendChild(cost);
                    row.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        this.#selectItem(item);
                    });
                    this.#dropdown.appendChild(row);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        #selectItem(item) {
            // Set global_item_id so the save handler links this existing item.
            if (this.#globalItemIdField) this.#globalItemIdField.value = String(item.id);

            // Pre-fill global form fields.
            const form = document.getElementById('eim-budget-line-item-form')?.querySelector('form');
            if (form) {
                const setField = (name, value) => {
                    const el = form.querySelector(`[name="${name}"]`);
                    if (el) el.value = value;
                };
                setField('label',       item.label        || '');
                setField('vendor_id',   String(item.vendor_id || 0));
                setField('unit_cost',   item.unit_cost_cents > 0 ? (item.unit_cost_cents / 100).toFixed(2) : '');
                setField('website_url', item.website_url   || '');
                setField('notes',       item.notes         || '');
            }

            // Set vendor picker display.
            window.eimVendorPickers?.['eim-li-vendor-picker']?.setValue(
                Number(item.vendor_id || 0),
                item.vendor_name || ''
            );

            // Set image picker if there is one.
            if (item.image_id > 0 && item.image_thumb_url) {
                window.eimLineItemImagePicker?.setSelection({
                    id:       item.image_id,
                    title:    item.label,
                    thumbUrl: item.image_thumb_url,
                    fullUrl:  item.image_thumb_url,
                });
            } else {
                window.eimLineItemImagePicker?.clearSelection();
            }

            // Update UI.
            if (this.#searchInput)   this.#searchInput.value = '';
            if (this.#dropdown)      this.#dropdown.style.display = 'none';
            if (this.#selectedLabel) this.#selectedLabel.textContent = item.label;
            if (this.#selectedBar)   this.#selectedBar.style.display = '';
        }

        #clearSelection() {
            if (this.#globalItemIdField) this.#globalItemIdField.value = '0';
            if (this.#selectedBar)       this.#selectedBar.style.display = 'none';
            if (this.#selectedLabel)     this.#selectedLabel.textContent = '';
        }
    }

    // =========================================================================
    // EventPicker — autocomplete picker + sortable/searchable selected-events list
    // =========================================================================

    /**
     * Manages a typeahead event autocomplete picker and the selected-events
     * mini-table. Fires the eim_suggest_events AJAX action to fetch matches.
     */
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

        /**
         * @param {string} containerId  ID of the .eim-event-picker wrapper element.
         * @param {{nonce: string}} options
         */
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

            // Build the dropdown <ul> and inject it after the search input.
            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#searchInput?.parentElement?.appendChild(this.#dropdown);

            // Collect IDs of events already in the list (edit forms).
            this.#selectedIds = new Set(
                [...(this.#tbody?.querySelectorAll('tr[data-event-id]') ?? [])].map(
                    r => Number(r.dataset.eventId)
                ).filter(Boolean)
            );

            this.#bindEvents();
        }

        // ── Private ────────────────────────────────────────────────────────────

        /**
         * Attaches search-input, filter, sort-link, and remove-button listeners.
         *
         * @returns {void}
         */
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

        /**
         * Executes the AJAX event search and renders the dropdown.
         *
         * @returns {Promise<void>}
         */
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

        /**
         * Renders the suggestion dropdown list.
         *
         * @param {Array<object>} events  Event objects from the AJAX response.
         * @returns {void}
         */
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

        /**
         * Adds an event to the selected list, creates the hidden input, and closes the dropdown.
         *
         * @param {object} ev  Event object from the AJAX response.
         * @returns {void}
         */
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

        /**
         * Removes an event from the selected list and deletes its hidden input.
         *
         * @param {number} eventId  ID of the event to remove.
         * @returns {void}
         */
        #removeEvent(eventId) {
            if (!eventId) return;
            this.#selectedIds.delete(eventId);
            this.#tbody?.querySelector(`tr[data-event-id="${eventId}"]`)?.remove();
            this.#hiddenInputs?.querySelector(`input[data-event-id="${eventId}"]`)?.remove();
            this.#updateListUI();
        }

        /**
         * Shows or hides the list wrapper and filter bar depending on row count, then
         * re-applies the current filter.
         *
         * @returns {void}
         */
        #updateListUI() {
            const total = this.#tbody?.querySelectorAll('tr').length ?? 0;
            if (this.#listWrap) this.#listWrap.style.display = total > 0 ? '' : 'none';
            if (this.#filterBar) this.#filterBar.style.display = total >= 2 ? '' : 'none';
            this.#applyFilter();
        }

        /**
         * Filters visible rows using the current filter-input value.
         *
         * @returns {void}
         */
        #applyFilter() {
            const query   = (this.#filterInput?.value || '').toLowerCase().trim();
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

        /**
         * Sorts the visible rows by the current sort key (name, start, or end).
         *
         * @returns {void}
         */
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

        /**
         * Refreshes sort-link indicators and their next-click direction.
         *
         * @returns {void}
         */
        #updateSortLinks() {
            for (const link of this.#container?.querySelectorAll('.eim-event-sort') ?? []) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }

        /**
         * Escapes a string for safe insertion as HTML text content.
         *
         * @param {string} str  Raw string to escape.
         * @returns {string}
         */
        #escHtml(str) {
            const d = document.createElement('div');
            d.appendChild(document.createTextNode(String(str)));
            return d.innerHTML;
        }
    }

    // =========================================================================
    // PaymentSection — accordion + AJAX search/sort/pagination for Payments tab
    // =========================================================================

    class PaymentSection {
        #container;
        #status;
        #planId;
        #toggle;
        #arrow;
        #body;
        #search;
        #field;
        #perPageSel;
        #tbody;
        #countEl;
        #spinner;
        #paginationNav;
        #sort    = 'deadline';
        #order   = 'asc';
        #page    = 1;
        #perPage = 10;
        #loaded  = false;

        constructor(container) {
            this.#container    = container;
            this.#status       = container.dataset.status  || 'needs_payment';
            this.#planId       = container.dataset.planId  || '0';
            this.#toggle       = container.querySelector('.eim-payment-accordion-toggle');
            this.#arrow        = container.querySelector('.eim-payment-accordion-arrow');
            this.#body         = container.querySelector('.eim-payment-section-body');
            this.#search       = container.querySelector('.eim-payment-search-input');
            this.#field        = container.querySelector('.eim-payment-search-field');
            this.#perPageSel   = container.querySelector('.eim-payment-per-page');
            this.#tbody        = container.querySelector('.eim-payment-tbody');
            this.#countEl      = container.querySelector('.eim-payment-section-count');
            this.#spinner      = container.querySelector('.eim-payment-spinner');
            this.#paginationNav = container.querySelector('.eim-payment-pagination');

            if (!this.#toggle || !this.#body) return;

            this.#toggle.addEventListener('click', () => this.#toggleAccordion());

            this.#search?.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }, 250));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });
            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });

            // Sort link clicks (within this section only — class eim-pay-sort-link).
            this.#body.addEventListener('click', (e) => {
                const link = e.target.closest('.eim-pay-sort-link');
                if (!link) return;
                e.preventDefault();
                this.#sort  = link.dataset.sort  || 'deadline';
                this.#order = link.dataset.order || 'asc';
                this.#page  = 1;
                this.#refresh();
            });

            // Restore accordion state from localStorage.
            const storageKey = 'eim_pay_section_' + this.#planId + '_' + this.#status;
            let isOpen = false;
            try { isOpen = localStorage.getItem(storageKey) === 'open'; } catch (e) {}
            if (isOpen) this.#openAccordion(/* load */ true);
        }

        #toggleAccordion() {
            if (this.#toggle.getAttribute('aria-expanded') === 'true') {
                this.#closeAccordion();
            } else {
                this.#openAccordion(true);
            }
        }

        #openAccordion(load = true) {
            this.#body.hidden = false;
            this.#toggle.setAttribute('aria-expanded', 'true');
            if (this.#arrow) this.#arrow.textContent = '▼';
            try { localStorage.setItem('eim_pay_section_' + this.#planId + '_' + this.#status, 'open'); } catch (e) {}
            if (load && !this.#loaded) this.#refresh();
        }

        #closeAccordion() {
            this.#body.hidden = true;
            this.#toggle.setAttribute('aria-expanded', 'false');
            if (this.#arrow) this.#arrow.textContent = '▶';
            try { localStorage.setItem('eim_pay_section_' + this.#planId + '_' + this.#status, 'closed'); } catch (e) {}
        }

        async #refresh() {
            if (!this.#tbody) return;
            this.#spinner?.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_budget_payment_items', {
                    nonce:    config.paymentSearchNonce || '',
                    plan_id:  this.#planId,
                    status:   this.#status,
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
                if (this.#countEl) this.#countEl.textContent = `${data.total} item${data.total === 1 ? '' : 's'}`;
                // Update the due-soon warning badge (needs_payment accordion only).
                if (this.#status === 'needs_payment') {
                    const dueSoonEl = this.#container.querySelector('.eim-payment-due-soon-count');
                    if (dueSoonEl) {
                        const n = Number(data.due_soon_count || 0);
                        dueSoonEl.textContent = `⚠ ${n} due within a month`;
                        dueSoonEl.style.display = n > 0 ? '' : 'none';
                    }
                }
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
                this.#loaded = true;
            } catch (err) {
                console.error('[EIM] Payment section refresh failed:', err);
            } finally {
                this.#spinner?.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            for (const link of this.#body?.querySelectorAll('.eim-pay-sort-link') ?? []) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const ind = link.querySelector('span');
                if (ind) ind.textContent = isCurrent ? (this.#order === 'asc' ? '↑' : '↓') : '';
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
    }

    // =========================================================================
    // Bootstrap
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled)     new BudgetPlansTable();
        if (config.lineItems?.enabled) new LineItemsTable();
        new CategoryTable();
        new LineItemImageModal();
        new LineItemImagePicker();
        new LineItemEditForm();
        new LineItemLibraryPicker();
        new EventPicker('eim-budget-event-picker', { nonce: config.suggestEventsNonce || '' });

        // Payments tab accordion sections (only present on plan detail/edit view).
        if (config.paymentSearchNonce) {
            for (const el of document.querySelectorAll('.eim-payment-section')) {
                new PaymentSection(el);
            }
        }
    });
})();
