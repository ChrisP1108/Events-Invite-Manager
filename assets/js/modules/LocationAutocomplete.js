import { DropdownList } from './DropdownList.js';

/**
 * Wires one text input to the search service and manages the full autocomplete
 * lifecycle for a single location field.
 *
 * Responsibilities:
 *   - Debounced AJAX search on input
 *   - Keyboard navigation (ArrowUp / ArrowDown / Enter / Escape)
 *   - Populating sibling address fields and an optional display element on selection
 *   - Tracking the selected library ID in a paired hidden input
 *   - Native form validation via setCustomValidity to prevent free-text saves
 */
export class LocationAutocomplete {
    /** @type {HTMLInputElement} */
    #nameInput;

    /** @type {(key: string) => HTMLElement|null} */
    #resolveField;

    /** @type {HTMLInputElement|null} */
    #libraryIdEl;

    /** @type {HTMLElement|null} */
    #displayEl;

    /** @type {import('./LocationSearchService.js').LocationSearchService} */
    #searchService;

    /** @type {DropdownList} */
    #dropdown;

    /** @type {Array<object>} */
    #currentResults = [];

    /** @type {ReturnType<typeof setTimeout>|null} */
    #debounceTimer = null;

    /**
     * @param {HTMLInputElement} nameInput  The text input the admin types into.
     * @param {{
     *   resolveField:   (key: string) => HTMLElement|null,
     *   searchService:  import('./LocationSearchService.js').LocationSearchService,
     *   libraryIdEl?:   HTMLInputElement|null,
     *   displayEl?:     HTMLElement|null,
     * }} options
     *
     * resolveField must return the associated element for the keys 'street',
     * 'city', 'state', 'zip', and 'isOther'; returning null for absent fields
     * is safe — those keys are silently skipped during population.
     *
     * libraryIdEl is a hidden <input> that stores the selected library ID so the
     * server can validate the selection was not free-typed.
     *
     * displayEl is an optional element whose textContent is updated with the
     * formatted address after a location is selected (e.g. the read-only address
     * line shown beneath the venue name field).
     */
    constructor(nameInput, { resolveField, searchService, libraryIdEl = null, displayEl = null }) {
        this.#nameInput     = nameInput;
        this.#resolveField  = resolveField;
        this.#libraryIdEl   = libraryIdEl;
        this.#displayEl     = displayEl;
        this.#searchService = searchService;

        this.#init();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Wraps the input, attaches the dropdown, binds events, and validates the
     * initial field state.
     *
     * @returns {void}
     */
    #init() {
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:inline-block;';
        this.#nameInput.parentNode.insertBefore(wrap, this.#nameInput);
        wrap.appendChild(this.#nameInput);

        this.#dropdown = new DropdownList();
        wrap.appendChild(this.#dropdown.element);

        this.#bindEvents();
        this.#validateInitialState();
    }

    /**
     * Attaches the input, keydown, and blur event listeners.
     *
     * @returns {void}
     */
    #bindEvents() {
        this.#nameInput.addEventListener('input',   () => this.#onInput());
        this.#nameInput.addEventListener('keydown', (e) => this.#onKeydown(e));
        this.#nameInput.addEventListener('blur',    () => setTimeout(() => this.#dropdown.hide(), 160));
    }

    /**
     * Handles the input event: invalidates any prior selection, clears the
     * address display, and schedules a debounced search.
     *
     * @returns {void}
     */
    #onInput() {
        if (this.#libraryIdEl) this.#libraryIdEl.value = '';

        if (this.#displayEl) {
            this.#displayEl.textContent  = '';
            this.#displayEl.style.display = 'none';
        }

        this.#nameInput.setCustomValidity(
            this.#nameInput.value.trim()
                ? 'Please select a location from the library.'
                : ''
        );

        clearTimeout(this.#debounceTimer);
        this.#debounceTimer = setTimeout(() => this.#doSearch(this.#nameInput.value.trim()), 300);
    }

    /**
     * Executes the search and renders results, or hides the dropdown when empty.
     *
     * @param {string} query  Trimmed value from the name input.
     * @returns {Promise<void>}
     */
    async #doSearch(query) {
        const results        = await this.#searchService.search(query);
        this.#currentResults = results;

        if (results.length > 0) {
            this.#dropdown.render(results, (loc) => this.#populate(loc));
        } else {
            this.#dropdown.hide();
        }
    }

    /**
     * Fills all associated fields from the selected location, clears the
     * custom validity error, and hides the dropdown.
     *
     * @param {object} loc  Location object from the search service.
     * @returns {void}
     */
    #populate(loc) {
        this.#nameInput.value = loc.name;
        this.#nameInput.setCustomValidity('');

        if (this.#libraryIdEl) this.#libraryIdEl.value = loc.id;

        this.#setField('street', loc.street_address);
        this.#setField('city',   loc.city);
        this.#setField('state',  loc.state);
        this.#setField('zip',    loc.zip_code);

        if (this.#displayEl) {
            const parts = [loc.street_address, loc.city, loc.state, loc.zip_code]
                .filter(Boolean).join(', ');
            this.#displayEl.textContent   = loc.is_other ? '(Other — no fixed address)' : parts;
            this.#displayEl.style.display = parts || loc.is_other ? '' : 'none';
        }

        const isOtherEl = this.#resolveField('isOther');
        if (isOtherEl) {
            if (isOtherEl.type === 'checkbox') {
                isOtherEl.checked = Boolean(loc.is_other);
                isOtherEl.dispatchEvent(new Event('change'));
            } else {
                isOtherEl.value = loc.is_other ? '1' : '';
            }
        }

        this.#dropdown.hide();
        this.#nameInput.focus();
    }

    /**
     * Sets the value of the element resolved for the given key, if one exists.
     *
     * @param {string} key    Field key passed to resolveField ('street', 'city', etc.).
     * @param {string} value  Value to assign; falls back to empty string for null/undefined.
     * @returns {void}
     */
    #setField(key, value) {
        const el = this.#resolveField(key);
        if (el) el.value = value ?? '';
    }

    /**
     * Handles keyboard navigation within the open dropdown.
     *
     * ArrowDown / ArrowUp move the highlight; Enter selects the active item;
     * Escape closes the dropdown without selection.
     *
     * @param {KeyboardEvent} e
     * @returns {void}
     */
    #onKeydown(e) {
        if (!this.#dropdown.isVisible || this.#currentResults.length === 0) return;

        const lastIndex = this.#currentResults.length - 1;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.#dropdown.setActive(Math.min(this.#dropdown.activeIndex + 1, lastIndex));
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.#dropdown.setActive(Math.max(this.#dropdown.activeIndex - 1, 0));
                break;
            case 'Enter':
                if (this.#dropdown.activeIndex >= 0) {
                    e.preventDefault();
                    this.#populate(this.#currentResults[this.#dropdown.activeIndex]);
                }
                break;
            case 'Escape':
                this.#dropdown.hide();
                break;
        }
    }

    /**
     * Sets a custom validity error on page load when the name field is pre-filled
     * but no library ID is present, forcing the admin to re-select from the library.
     *
     * This covers the case where an existing venue was saved before library
     * enforcement was introduced and its name no longer matches any library entry.
     *
     * @returns {void}
     */
    #validateInitialState() {
        if (this.#libraryIdEl && this.#nameInput.value.trim() && !this.#libraryIdEl.value) {
            this.#nameInput.setCustomValidity('Please select a location from the library.');
        }
    }
}
