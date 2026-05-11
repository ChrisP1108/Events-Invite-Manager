/* global ajaxurl, eimInviteesAdmin */

/**
 * Admin invitee interactions.
 *
 * Handles:
 *  - Global Invitees page table search/sort (InviteeTable)
 *  - Connection Groups page table search (ConnectionGroupTable)
 *  - Event edit screen invitee picker + connection-group checkboxes (EventInviteePicker)
 *  - Connection Groups edit page member picker (ConnectionGroupMemberPicker)
 */
(() => {
    'use strict';

    const config = window.eimInviteesAdmin ?? {};

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [k, v] of Object.entries(params)) {
            url.searchParams.set(k, String(v));
        }
        return url;
    };

    const debounce = (fn, delay = 250) => {
        let timer = 0;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => fn(...args), delay);
        };
    };

    const escHtml = (str) => {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    };

    // -----------------------------------------------------------------------
    // InviteeTable — global Invitees list search/sort
    // -----------------------------------------------------------------------
    class InviteeTable {
        #table; #tbody; #search; #count; #spinner; #sort; #order;

        constructor() {
            this.#table   = document.getElementById('eim-invitees-table');
            this.#tbody   = document.getElementById('eim-invitees-table-body');
            this.#search  = document.getElementById('eim-invitee-search');
            this.#count   = document.getElementById('eim-invitee-count');
            this.#spinner = document.getElementById('eim-invitee-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort  = this.#table.dataset.sort  || config.table?.sort  || 'last_name';
            this.#order = this.#table.dataset.order || config.table?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'last_name';
                    this.#order = link.dataset.order || 'asc';
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_search_invitees', {
                    nonce: config.searchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
                this.#updateSortLinks();
            } catch (e) {
                console.error('[EIM] Invitee search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent    = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const span = link.querySelector('span');
                if (span) span.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // -----------------------------------------------------------------------
    // ConnectionGroupTable — Connection Groups list live search
    // -----------------------------------------------------------------------
    class ConnectionGroupTable {
        #tbody; #search; #count; #spinner;

        constructor() {
            this.#tbody   = document.getElementById('eim-connection-groups-table-body');
            this.#search  = document.getElementById('eim-connection-group-search');
            this.#count   = document.getElementById('eim-connection-group-count');
            this.#spinner = document.getElementById('eim-connection-group-loading');

            if (!this.#tbody || !this.#search || !config.connectionGroupSearchNonce) return;

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_search_connection_groups', {
                    nonce: config.connectionGroupSearchNonce,
                    query: this.#search?.value || '',
                }), { credentials: 'same-origin' })).json();

                if (!success) return;

                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
            } catch (e) {
                console.error('[EIM] Connection group search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventInviteePicker — event edit add-invitee flow with group checkboxes
    // -----------------------------------------------------------------------
    class EventInviteePicker {
        #input; #hidden; #selected; #dropdown; #connectWrap; #connectList;

        constructor() {
            this.#input       = document.getElementById('eim_event_invitee_search');
            this.#hidden      = document.getElementById('eim_event_invitee_id');
            this.#selected    = document.getElementById('eim_event_invitee_selected');
            this.#connectWrap = document.getElementById('eim-connected-invitees-wrap');
            this.#connectList = document.getElementById('eim-connected-invitees-list');

            if (!this.#input || !this.#hidden || !config.suggestNonce) return;

            this.#dropdown = this.#makeList();
            this.#input.parentElement?.classList.add('eim-invitee-picker-positioner');
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', debounce(() => this.#search()));
            this.#input.addEventListener('input', () => {
                this.#hidden.value = '';
                if (this.#selected) this.#selected.textContent = '';
                this.#hideConnections();
            });
            this.#input.addEventListener('blur', () => setTimeout(() => this.#dropdown.style.display = 'none', 150));

            this.#input.closest('form')?.addEventListener('submit', (e) => {
                if (!this.#hidden?.value) {
                    e.preventDefault();
                    alert('Please select an invitee from the search results before adding them to this event.');
                }
            });
        }

        async #search() {
            const query   = this.#input?.value.trim() || '';
            const eventId = this.#input?.dataset.eventId || config.event?.id || 0;
            if (query.length < 2) { this.#dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_invitees', {
                    nonce: config.suggestNonce, query, event_id: eventId,
                }), { credentials: 'same-origin' })).json();
                this.#renderDropdown(success ? data : []);
            } catch (e) {
                console.error('[EIM] Invitee suggest failed:', e);
                this.#dropdown.style.display = 'none';
            }
        }

        #renderDropdown(invitees) {
            this.#dropdown.replaceChildren();
            if (!invitees.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No available invitees found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const inv of invitees) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = inv.name || '';
                    li.appendChild(name);
                    if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                    if (inv.phone) li.appendChild(document.createTextNode(` - ${inv.phone}`));
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); this.#select(inv); });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        #select(inv) {
            if (this.#hidden)   this.#hidden.value = String(inv.id || '');
            if (this.#input)    this.#input.value  = inv.name || inv.label || '';
            if (this.#selected) this.#selected.textContent = inv.label ? `Selected: ${inv.label}` : '';
            this.#dropdown.style.display = 'none';
            this.#fetchConnections(inv.id);
        }

        async #fetchConnections(inviteeId) {
            if (!inviteeId || !this.#connectWrap || !this.#connectList) return;
            const eventId = this.#input?.dataset.eventId || config.event?.id || 0;

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_get_connections_for_event', {
                    nonce: config.suggestNonce, invitee_id: inviteeId, event_id: eventId,
                }), { credentials: 'same-origin' })).json();

                if (!success || !data?.length) { this.#hideConnections(); return; }
                this.#renderConnections(data);
            } catch (e) {
                console.error('[EIM] Connections fetch failed:', e);
                this.#hideConnections();
            }
        }

        #renderConnections(connections) {
            this.#connectList.replaceChildren();
            for (const conn of connections) {
                const row = document.createElement('div');
                row.className = 'eim-connected-invitee-row';
                const groupLabel = conn.group_name
                    ? `<span style="color:#646970;font-size:11px;"> (${escHtml(conn.group_name)})</span>`
                    : '';
                if (conn.already_invited) {
                    row.innerHTML = `<input type="checkbox" disabled>
                        <label>${escHtml(conn.name)}${groupLabel}
                            <span class="eim-already-invited">(already invited)</span>
                        </label>`;
                } else {
                    const id = `eim-conn-${conn.id}`;
                    row.innerHTML = `<input type="checkbox" id="${id}"
                        name="connected_invitee_ids[]" value="${Number(conn.id)}" checked>
                        <label for="${id}">${escHtml(conn.name)}${groupLabel}</label>`;
                }
                this.#connectList.appendChild(row);
            }
            this.#connectWrap.style.display = 'block';
        }

        #hideConnections() {
            if (this.#connectWrap) this.#connectWrap.style.display = 'none';
            if (this.#connectList) this.#connectList.replaceChildren();
        }

        #makeList() {
            const ul = document.createElement('ul');
            ul.className = 'eim-invitee-suggestions';
            ul.setAttribute('role', 'listbox');
            ul.style.display = 'none';
            return ul;
        }
    }

    // -----------------------------------------------------------------------
    // ConnectionGroupMemberPicker — member autocomplete on group edit page
    // -----------------------------------------------------------------------
    class ConnectionGroupMemberPicker {
        #input; #hidden; #selected; #dropdown; #groupId; #existingIds;

        constructor() {
            this.#input    = document.getElementById('eim_cg_member_search');
            this.#hidden   = document.getElementById('eim_cg_member_invitee_id');
            this.#selected = document.getElementById('eim_cg_member_selected');

            if (!this.#input || !this.#hidden || !config.suggestNonce) return;

            this.#groupId     = Number(this.#input.dataset.groupId || 0);
            this.#existingIds = (this.#input.dataset.existingIds || '')
                .split(',').map(Number).filter(Boolean);

            this.#dropdown = document.createElement('ul');
            this.#dropdown.className = 'eim-invitee-suggestions';
            this.#dropdown.setAttribute('role', 'listbox');
            this.#dropdown.style.display = 'none';
            this.#input.parentElement?.classList.add('eim-invitee-picker-positioner');
            this.#input.parentElement?.appendChild(this.#dropdown);

            this.#input.addEventListener('input', debounce(() => this.#search()));
            this.#input.addEventListener('blur', () => setTimeout(() => this.#dropdown.style.display = 'none', 150));

            this.#input.closest('form')?.addEventListener('submit', (e) => {
                if (!this.#hidden?.value) {
                    e.preventDefault();
                    alert('Please select an invitee from the search results.');
                }
            });
        }

        async #search() {
            const query = this.#input?.value.trim() || '';
            if (query.length < 2) { this.#dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_cg_members', {
                    nonce:       config.suggestNonce,
                    query,
                    group_id:    this.#groupId,
                    exclude_ids: this.#existingIds.join(','),
                }), { credentials: 'same-origin' })).json();

                this.#render(success ? data : []);
            } catch (e) {
                console.error('[EIM] Member suggest failed:', e);
                this.#dropdown.style.display = 'none';
            }
        }

        #render(invitees) {
            this.#dropdown.replaceChildren();
            if (!invitees.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching invitees found.';
                this.#dropdown.appendChild(li);
            } else {
                for (const inv of invitees) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = inv.name || '';
                    li.appendChild(name);
                    if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                    li.addEventListener('mousedown', (e) => { e.preventDefault(); this.#select(inv); });
                    this.#dropdown.appendChild(li);
                }
            }
            this.#dropdown.style.display = 'block';
        }

        #select(inv) {
            if (this.#hidden)   this.#hidden.value = String(inv.id || '');
            if (this.#input)    this.#input.value  = inv.name || inv.label || '';
            if (this.#selected) this.#selected.textContent = inv.label ? `Selected: ${inv.label}` : '';
            this.#dropdown.style.display = 'none';
        }
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled)            new InviteeTable();
        if (config.connectionGroupTable?.enabled) new ConnectionGroupTable();
        if (config.event?.enabled)            new EventInviteePicker();
        if (config.connectionGroup?.enabled)  new ConnectionGroupMemberPicker();
    });
})();
