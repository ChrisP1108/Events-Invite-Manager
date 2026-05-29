/* global ajaxurl, eimMessagesAdmin */

/**
 * Global Messages admin sub-page.
 *
 * Drives the live-search/sort/pagination table and handles inline
 * mark-read/unread and delete actions per row.
 *
 * Configuration comes from the eimMessagesAdmin object localised by AdminMenu::enqueueScripts().
 */
(() => {
    'use strict';

    const config = window.eimMessagesAdmin ?? {};

    // ── Utilities ─────────────────────────────────────────────────────────────

    const ajaxUrl = (action, params = {}) => {
        const url = new URL(ajaxurl, window.location.href);
        url.searchParams.set('action', action);
        for (const [key, value] of Object.entries(params)) {
            url.searchParams.set(key, String(value));
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

    // ── GlobalMessagesTable — live-search, sort, pagination ───────────────────

    class GlobalMessagesTable {
        /** @type {HTMLTableElement|null} */
        #table;
        /** @type {HTMLTableSectionElement|null} */
        #tbody;
        /** @type {HTMLInputElement|null} */
        #search;
        /** @type {HTMLSelectElement|null} */
        #field;
        /** @type {HTMLElement|null} */
        #count;
        /** @type {HTMLElement|null} */
        #spinner;
        /** @type {HTMLSelectElement|null} */
        #perPageSel;
        /** @type {HTMLElement|null} */
        #paginationNav;
        /** @type {string} */
        #sort;
        /** @type {string} */
        #order;
        /** @type {number} */
        #page = 1;
        /** @type {number} */
        #perPage = 10;

        constructor() {
            this.#table         = document.getElementById('eim-global-messages-table');
            this.#tbody         = document.getElementById('eim-global-messages-table-body');
            this.#search        = document.getElementById('eim-global-messages-search');
            this.#field         = document.getElementById('eim-global-messages-search-field');
            this.#count         = document.getElementById('eim-global-messages-count');
            this.#spinner       = document.getElementById('eim-global-messages-loading');
            this.#perPageSel    = document.getElementById('eim-global-messages-search-per-page');
            this.#paginationNav = document.getElementById('eim-global-messages-search-pagination');

            if (!this.#table || !this.#tbody || !this.#search || !config.searchNonce) return;

            this.#sort    = this.#table.dataset.sort  || config.table?.sort  || 'created_at';
            this.#order   = this.#table.dataset.order || config.table?.order || 'desc';
            this.#perPage = Number(this.#perPageSel?.value || 10);

            this.#perPageSel?.addEventListener('change', () => {
                this.#perPage = Number(this.#perPageSel.value);
                this.#page = 1;
                this.#refresh();
            });
            this.#search.addEventListener('input', debounce(() => { this.#page = 1; this.#refresh(); }));
            this.#field?.addEventListener('change', () => { this.#page = 1; this.#refresh(); });

            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    this.#sort  = link.dataset.sort  || 'created_at';
                    this.#order = link.dataset.order || 'desc';
                    this.#page  = 1;
                    this.#refresh();
                });
            }

            this.#renderPagination(Number(this.#table.dataset.total || 0));
        }

        async #refresh() {
            this.#setLoading(true);
            try {
                const url = ajaxUrl('eim_search_messages', {
                    nonce:    config.searchNonce,
                    query:    this.#search?.value || '',
                    sort:     this.#sort,
                    order:    this.#order,
                    field:    this.#field?.value || '',
                    page:     this.#page,
                    per_page: this.#perPage,
                });
                const response = await fetch(url, { credentials: 'same-origin' });
                const { success, data } = await response.json();
                if (!success) return;
                this.#tbody.innerHTML = data.html || '';
                this.#updateCount(Number(data.count || 0));
                this.#updateSortLinks();
                this.#renderPagination(Number(data.total || 0));
            } catch (err) {
                console.error('[EIM] Messages search failed:', err);
            } finally {
                this.#setLoading(false);
            }
        }

        #renderPagination(total) {
            window.eimRenderPagination?.(this.#paginationNav, {
                total,
                perPage: this.#perPage,
                page:    this.#page,
                onPageChange: (p) => { this.#page = p; this.#refresh(); },
            });
        }

        #setLoading(isLoading) {
            this.#spinner?.classList.toggle('is-active', isLoading);
        }

        #updateCount(count) {
            if (this.#count) this.#count.textContent = `${count} result${count === 1 ? '' : 's'}`;
        }

        #updateSortLinks() {
            if (!this.#table) return;
            this.#table.dataset.sort  = this.#sort;
            this.#table.dataset.order = this.#order;
            for (const link of this.#table.querySelectorAll('.eim-sort-link')) {
                const isCurrent = link.dataset.sort === this.#sort;
                link.dataset.order = isCurrent && this.#order === 'asc' ? 'desc' : 'asc';
                const indicator = link.querySelector('span');
                if (indicator) indicator.textContent = isCurrent ? (this.#order === 'asc' ? '^' : 'v') : '';
            }
        }
    }

    // ── Inline row actions ────────────────────────────────────────────────────

    async function toggleRead(btn) {
        const messageId = btn.dataset.messageId;
        const isRead    = btn.dataset.isRead === '1';
        const newRead   = !isRead;

        btn.disabled = true;

        try {
            const body = new URLSearchParams({
                action:     'eim_mark_message_read',
                nonce:      config.markReadNonce,
                message_id: messageId,
                is_read:    newRead ? '1' : '0',
            });
            const response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const { success } = await response.json();
            if (!success) return;

            const row = btn.closest('tr');

            btn.dataset.isRead = newRead ? '1' : '0';
            btn.textContent    = newRead ? 'Mark Unread' : 'Mark Read';

            const badge = row?.querySelector('.eim-msg-status-badge');
            if (badge) {
                badge.textContent = newRead ? 'Read' : 'Unread';
                if (newRead) {
                    badge.style.background = '#f0f0f1';
                    badge.style.color      = '#646970';
                } else {
                    badge.style.background = '#fff3cd';
                    badge.style.color      = '#856404';
                }
            }

            if (row) row.dataset.isRead = newRead ? '1' : '0';
        } catch (err) {
            console.error('[EIM] Mark-read failed:', err);
        } finally {
            btn.disabled = false;
        }
    }

    async function deleteMessage(btn) {
        const messageId = btn.dataset.messageId;
        if (!window.confirm('Delete this message? This cannot be undone.')) return;

        btn.disabled = true;

        try {
            const body = new URLSearchParams({
                action:     'eim_delete_message',
                nonce:      config.deleteNonce,
                message_id: messageId,
            });
            const response = await fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const { success } = await response.json();
            if (!success) { btn.disabled = false; return; }

            btn.closest('tr')?.remove();
        } catch (err) {
            console.error('[EIM] Delete message failed:', err);
            btn.disabled = false;
        }
    }

    // ── MessageThread — inline thread panel + reply form ─────────────────────

    /**
     * Manages the collapsible thread panel that opens below a message row,
     * showing the full conversation and a reply form for admin responses.
     */
    class MessageThread {
        /** @type {HTMLTableSectionElement|null} */
        #tbody;

        constructor() {
            this.#tbody = document.getElementById('eim-global-messages-table-body');
            if (!this.#tbody) return;

            this.#tbody.addEventListener('click', (e) => {
                const btn = e.target.closest('.eim-msg-thread');
                if (!btn) return;
                e.preventDefault();
                this.#toggleThread(btn);
            });
        }

        #toggleThread(btn) {
            const row = btn.closest('tr');
            if (!row) return;

            // If the thread row already follows this row, collapse it.
            const next = row.nextElementSibling;
            if (next?.classList.contains('eim-msg-thread-row')) {
                next.remove();
                return;
            }

            const eventId   = Number(btn.dataset.eventId   || 0);
            const groupId   = Number(btn.dataset.groupId   || 0);
            const groupName = btn.dataset.groupName || 'Thread';

            const threadRow = document.createElement('tr');
            threadRow.className = 'eim-msg-thread-row';
            const td = document.createElement('td');
            td.colSpan = 7;
            td.style.cssText = 'padding:0;border-top:none;';
            td.innerHTML = '<p style="color:#999;padding:14px 18px;">Loading…</p>';
            threadRow.appendChild(td);
            row.after(threadRow);

            this.#loadThread(td, eventId, groupId, groupName);
        }

        async #loadThread(td, eventId, groupId, groupName) {
            try {
                const url = new URL(ajaxurl, window.location.href);
                url.searchParams.set('action',   'eim_get_group_messages');
                url.searchParams.set('nonce',    config.getMessagesNonce || '');
                url.searchParams.set('event_id', String(eventId));
                url.searchParams.set('group_id', String(groupId));

                const { success, data } = await fetch(url, { credentials: 'same-origin' }).then(r => r.json());

                if (!success) {
                    td.innerHTML = '<p style="color:#d63638;padding:14px 18px;">Failed to load thread.</p>';
                    return;
                }

                this.#renderThread(td, eventId, groupId, groupName, data.messages || []);
            } catch (err) {
                console.error('[EIM] Load thread failed:', err);
                td.innerHTML = '<p style="color:#d63638;padding:14px 18px;">Unexpected error.</p>';
            }
        }

        #renderThread(td, eventId, groupId, groupName, messages) {
            const panel = document.createElement('div');
            panel.style.cssText = 'padding:14px 18px 16px;background:#f6f7f7;border-top:2px solid #2271b1;';

            const heading = document.createElement('p');
            heading.style.cssText = 'margin:0 0 12px;font-size:12px;color:#646970;font-weight:600;text-transform:uppercase;letter-spacing:.04em;';
            heading.textContent = `Thread with ${groupName}`;
            panel.appendChild(heading);

            const msgList = document.createElement('div');
            msgList.className = 'eim-thread-messages';
            msgList.style.cssText = 'max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:8px;margin-bottom:14px;padding-right:4px;';
            this.#populateBubbles(msgList, messages);
            panel.appendChild(msgList);

            // Reply form
            const replyRow = document.createElement('div');
            replyRow.style.cssText = 'display:flex;gap:8px;align-items:flex-end;';

            const textarea = document.createElement('textarea');
            textarea.rows = 2;
            textarea.placeholder = 'Type a reply…';
            textarea.style.cssText = 'flex:1;resize:vertical;min-height:58px;border:1px solid #8c8f94;border-radius:4px;padding:6px 10px;font-size:13px;font-family:inherit;';
            replyRow.appendChild(textarea);

            const sendBtn = document.createElement('button');
            sendBtn.type = 'button';
            sendBtn.className = 'button button-primary';
            sendBtn.textContent = 'Send Reply';
            sendBtn.style.cssText = 'white-space:nowrap;height:fit-content;';
            replyRow.appendChild(sendBtn);

            panel.appendChild(replyRow);

            sendBtn.addEventListener('click', async () => {
                const text = textarea.value.trim();
                if (!text) return;
                sendBtn.disabled = true;
                sendBtn.textContent = 'Sending…';
                try {
                    await this.#sendReply(td, eventId, groupId, groupName, text, textarea, msgList);
                } finally {
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send Reply';
                }
            });

            td.replaceChildren(panel);
            setTimeout(() => { msgList.scrollTop = msgList.scrollHeight; }, 0);
        }

        #populateBubbles(msgList, messages) {
            msgList.replaceChildren();
            if (!messages.length) {
                const empty = document.createElement('p');
                empty.style.cssText = 'color:#999;font-size:13px;margin:0;';
                empty.textContent = 'No messages yet.';
                msgList.appendChild(empty);
                return;
            }
            for (const msg of messages) {
                msgList.appendChild(this.#createBubble(msg));
            }
        }

        #createBubble(msg) {
            const isAdmin = Boolean(msg.is_admin_reply);
            const wrap = document.createElement('div');
            wrap.style.cssText = `display:flex;justify-content:${isAdmin ? 'flex-end' : 'flex-start'};`;

            const bubble = document.createElement('div');
            bubble.style.cssText = [
                'max-width:72%;padding:8px 12px;border-radius:14px;',
                isAdmin
                    ? 'background:#2271b1;color:#fff;border-bottom-right-radius:3px;'
                    : 'background:#e8eaed;color:#1e1e1e;border-bottom-left-radius:3px;',
            ].join('');

            const text = document.createElement('p');
            text.style.cssText = 'margin:0 0 4px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word;';
            text.textContent = msg.message;
            bubble.appendChild(text);

            const meta = document.createElement('span');
            meta.style.cssText = `font-size:10px;display:block;text-align:${isAdmin ? 'right' : 'left'};${isAdmin ? 'color:rgba(255,255,255,.65);' : 'color:#999;'}`;
            meta.textContent = (isAdmin ? 'You · ' : '') + this.#formatDate(msg.created_at);
            bubble.appendChild(meta);

            wrap.appendChild(bubble);
            return wrap;
        }

        async #sendReply(td, eventId, groupId, groupName, text, textarea, msgList) {
            try {
                const body = new URLSearchParams({
                    action:   'eim_reply_to_message',
                    nonce:    config.replyNonce || '',
                    event_id: String(eventId),
                    group_id: String(groupId),
                    message:  text,
                });
                const { success, data } = await fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                }).then(r => r.json());

                if (!success) { alert('Failed to send reply. Please try again.'); return; }

                this.#populateBubbles(msgList, data.messages || []);
                setTimeout(() => { msgList.scrollTop = msgList.scrollHeight; }, 0);
                textarea.value = '';

                // Sync the table row: mark-read state + toggle-read button text.
                const threadRow = td.closest('tr.eim-msg-thread-row');
                const msgRow    = threadRow?.previousElementSibling;
                if (msgRow) {
                    const badge = msgRow.querySelector('.eim-msg-status-badge');
                    if (badge) {
                        badge.textContent      = 'Read';
                        badge.style.background = '#f0f0f1';
                        badge.style.color      = '#646970';
                    }
                    msgRow.dataset.isRead = '1';
                    const toggleBtn = msgRow.querySelector('.eim-msg-toggle-read');
                    if (toggleBtn) {
                        toggleBtn.dataset.isRead = '1';
                        toggleBtn.textContent    = 'Mark Unread';
                    }
                }
            } catch (err) {
                console.error('[EIM] Reply failed:', err);
                alert('Unexpected error. Please try again.');
            }
        }

        #formatDate(datetime) {
            if (!datetime) return '';
            try {
                return new Date(datetime.replace(' ', 'T') + 'Z').toLocaleString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit', hour12: true,
                });
            } catch { return datetime; }
        }
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        if (!config.table?.enabled) return;

        new GlobalMessagesTable();
        new MessageThread();

        document.getElementById('eim-global-messages-table-body')?.addEventListener('click', (e) => {
            const toggleBtn = e.target.closest('.eim-msg-toggle-read');
            if (toggleBtn) { toggleRead(toggleBtn); return; }

            const deleteBtn = e.target.closest('.eim-msg-delete');
            if (deleteBtn) deleteMessage(deleteBtn);
        });
    });
})();
