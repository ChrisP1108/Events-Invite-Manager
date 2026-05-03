/**
 * Renders and manages the suggestion list positioned below an autocomplete input.
 *
 * Responsible solely for the dropdown UI: building the element, rendering
 * location items, tracking the active keyboard-highlight index, and hiding.
 * All interaction callbacks (mouseenter, mousedown) are wired internally;
 * the caller supplies only an onSelect handler at render time.
 */
export class DropdownList {
    /** @type {HTMLUListElement} */
    #el;

    /** @type {number} */
    #activeIndex = -1;

    constructor() {
        this.#el = this.#createElement();
    }

    /**
     * Returns the root <ul> element to be inserted into the DOM.
     *
     * @returns {HTMLUListElement}
     */
    get element() { return this.#el; }

    /**
     * Returns the index of the currently highlighted item, or -1 when none.
     *
     * @returns {number}
     */
    get activeIndex() { return this.#activeIndex; }

    /**
     * Returns true when the dropdown is currently visible.
     *
     * @returns {boolean}
     */
    get isVisible() { return this.#el.style.display !== 'none'; }

    /**
     * Populates the list with location items and makes it visible.
     *
     * Any existing items are replaced. Each item fires onSelect with the
     * corresponding location object when clicked.
     *
     * @param {Array<object>}        locations  Location objects returned by the search service.
     * @param {(loc: object) => void} onSelect   Called with the chosen location on mousedown.
     * @returns {void}
     */
    render(locations, onSelect) {
        this.#el.replaceChildren();
        this.#activeIndex = -1;

        for (const [i, loc] of locations.entries()) {
            const li = this.#createItem(loc);
            li.addEventListener('mouseenter', () => this.setActive(i));
            li.addEventListener('mousedown', (e) => {
                e.preventDefault(); // keep focus on the text input
                onSelect(loc);
            });
            this.#el.appendChild(li);
        }

        this.#el.style.display = 'block';
    }

    /**
     * Highlights the item at the given index and removes highlight from all others.
     *
     * Passing an out-of-range index silently clears all highlights.
     *
     * @param {number} index  Zero-based index of the item to highlight.
     * @returns {void}
     */
    setActive(index) {
        for (const child of this.#el.children) {
            child.style.background = '';
            child.style.color      = '';
        }

        this.#activeIndex = index;

        if (index >= 0 && index < this.#el.children.length) {
            this.#el.children[index].style.background = '#2271b1';
            this.#el.children[index].style.color      = '#fff';
        }
    }

    /**
     * Hides the dropdown and resets the active index.
     *
     * @returns {void}
     */
    hide() {
        this.#el.style.display = 'none';
        this.#activeIndex      = -1;
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Builds and returns the styled <ul> element.
     *
     * @returns {HTMLUListElement}
     */
    #createElement() {
        const ul = document.createElement('ul');
        ul.setAttribute('role',       'listbox');
        ul.setAttribute('aria-label', 'Location suggestions');
        ul.style.cssText = [
            'position:absolute',
            'top:100%',
            'left:0',
            'z-index:99999',
            'background:#fff',
            'border:1px solid #8c8f94',
            'border-top:none',
            'border-radius:0 0 3px 3px',
            'margin:0',
            'padding:0',
            'list-style:none',
            'min-width:100%',
            'max-width:520px',
            'max-height:260px',
            'overflow-y:auto',
            'display:none',
            'box-shadow:0 3px 8px rgba(0,0,0,.15)',
        ].join(';');
        return ul;
    }

    /**
     * Builds and returns a single <li> element for the given location.
     *
     * Shows the name in bold followed by an "(Other)" badge for generic entries,
     * or the formatted address for specific locations.
     *
     * @param {object} loc  Location object from the search service.
     * @returns {HTMLLIElement}
     */
    #createItem(loc) {
        const li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.style.cssText = [
            'padding:8px 12px',
            'cursor:pointer',
            'font-size:13px',
            'line-height:1.5',
            'border-bottom:1px solid #f0f0f1',
            'white-space:nowrap',
            'overflow:hidden',
            'text-overflow:ellipsis',
        ].join(';');

        const strong = document.createElement('strong');
        strong.textContent = loc.name;
        li.appendChild(strong);

        if (loc.is_other) {
            const badge = document.createElement('span');
            badge.textContent   = ' (Other)';
            badge.style.cssText = 'color:#666;font-size:11px;font-weight:normal;';
            li.appendChild(badge);
        } else if (loc.street_address || loc.city) {
            const parts = [loc.street_address, loc.city, loc.state, loc.zip_code]
                .filter(Boolean).join(', ');
            li.appendChild(document.createTextNode(` — ${parts}`));
        }

        return li;
    }
}
