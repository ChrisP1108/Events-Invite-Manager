/* global ajaxurl, eimCategoriesAdmin */

/**
 * Admin categories module.
 *
 * Exports two reusable classes via window globals:
 *  - window.EimCategoryPicker — multi-select typeahead widget for entity forms
 *  - CategoriesTable          — AJAX search/sort for the Categories list page
 *
 * Also auto-initialises any .eim-category-picker containers found on DOMContentLoaded.
 */
(() => {
    'use strict';

    const config = window.eimCategoriesAdmin ?? {};

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
        return url;
    };

    const debounce = (fn, delay = 250) => {
        let t = 0;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
    };

    const escHtml = (str) => {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    };

    // =========================================================================
    // CategoryPicker — multi-select typeahead widget
    //
    // Attach to any element with class "eim-category-picker" that has:
    //   data-nonce       — eim_suggest_categories_nonce value
    //   data-input-name  — form field name for the hidden inputs (default "category_ids[]")
    //   data-selected    — JSON array of {id, name, parent_name} pre-selected categories
    // =========================================================================

    class CategoryPicker {
        #container;
        #nonce;
        #inputName;
        #selected = new Map(); // id → {id, name, parent_name, label}

        #input;
        #dropdown;
        #chips;
        #hiddenWrap;

        constructor(container) {
            this.#container = container;
            this.#nonce     = container.dataset.nonce     ?? '';
            this.#inputName = container.dataset.inputName ?? 'category_ids[]';

            const preselected = JSON.parse(container.dataset.selected ?? '[]');
            for (const item of preselected) {
                this.#selected.set(item.id, item);
            }

            this.#build();
            this.#renderChips();
        }

        #build() {
            this.#container.innerHTML = '';

            // Search input + dropdown wrapper
            const positioner = document.createElement('div');
            positioner.style.cssText = 'position:relative;display:inline-block;max-width:380px;width:100%;';

            this.#input = document.createElement('input');
            this.#input.type        = 'text';
            this.#input.className   = 'regular-text';
            this.#input.placeholder = 'Search categories to add…';
            this.#input.autocomplete = 'off';
            this.#input.style.width = '100%';

            this.#dropdown = document.createElement('ul');
            this.#dropdown.style.cssText =
                'position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #dcdcde;'
                + 'border-radius:0 0 4px 4px;margin:0;padding:0;list-style:none;z-index:1000;display:none;'
                + 'max-height:240px;overflow-y:auto;box-shadow:0 4px 8px rgba(0,0,0,.1);';

            positioner.appendChild(this.#input);
            positioner.appendChild(this.#dropdown);

            // Chips area
            this.#chips = document.createElement('div');
            this.#chips.className = 'eim-category-chips';
            this.#chips.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;';

            // Hidden inputs
            this.#hiddenWrap = document.createElement('div');

            this.#container.appendChild(positioner);
            this.#container.appendChild(this.#chips);
            this.#container.appendChild(this.#hiddenWrap);

            // Events
            this.#input.addEventListener('input', debounce(() => this.#onInput(), 250));
            this.#input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this.#closeDropdown();
            });
            document.addEventListener('click', (e) => {
                if (!this.#container.contains(e.target)) this.#closeDropdown();
            });
        }

        async #onInput() {
            const q = this.#input.value.trim();
            if (q.length < 1) { this.#closeDropdown(); return; }

            const url = ajaxUrl('eim_suggest_categories', { nonce: this.#nonce, query: q });
            try {
                const res  = await fetch(url);
                const json = await res.json();
                if (json.success) this.#renderDropdown(json.data);
            } catch { /* silent */ }
        }

        #renderDropdown(items) {
            this.#dropdown.innerHTML = '';
            const available = items.filter(i => !this.#selected.has(i.id));

            if (available.length === 0) {
                this.#dropdown.style.display = 'none';
                return;
            }

            for (const item of available) {
                const li = document.createElement('li');
                li.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f1;';
                li.textContent   = item.label;
                li.dataset.id    = item.id;
                li.addEventListener('click', () => this.#addItem(item));
                li.addEventListener('mouseenter', () => { li.style.background = '#f0f6fc'; });
                li.addEventListener('mouseleave', () => { li.style.background = ''; });
                this.#dropdown.appendChild(li);
            }

            this.#dropdown.style.display = 'block';
        }

        #addItem(item) {
            this.#selected.set(item.id, item);
            this.#renderChips();
            this.#closeDropdown();
            this.#input.value = '';
            this.#input.focus();
        }

        #removeItem(id) {
            this.#selected.delete(id);
            this.#renderChips();
        }

        #renderChips() {
            this.#chips.innerHTML      = '';
            this.#hiddenWrap.innerHTML = '';

            for (const [id, item] of this.#selected) {
                // Chip
                const chip = document.createElement('span');
                chip.style.cssText =
                    'display:inline-flex;align-items:center;gap:4px;background:#f0f6fc;'
                    + 'border:1px solid #a8c4e0;border-radius:3px;padding:2px 8px;font-size:12px;';

                const labelText = item.label ?? item.name;
                const editBase  = config.categoryEditBaseUrl ?? '';
                if (editBase) {
                    const link = document.createElement('a');
                    link.textContent = labelText;
                    link.href        = editBase + '&action=edit&id=' + id;
                    link.style.cssText = 'color:inherit;text-decoration:none;';
                    link.addEventListener('mouseenter', () => { link.style.textDecoration = 'underline'; });
                    link.addEventListener('mouseleave', () => { link.style.textDecoration = 'none'; });
                    chip.appendChild(link);
                } else {
                    chip.appendChild(document.createTextNode(labelText));
                }

                const btn = document.createElement('button');
                btn.type      = 'button';
                btn.textContent = '×';
                btn.title     = 'Remove';
                btn.style.cssText =
                    'background:none;border:none;cursor:pointer;color:#d63638;font-size:14px;'
                    + 'line-height:1;padding:0 0 0 2px;';
                btn.addEventListener('click', () => this.#removeItem(id));

                chip.appendChild(btn);
                this.#chips.appendChild(chip);

                // Hidden input
                const hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = this.#inputName;
                hidden.value = String(id);
                this.#hiddenWrap.appendChild(hidden);
            }
        }

        #closeDropdown() {
            this.#dropdown.style.display = 'none';
        }
    }

    // Expose globally so other scripts can instantiate it.
    window.EimCategoryPicker = CategoryPicker;

    // =========================================================================
    // CategoriesTable — AJAX search/sort for the categories list page
    // =========================================================================

    class CategoriesTable {
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
        #nonce;
        #page = 1;
        #perPage = 10;

        constructor() {
            this.#table         = document.getElementById('eim-categories-table');
            this.#tbody         = document.getElementById('eim-categories-table-body');
            this.#search        = document.getElementById('eim-category-search');
            this.#field         = document.getElementById('eim-category-search-field');
            this.#count         = document.getElementById('eim-category-count');
            this.#spinner       = document.getElementById('eim-category-loading');
            this.#perPageSel    = document.getElementById('eim-category-search-per-page');
            this.#paginationNav = document.getElementById('eim-category-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search) return;

            this.#sort  = this.#table.dataset.sort  ?? 'name';
            this.#order = this.#table.dataset.order ?? 'asc';
            this.#nonce = config.searchNonce ?? '';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#fetch();
            });
            this.#search.addEventListener('input', debounce(() => {
                this.#page = 1;
                this.#fetch();
            }, 250));
            this.#field?.addEventListener('change', () => {
                this.#page = 1;
                this.#fetch();
            });

            this.#table.querySelectorAll('.eim-sort-link').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort;
                    this.#order = link.dataset.order;
                    this.#page  = 1;
                    this.#fetch();
                });
            });

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #fetch() {
            if (this.#spinner) this.#spinner.classList.add('is-active');

            const url = ajaxUrl('eim_search_categories', {
                nonce:    this.#nonce,
                query:    this.#search.value,
                field:    this.#field?.value || '',
                sort:     this.#sort,
                order:    this.#order,
                page:     this.#page,
                per_page: this.#perPage,
            });

            try {
                const res  = await fetch(url);
                const json = await res.json();
                if (json.success) {
                    this.#tbody.innerHTML = json.data.html;
                    if (this.#count) this.#count.textContent = json.data.count + ' result' + (json.data.count === 1 ? '' : 's');
                    this.#table.dataset.total = json.data.total;
                    this.#renderPagination(Number(json.data.total || 0));
                    this.#updateSortIndicators();
                }
            } catch { /* silent */ } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortIndicators() {
            this.#table.querySelectorAll('.eim-sort-link').forEach(link => {
                const indicator = link.querySelector('span[aria-hidden]');
                if (!indicator) return;
                indicator.textContent = link.dataset.sort === this.#sort
                    ? (this.#order === 'asc' ? '^' : 'v')
                    : '';
            });
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (page) => {
                    this.#page = page;
                    this.#fetch();
                },
            });
        }
    }

    // =========================================================================
    // Init
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        // Auto-init category pickers.
        document.querySelectorAll('.eim-category-picker').forEach(el => {
            new CategoryPicker(el);
        });

        // Init the categories list table if present.
        if (config.table?.enabled) {
            new CategoriesTable();
        }
    });
})();
