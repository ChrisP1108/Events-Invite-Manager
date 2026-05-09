/* global eimLocationAC */

import { LocationSearchService } from './LocationSearchService.js';
import { LocationAutocomplete }  from './LocationAutocomplete.js';
import { LodgingRowManager }     from './LodgingRowManager.js';

/**
 * Entry-point bootstrapper for the location autocomplete system.
 *
 * Reads the localised eimLocationAC configuration object provided by
 * wp_localize_script, constructs a shared LocationSearchService, and
 * delegates initialisation to the appropriate handler for each input type:
 *
 *   - Fixed-ID inputs (venue name, inline lodging-add) are configured via
 *     the eimLocationAC.inputs array and initialised directly.
 *   - Dynamic lodging-init rows (new-event form) are managed by a
 *     LodgingRowManager keyed to #eim-lodging-init-rows.
 *
 * Constructing this class is the only action the entry-point script needs
 * to take — all further setup is handled internally.
 */
export class LocationAutocompleteApp {
    /** @type {LocationSearchService} */
    #searchService;

    constructor() {
        const config = window.eimLocationAC;
        if (!config?.nonce) return;

        this.#searchService = new LocationSearchService(config.nonce);
        this.#initFixedInputs(config.inputs ?? []);
        this.#initLodgingRows();
    }

    // ── Private ──────────────────────────────────────────────────────────────

    /**
     * Initialises a LocationAutocomplete for each fixed-ID input defined in the
     * eimLocationAC.inputs configuration array.
     *
     * Inputs whose DOM element is not found are silently skipped, so the same
     * configuration can be passed for both the add and edit event screens even
     * though some inputs only exist on one of them.
     *
     * @param {Array<{
     *   inputId:      string,
     *   libraryIdId?: string,
     *   displayId?:   string,
     *   fields:       Record<string, string|null>,
     * }>} inputs  Array of input configuration objects from wp_localize_script.
     * @returns {void}
     */
    #initFixedInputs(inputs) {
        for (const cfg of inputs) {
            const nameInput = document.getElementById(cfg.inputId);
            if (!nameInput) continue;

            new LocationAutocomplete(nameInput, {
                resolveField:  (key) => cfg.fields[key] ? document.getElementById(cfg.fields[key]) : null,
                libraryIdEl:   cfg.libraryIdId ? document.getElementById(cfg.libraryIdId) : null,
                displayEl:     cfg.displayId   ? document.getElementById(cfg.displayId)   : null,
                searchService: this.#searchService,
                lodgingOnly:   cfg.lodgingOnly ?? false,
            });
        }
    }

    /**
     * Initialises the LodgingRowManager when the new-event form's row container
     * is present in the DOM.
     *
     * Does nothing on the event edit screen where the container is absent.
     *
     * @returns {void}
     */
    #initLodgingRows() {
        const container = document.getElementById('eim-lodging-init-rows');
        if (!container) return;

        const template = document.getElementById('eim-lodging-init-row-template');
        new LodgingRowManager(container, template, this.#searchService);
    }
}
