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
        #table; #tbody; #search; #field; #count; #spinner; #sort; #order;

        constructor() {
            this.#table   = document.getElementById('eim-invitees-table');
            this.#tbody   = document.getElementById('eim-invitees-table-body');
            this.#search  = document.getElementById('eim-invitee-search');
            this.#field   = document.getElementById('eim-invitee-search-field');
            this.#count   = document.getElementById('eim-invitee-count');
            this.#spinner = document.getElementById('eim-invitee-loading');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort  = this.#table.dataset.sort  || config.table?.sort  || 'last_name';
            this.#order = this.#table.dataset.order || config.table?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

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
                    field: this.#field?.value || '',
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
        #table; #tbody; #search; #field; #count; #spinner; #sort; #order;

        constructor() {
            this.#table   = document.getElementById('eim-connection-groups-table');
            this.#tbody   = document.getElementById('eim-connection-groups-table-body');
            this.#search  = document.getElementById('eim-connection-group-search');
            this.#field   = document.getElementById('eim-connection-group-search-field');
            this.#count   = document.getElementById('eim-connection-group-count');
            this.#spinner = document.getElementById('eim-connection-group-loading');

            if (!this.#tbody || !this.#search || !config.connectionGroupSearchNonce) return;

            this.#sort  = this.#table?.dataset.sort  || config.connectionGroupTable?.sort  || 'name';
            this.#order = this.#table?.dataset.order || config.connectionGroupTable?.order || 'asc';

            this.#search.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of (this.#table?.querySelectorAll('.eim-sort-link') ?? [])) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');

            try {
                const url = ajaxUrl('eim_search_connection_groups', {
                    nonce: config.connectionGroupSearchNonce,
                    query: this.#search?.value || '',
                    sort:  this.#sort,
                    order: this.#order,
                    field: this.#field?.value || '',
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();

                if (!success) return;

                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
            } catch (e) {
                console.error('[EIM] Connection group search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
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
    // EventGroupManager — member dropdown + add-member per group row
    // Uses full event delegation so it works after AJAX tbody replacement.
    // -----------------------------------------------------------------------
    class EventGroupManager {
        #debounceMap = new WeakMap();

        constructor() {
            if (!document.getElementById('eim-event-groups-table')) return;

            document.addEventListener('click',    (e) => this.#handleClick(e));
            document.addEventListener('focusout', (e) => this.#handleFocusOut(e));
            document.addEventListener('input',    (e) => this.#handleInput(e));
            document.addEventListener('submit',   (e) => this.#handleSubmit(e));
        }

        #handleClick(e) {
            const trigger = e.target.closest('.eim-member-dropdown-trigger');
            if (trigger) { this.#toggleDropdown(e, trigger); return; }

            const addToggle = e.target.closest('.eim-add-member-toggle');
            if (addToggle) { this.#showRow(`eim-add-member-row-${addToggle.dataset.groupId}`, `eim-add-member-search-${addToggle.dataset.groupId}`); return; }

            const addCancel = e.target.closest('.eim-add-member-cancel');
            if (addCancel) { this.#hideAddMember(addCancel.dataset.groupId); return; }

            const connToggle = e.target.closest('.eim-add-connection-toggle');
            if (connToggle) { this.#showRow(`eim-add-connection-row-${connToggle.dataset.groupId}`, null, `.eim-connection-select`); return; }

            const connCancel = e.target.closest('.eim-add-connection-cancel');
            if (connCancel) { this.#hideAddConnection(connCancel.dataset.groupId); return; }

            this.#closeAll();
        }

        #handleFocusOut(e) {
            const input = e.target.closest('.eim-group-member-search');
            if (!input) return;
            setTimeout(() => {
                const drop = input.parentElement?.querySelector('.eim-invitee-suggestions');
                if (drop) drop.style.display = 'none';
            }, 150);
        }

        #handleInput(e) {
            const input = e.target.closest('.eim-group-member-search');
            if (input) this.#debouncedSearch(input);
        }

        #handleSubmit(e) {
            const form = e.target.closest('.eim-add-member-form');
            if (!form) return;
            const hidden = form.querySelector('.eim-add-member-invitee-id');
            const select = form.querySelector('.eim-connection-select');
            if (hidden && !hidden.value) {
                e.preventDefault();
                alert('Please select an invitee from the search results.');
            } else if (select && !select.value) {
                e.preventDefault();
                alert('Please select a connection from the list.');
            }
        }

        #toggleDropdown(e, btn) {
            e.stopPropagation();
            const menu   = btn.nextElementSibling;
            if (!menu) return;
            const isOpen = !menu.hidden;
            this.#closeAll();
            if (!isOpen) {
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
            }
        }

        #closeAll() {
            document.querySelectorAll('.eim-member-dropdown-menu').forEach(m => {
                m.hidden = true;
                m.previousElementSibling?.setAttribute('aria-expanded', 'false');
            });
        }

        #showRow(rowId, focusId, focusSelector) {
            const row = document.getElementById(rowId);
            if (!row) return;
            row.style.display = '';
            if (focusId) document.getElementById(focusId)?.focus();
            else if (focusSelector) row.querySelector(focusSelector)?.focus();
        }

        #hideAddMember(groupId) {
            const row = document.getElementById(`eim-add-member-row-${groupId}`);
            if (row) row.style.display = 'none';
            const input  = document.getElementById(`eim-add-member-search-${groupId}`);
            const hidden = document.getElementById(`eim-add-member-invitee-id-${groupId}`);
            if (input)  input.value  = '';
            if (hidden) hidden.value = '';
        }

        #hideAddConnection(groupId) {
            const row = document.getElementById(`eim-add-connection-row-${groupId}`);
            if (!row) return;
            row.style.display = 'none';
            const sel = row.querySelector('.eim-connection-select');
            if (sel) sel.value = '';
        }

        #debouncedSearch(input) {
            clearTimeout(this.#debounceMap.get(input) || 0);
            this.#debounceMap.set(input, setTimeout(() => this.#searchMember(input), 250));
        }

        async #searchMember(input) {
            const query   = input.value.trim();
            const eventId = input.dataset.eventId || 0;
            const groupId = input.dataset.groupId || 0;

            let drop = input.parentElement?.querySelector('.eim-invitee-suggestions');
            if (!drop) {
                drop = document.createElement('ul');
                drop.className = 'eim-invitee-suggestions';
                drop.setAttribute('role', 'listbox');
                drop.style.display = 'none';
                input.parentElement?.appendChild(drop);
            }

            if (query.length < 2) { drop.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_invitees', {
                    nonce: config.suggestNonce, query, event_id: eventId,
                }), { credentials: 'same-origin' })).json();

                drop.replaceChildren();
                const items = success ? data : [];

                if (!items.length) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion-empty';
                    li.textContent = 'No available invitees found.';
                    drop.appendChild(li);
                } else {
                    for (const inv of items) {
                        const li = document.createElement('li');
                        li.className = 'eim-invitee-suggestion';
                        li.setAttribute('role', 'option');
                        const name = document.createElement('strong');
                        name.textContent = inv.name || '';
                        li.appendChild(name);
                        if (inv.email) li.appendChild(document.createTextNode(` - ${inv.email}`));
                        li.addEventListener('mousedown', (ev) => {
                            ev.preventDefault();
                            input.value = inv.name || inv.label || '';
                            drop.style.display = 'none';
                            const hidden = document.getElementById(`eim-add-member-invitee-id-${groupId}`);
                            if (hidden) hidden.value = String(inv.id || '');
                        });
                        drop.appendChild(li);
                    }
                }
                drop.style.display = 'block';
            } catch (err) {
                console.error('[EIM] Member search failed:', err);
                drop.style.display = 'none';
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventGroupsTable — AJAX search/filter + column sort for the Invited Invitees table
    // -----------------------------------------------------------------------
    class EventGroupsTable {
        #table; #tbody; #search; #field; #count; #spinner; #sort; #order;

        constructor() {
            this.#table   = document.getElementById('eim-event-groups-table');
            this.#tbody   = document.getElementById('eim-event-groups-table-body');
            this.#search  = document.getElementById('eim-event-groups-search');
            this.#field   = document.getElementById('eim-event-groups-search-field');
            this.#count   = document.getElementById('eim-event-groups-count');
            this.#spinner = document.getElementById('eim-event-groups-loading');

            if (!this.#table || !this.#tbody || !config.event?.groupsSortNonce) return;

            this.#sort  = this.#table.dataset.sort  || 'name';
            this.#order = this.#table.dataset.order || 'asc';

            this.#search?.addEventListener('input', debounce(() => this.#refresh()));
            this.#field?.addEventListener('change', () => this.#refresh());

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.#sort  = link.dataset.sort  || 'name';
                    this.#order = link.dataset.order || 'asc';
                    this.#updateSortLinks();
                    this.#refresh();
                });
            }
        }

        async #refresh() {
            if (this.#spinner) this.#spinner.classList.add('is-active');
            try {
                const url = ajaxUrl('eim_sort_event_groups', {
                    nonce:    config.event.groupsSortNonce,
                    event_id: config.event.id || 0,
                    sort:     this.#sort,
                    order:    this.#order,
                    query:    this.#search?.value || '',
                    field:    this.#field?.value  || '',
                });
                const { success, data } = await (await fetch(url, { credentials: 'same-origin' })).json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                if (this.#count) this.#count.textContent = `${data.count} result${data.count === 1 ? '' : 's'}`;
            } catch (e) {
                console.error('[EIM] Event groups sort/search failed:', e);
            } finally {
                if (this.#spinner) this.#spinner.classList.remove('is-active');
            }
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventMenuItemFilter — client-side search for the event's assigned food/beverage tables
    // -----------------------------------------------------------------------
    class EventMenuItemFilter {
        constructor() {
            this.#initType('food');
            this.#initType('beverage');
        }

        #initType(type) {
            const search  = document.getElementById(`eim-event-${type}-item-search`);
            const field   = document.getElementById(`eim-event-${type}-item-search-field`);
            const count   = document.getElementById(`eim-event-${type}-item-count`);
            const tbody   = document.getElementById(`eim-event-${type}-items-body`);

            if (!search || !tbody) return;

            const run = () => this.#filter(search, field, count, tbody);
            search.addEventListener('input', debounce(run));
            field?.addEventListener('change', run);
        }

        #filter(search, field, count, tbody) {
            const query = search.value.toLowerCase().trim();
            const col   = field?.value || '';

            const dataRows = [...tbody.querySelectorAll('tr[data-label]')];
            let visible    = 0;

            for (const row of dataRows) {
                let matches;
                if (query === '') {
                    matches = true;
                } else if (col === 'label') {
                    matches = row.dataset.label.includes(query);
                } else if (col === 'description') {
                    matches = row.dataset.description.includes(query);
                } else {
                    matches = row.dataset.label.includes(query) || row.dataset.description.includes(query);
                }
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            }

            if (count) count.textContent = `${visible} result${visible === 1 ? '' : 's'}`;

            let emptyRow = tbody.querySelector('.eim-filter-empty');
            if (dataRows.length > 0 && visible === 0 && query !== '') {
                if (!emptyRow) {
                    emptyRow = document.createElement('tr');
                    emptyRow.className = 'eim-filter-empty';
                    const td = document.createElement('td');
                    td.colSpan = tbody.closest('table')?.tHead?.rows[0]?.cells.length || 3;
                    td.textContent = 'No results found based upon search criteria.';
                    emptyRow.appendChild(td);
                    tbody.appendChild(emptyRow);
                }
                emptyRow.style.display = '';
            } else if (emptyRow) {
                emptyRow.style.display = 'none';
            }
        }
    }

    // -----------------------------------------------------------------------
    // EventAssignmentSorter — drag/order + column sort for event lodging/menu tables
    // -----------------------------------------------------------------------
    class EventAssignmentSorter {
        constructor() {
            if (!config.event?.assignmentSortNonce) return;

            for (const table of document.querySelectorAll('.eim-sortable-assignment-list')) {
                this.#initTable(table);
            }
        }

        #initTable(table) {
            const tbody = table.tBodies?.[0];
            if (!tbody) return;

            for (const link of table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    const sort  = link.dataset.sort || 'order';
                    const order = link.dataset.order || 'asc';
                    this.#sortRows(table, sort, order);
                    this.#updateSortLinks(table, sort, order);
                });
            }

            let dragging = null;

            for (const row of tbody.querySelectorAll('.eim-sortable-row')) {
                const handle = row.querySelector('.eim-drag-handle');
                if (!handle) continue;

                row.draggable = false;

                handle.addEventListener('mousedown', () => {
                    row.draggable = true;
                });

                handle.addEventListener('mouseup', () => {
                    if (!row.classList.contains('is-dragging')) row.draggable = false;
                });

                handle.addEventListener('touchstart', () => {
                    row.draggable = true;
                }, { passive: true });

                row.addEventListener('dragstart', (event) => {
                    dragging = row;
                    row.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', row.dataset.id || '');
                });

                row.addEventListener('dragend', () => {
                    row.classList.remove('is-dragging');
                    row.draggable = false;

                    if (!dragging) return;
                    dragging = null;
                    this.#renumberRows(tbody);
                    this.#saveOrder(table);
                });
            }

            tbody.addEventListener('dragover', (event) => {
                if (!dragging) return;

                event.preventDefault();
                const after = this.#dragAfterElement(tbody, event.clientY);

                if (after === null) {
                    tbody.appendChild(dragging);
                } else {
                    tbody.insertBefore(dragging, after);
                }
            });
        }

        #dragAfterElement(tbody, y) {
            const rows = [...tbody.querySelectorAll('.eim-sortable-row:not(.is-dragging)')]
                .filter((row) => row.style.display !== 'none');

            return rows.reduce((closest, row) => {
                const box    = row.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset, row };
                }

                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, row: null }).row;
        }

        #renumberRows(tbody) {
            const rows = [...tbody.querySelectorAll('.eim-sortable-row')];

            rows.forEach((row, index) => {
                const order = String(index + 1);
                row.dataset.order = order;

                const cell = row.querySelector('.eim-order-cell');
                if (cell) cell.textContent = order;
            });
        }

        #sortRows(table, sort, order) {
            const tbody = table.tBodies?.[0];
            if (!tbody) return;

            const multiplier = order === 'desc' ? -1 : 1;
            const rows = [...tbody.querySelectorAll('.eim-sortable-row')];

            rows.sort((a, b) => {
                const aVal = a.dataset[sort] || '';
                const bVal = b.dataset[sort] || '';

                if (sort === 'order') {
                    return multiplier * ((Number(aVal) || 0) - (Number(bVal) || 0));
                }

                return multiplier * aVal.localeCompare(bVal, undefined, { sensitivity: 'base' });
            });

            for (const row of rows) {
                tbody.appendChild(row);
            }
        }

        #updateSortLinks(table, sort, order) {
            table.dataset.sort  = sort;
            table.dataset.order = order;

            for (const link of table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = (link.dataset.sort || '') === sort;
                link.dataset.order = isCurrent && order === 'asc' ? 'desc' : 'asc';

                const indicator = link.querySelector('span[aria-hidden]');
                if (indicator) indicator.textContent = isCurrent ? (order === 'asc' ? '^' : 'v') : '';
            }
        }

        async #saveOrder(table) {
            const rows = [...(table.tBodies?.[0]?.querySelectorAll('.eim-sortable-row') ?? [])];
            const ids  = rows.map((row) => row.dataset.id).filter(Boolean);

            if (ids.length < 2) return;

            const body = new URLSearchParams();
            body.set('nonce', config.event.assignmentSortNonce);
            body.set('event_id', config.event.id || 0);

            if (table.dataset.kind === 'lodging') {
                body.set('action', 'eim_sort_event_lodging');
            } else {
                body.set('action', 'eim_sort_event_menu_items');
                body.set('type', table.dataset.type || 'food');
            }

            ids.forEach((id) => body.append('ids[]', id));

            const status = table.parentElement?.querySelector('.eim-sort-status');
            if (status) status.textContent = 'Saving order...';

            try {
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body,
                });
                const { success } = await response.json();

                if (status) {
                    status.textContent = success ? 'Order saved.' : 'Could not save order.';
                    window.setTimeout(() => { status.textContent = ''; }, 2400);
                }
            } catch (error) {
                console.error('[EIM] Assignment order save failed:', error);
                if (status) status.textContent = 'Could not save order.';
            }
        }
    }

    // -----------------------------------------------------------------------
    // MenuItemPicker — autocomplete for food/beverage pickers on the event edit page
    // -----------------------------------------------------------------------
    class MenuItemPicker {
        #inputs = [];

        constructor() {
            if (!config.suggestMenuItemsNonce) return;

            for (const input of document.querySelectorAll('.eim-menu-item-search')) {
                this.#initInput(input);
            }
        }

        #initInput(input) {
            const type     = input.dataset.type || 'food';
            const hiddenId = `eim_${type}_item_id`;
            const labelId  = `eim_${type}_item_selected`;
            const hidden   = document.getElementById(hiddenId);
            const label    = document.getElementById(labelId);

            const dropdown = document.createElement('ul');
            dropdown.className = 'eim-invitee-suggestions';
            dropdown.setAttribute('role', 'listbox');
            dropdown.style.display = 'none';
            input.parentElement?.classList.add('eim-invitee-picker-positioner');
            input.parentElement?.appendChild(dropdown);

            input.addEventListener('input', debounce(() => this.#search(input, type, dropdown, hidden, label)));
            input.addEventListener('input', () => {
                if (hidden) hidden.value = '';
                if (label)  label.textContent = '';
            });
            input.addEventListener('blur', () => setTimeout(() => { dropdown.style.display = 'none'; }, 150));

            input.closest('form')?.addEventListener('submit', (e) => {
                if (!hidden?.value) {
                    e.preventDefault();
                    alert('Please select an item from the search results before adding it.');
                }
            });

            this.#inputs.push({ input, type, dropdown, hidden, label });
        }

        async #search(input, type, dropdown, hidden, label) {
            const query = input.value.trim();
            if (query.length < 1) { dropdown.style.display = 'none'; return; }

            try {
                const { success, data } = await (await fetch(ajaxUrl('eim_suggest_menu_items', {
                    nonce: config.suggestMenuItemsNonce,
                    type,
                    query,
                }), { credentials: 'same-origin' })).json();

                this.#renderDropdown(success ? data : [], dropdown, input, hidden, label);
            } catch (e) {
                console.error('[EIM] Menu item suggest failed:', e);
                dropdown.style.display = 'none';
            }
        }

        #renderDropdown(items, dropdown, input, hidden, label) {
            dropdown.replaceChildren();
            if (!items.length) {
                const li = document.createElement('li');
                li.className = 'eim-invitee-suggestion-empty';
                li.textContent = 'No matching items found.';
                dropdown.appendChild(li);
            } else {
                for (const item of items) {
                    const li = document.createElement('li');
                    li.className = 'eim-invitee-suggestion';
                    li.setAttribute('role', 'option');
                    const name = document.createElement('strong');
                    name.textContent = item.label || '';
                    li.appendChild(name);
                    if (item.description) li.appendChild(document.createTextNode(` — ${item.description}`));
                    li.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        input.value  = item.label || '';
                        if (hidden) hidden.value = String(item.id || '');
                        if (label)  label.textContent = `Selected: ${item.label}`;
                        dropdown.style.display = 'none';
                    });
                    dropdown.appendChild(li);
                }
            }
            dropdown.style.display = 'block';
        }
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        if (config.table?.enabled)                new InviteeTable();
        if (config.connectionGroupTable?.enabled) new ConnectionGroupTable();
        if (config.event?.enabled)                new EventInviteePicker();
        if (config.connectionGroup?.enabled)      new ConnectionGroupMemberPicker();
        new EventGroupManager();
        if (config.event?.enabled) {
            new EventGroupsTable();
            new MenuItemPicker();
            new EventMenuItemFilter();
            new EventAssignmentSorter();
        }
    });
})();
