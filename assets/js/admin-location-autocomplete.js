/**
 * Entry point for the location autocomplete system.
 *
 * The module path is constructed from the baseUrl value injected by
 * wp_localize_script rather than from a relative path. Relative paths in
 * dynamic import() resolve against the document URL when the script runs
 * without type="module", which points at the WordPress admin directory rather
 * than the plugin's assets directory, producing a 404. Using an absolute URL
 * bypasses that resolution ambiguity and works correctly in both module and
 * non-module script contexts.
 */
(async () => {
    'use strict';

    const baseUrl = window.eimLocationAC?.baseUrl;
    if (!baseUrl) return;

    const { LocationAutocompleteApp } = await import(
        `${baseUrl}/modules/LocationAutocompleteApp.js`
    );

    new LocationAutocompleteApp();
})();
