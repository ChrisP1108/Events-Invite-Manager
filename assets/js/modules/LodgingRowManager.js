import { LocationAutocomplete } from './LocationAutocomplete.js';

/**
 * Manages the dynamic lodging location rows on the new-event admin form.
 *
 * Each row contains an autocomplete input backed by the location library.
 * The manager initialises autocomplete on all rows already in the DOM,
 * handles cloning a pristine <template> element when the admin adds a new
 * row, and removes rows via delegated click handling.
 *
 * Field elements within each row are located by CSS class rather than ID
 * so that multiple rows can coexist without ID collisions.
 */
export class LodgingRowManager {
    /**
     * Maps field keys used by LocationAutocomplete to the CSS class selectors
     * for each hidden input within a lodging row.
     *
     * @type {Readonly<Record<string, string>>}
     */
    static #FIELD_CLASS_MAP = Object.freeze({
        street:  '.eim-lodging-init-street',
        city:    '.eim-lodging-init-city',
        state:   '.eim-lodging-init-state',
        zip:     '.eim-lodging-init-zip',
        isOther: '.eim-lodging-init-is-other',
    });

    /** @type {HTMLElement} */
    #container;

    /** @type {HTMLTemplateElement|null} */
    #template;

    /** @type {import('./LocationSearchService.js').LocationSearchService} */
    #searchService;

    /**
     * @param {HTMLElement}                                                       container      Element wrapping all lodging rows (#eim-lodging-init-rows).
     * @param {HTMLTemplateElement|null}                                           template       Pristine row template element (#eim-lodging-init-row-template).
     * @param {import('./LocationSearchService.js').LocationSearchService}         searchService  Shared search service instance.
     */
    constructor(container, template, searchService) {
        this.#container    = container;
        this.#template     = template;
        this.#searchService = searchService;

        for (const row of container.querySelectorAll('.eim-lodging-init-row')) {
            this.#initRow(row);
        }

        this.#bindContainerEvents();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Attaches a LocationAutocomplete instance to the name input within a row.
     *
     * Field elements are resolved by querying within the row element using the
     * class map, so each row is fully self-contained.
     *
     * @param {HTMLElement} rowEl  The .eim-lodging-init-row element to initialise.
     * @returns {void}
     */
    #initRow(rowEl) {
        const nameInput = rowEl.querySelector('.eim-lodging-init-name');
        if (!nameInput) return;

        new LocationAutocomplete(nameInput, {
            resolveField: (key) => {
                const selector = LodgingRowManager.#FIELD_CLASS_MAP[key];
                return selector ? rowEl.querySelector(selector) : null;
            },
            libraryIdEl:   rowEl.querySelector('.eim-lodging-init-library-id'),
            displayEl:     rowEl.querySelector('.eim-lodging-init-display'),
            searchService: this.#searchService,
        });
    }

    /**
     * Binds the add-row button and a delegated remove handler on the container.
     *
     * Optional chaining on the add button means missing the button in the DOM
     * (e.g. on the edit form where rows are managed inline) is silently ignored.
     *
     * @returns {void}
     */
    #bindContainerEvents() {
        document.getElementById('eim-add-lodging-row')
            ?.addEventListener('click', () => this.#addRow());

        this.#container.addEventListener('click', ({ target }) => {
            if (target.matches('.eim-remove-lodging-row')) {
                target.closest('.eim-lodging-init-row')?.remove();
            }
        });
    }

    /**
     * Clones the pristine template, appends the new row to the container, and
     * initialises its autocomplete.
     *
     * Cloning from the template rather than an existing row guarantees that no
     * already-initialised dropdown markup is carried over into the new row.
     *
     * @returns {void}
     */
    #addRow() {
        if (!this.#template) return;
        const newRow = this.#template.content.firstElementChild.cloneNode(true);
        this.#container.appendChild(newRow);
        this.#initRow(newRow);
    }
}
