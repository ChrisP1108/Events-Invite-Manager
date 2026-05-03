/* global ajaxurl */

/**
 * Queries the WordPress AJAX endpoint for location library entries.
 *
 * Encapsulates the nonce and URL construction so callers only supply a
 * query string. Any network or JSON parse failure returns an empty array
 * rather than propagating an exception.
 */
export class LocationSearchService {
    /** @type {string} */
    #nonce;

    /**
     * @param {string} nonce  WordPress nonce for the eim_search_locations AJAX action.
     */
    constructor(nonce) {
        this.#nonce = nonce;
    }

    /**
     * Searches the location library for entries whose name contains the query.
     *
     * Returns an empty array when the query is fewer than two characters, when
     * the server reports no matches, or when a network error occurs.
     *
     * @param {string} query  Partial location name to search for.
     * @returns {Promise<Array<object>>}  Matching location objects from the server.
     */
    async search(query) {
        if (query.length < 2) return [];

        try {
            // Use window.location.href as the base so the constructor handles both
            // absolute URLs (https://site.local/wp-admin/admin-ajax.php) and the
            // root-relative path (/wp-admin/admin-ajax.php) that WordPress uses in
            // some configurations. Passing only the first argument fails on relative
            // strings because URL requires an absolute base when no second argument
            // is supplied.
            const url = new URL(ajaxurl, window.location.href);
            url.searchParams.set('action', 'eim_search_locations');
            url.searchParams.set('nonce',  this.#nonce);
            url.searchParams.set('query',  query);

            const response          = await fetch(url);
            const { success, data } = await response.json();

            return success && data.length > 0 ? data : [];
        } catch (err) {
            console.error('[EIM] Location search failed:', err);
            return [];
        }
    }
}
