/* global ajaxurl, eimBudgetItemsAdmin */

/**
 * Admin Budget Line Items library page interactions.
 *
 * BudgetItemsTable  — AJAX search + sort on the global items list.
 * BudgetItemImagePicker  — WordPress media picker for the add/edit form.
 * BudgetItemImageModal   — full-size lightbox on thumbnail click.
 */
(() => {
    'use strict';

    const config = window.eimBudgetItemsAdmin ?? {};

    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
        }
        return url;
    };

    // =========================================================================
    // BudgetItemsTable — AJAX search + sort for the global items list
    // =========================================================================

    class BudgetItemsTable {
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
        #page  = 1;
        #perPage = 10;

        constructor() {
            this.#table        = document.getElementById('eim-budget-items-table');
            this.#tbody        = document.getElementById('eim-budget-items-table-body');
            this.#search       = document.getElementById('eim-budget-items-search');
            this.#field        = document.getElementById('eim-budget-items-search-field');
            this.#count        = document.getElementById('eim-budget-items-count');
            this.#spinner      = document.getElementById('eim-budget-items-loading');
            this.#perPageSel   = document.getElementById('eim-budget-items-search-per-page');
            this.#paginationNav = document.getElementById('eim-budget-items-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || 'label';
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
                    this.#sort  = link.dataset.sort  || 'label';
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
                const url = ajaxUrl('eim_search_budget_items', {
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
                console.error('[EIM] Budget items search failed:', err);
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
    // BudgetItemImageModal — full-size lightbox on thumbnail click
    // =========================================================================

    class BudgetItemImageModal {
        #overlay = null;
        #image   = null;
        #caption = null;

        constructor() {
            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element)) return;
                const trigger = event.target.closest('.eim-li-image-thumb');
                if (!trigger) return;
                const fullSrc = trigger.dataset.fullSrc || '';
                if (!fullSrc) return;
                event.preventDefault();
                this.#open(fullSrc, trigger.dataset.caption || '');
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
            const modal  = document.createElement('div');
            modal.className = 'eim-li-image-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'button-link eim-li-image-modal-close';
            close.setAttribute('aria-label', 'Close image preview');
            close.textContent = '×';
            this.#image   = document.createElement('img');
            this.#image.alt = '';
            this.#caption = document.createElement('div');
            this.#caption.className = 'eim-li-image-modal-caption';
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
    // BudgetItemImagePicker — WordPress media picker for add/edit form
    // =========================================================================

    class BudgetItemImagePicker {
        #field;
        #preview;
        #select;
        #remove;
        #frame = null;

        constructor() {
            this.#field   = document.getElementById('eim_bi_image_attachment_id');
            this.#preview = document.getElementById('eim_bi_image_preview');
            this.#select  = document.getElementById('eim_bi_image_select');
            this.#remove  = document.getElementById('eim_bi_image_remove');

            if (!this.#field || !this.#preview || !this.#select || !window.wp?.media) return;

            this.#select.addEventListener('click', () => this.#openMediaFrame());
            this.#remove?.addEventListener('click', () => this.#renderSelection(null));
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
                        id:       attachment.id || 0,
                        title:    attachment.title || attachment.filename || '',
                        thumbUrl: attachment.sizes?.thumbnail?.url || attachment.sizes?.medium?.url || attachment.url || '',
                        fullUrl:  attachment.sizes?.full?.url || attachment.url || '',
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
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'button-link eim-li-image-thumb';
                btn.dataset.fullSrc = image.fullUrl || image.thumbUrl;
                btn.dataset.caption = image.title || '';
                btn.setAttribute('aria-label', 'View full-size image');
                const img = document.createElement('img');
                img.src = image.thumbUrl;
                img.alt = '';
                img.loading = 'lazy';
                btn.appendChild(img);
                this.#preview.appendChild(btn);
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
    // Bootstrap
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled) new BudgetItemsTable();
        new BudgetItemImageModal();
        new BudgetItemImagePicker();
    });
})();
