/**
 * Entry point for the location autocomplete system.
 *
 * Uses a dynamic import() so this file works correctly whether WordPress
 * serves it as a plain script or as a type="module" script. Static import
 * statements require type="module" on the <script> tag; dynamic import()
 * is valid in any JavaScript context and resolves module paths relative to
 * this file's URL, so the module files in ./modules/ still benefit from full
 * ES module scope (strict mode, private imports, etc.).
 */
(async () => {
    'use strict';

    const { LocationAutocompleteApp } = await import('./modules/LocationAutocompleteApp.js');
    new LocationAutocompleteApp();
})();
