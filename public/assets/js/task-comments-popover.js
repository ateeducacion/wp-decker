/* global bootstrap, wpApiSettings, deckerVars */

(function () {
    'use strict';

    const PREVIEW_LIMIT = 5;
    const BODY_MAX_LENGTH = 140;
    const cache = new Map();
    const inflight = new Map();
    const initialized = new WeakSet();

    function t(key, fallback) {
        try {
            if (window.deckerVars && deckerVars.strings && deckerVars.strings[key]) {
                return deckerVars.strings[key];
            }
        } catch (e) {
            // ignore
        }
        return fallback;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function stripHtml(html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
    }

    function truncate(text, max) {
        if (!text) {
            return '';
        }
        if (text.length <= max) {
            return text;
        }
        return text.slice(0, max - 1).trimEnd() + '…';
    }

    function formatDate(iso) {
        if (!iso) {
            return '';
        }
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        try {
            const locale = (window.deckerVars && deckerVars.locale) || undefined;
            return date.toLocaleString(locale, {
                dateStyle: 'short',
                timeStyle: 'short'
            });
        } catch (e) {
            return date.toISOString().slice(0, 16).replace('T', ' ');
        }
    }

    function buildContent(comments, total) {
        if (!comments.length) {
            return '<div class="text-muted small">' +
                escapeHtml(t('no_comments', 'No comments yet.')) + '</div>';
        }

        const parts = ['<div class="decker-comments-popover-list">'];
        comments.forEach(function (comment) {
            const author = escapeHtml(comment.author_name || '');
            const avatar = comment.author_avatar_urls && (
                comment.author_avatar_urls['48'] ||
                comment.author_avatar_urls['96'] ||
                comment.author_avatar_urls['24']
            );
            const date = escapeHtml(formatDate(comment.date));
            const body = escapeHtml(
                truncate(stripHtml(comment.content && comment.content.rendered), BODY_MAX_LENGTH)
            );

            parts.push('<div class="d-flex align-items-start mb-2">');
            if (avatar) {
                parts.push(
                    '<img class="me-2 rounded-circle" width="28" height="28" alt="" src="' +
                    escapeHtml(avatar) + '">'
                );
            }
            parts.push('<div class="flex-grow-1">');
            parts.push(
                '<div class="d-flex justify-content-between align-items-baseline">' +
                '<strong class="me-2">' + author + '</strong>' +
                '<small class="text-muted">' + date + '</small>' +
                '</div>'
            );
            parts.push('<div class="small">' + body + '</div>');
            parts.push('</div></div>');
        });
        parts.push('</div>');

        if (total > comments.length) {
            const more = total - comments.length;
            const label = escapeHtml(
                t('more_comments', 'and %d more').replace('%d', String(more))
            );
            parts.push('<div class="text-muted small text-end">' + label + '</div>');
        }

        return parts.join('');
    }

    function fetchComments(taskId) {
        if (cache.has(taskId)) {
            return Promise.resolve(cache.get(taskId));
        }
        if (inflight.has(taskId)) {
            return inflight.get(taskId);
        }

        if (!window.wpApiSettings || !wpApiSettings.root) {
            return Promise.reject(new Error('wpApiSettings not available'));
        }

        const url = wpApiSettings.root + 'wp/v2/comments' +
            '?post=' + encodeURIComponent(taskId) +
            '&per_page=' + PREVIEW_LIMIT +
            '&orderby=date&order=desc' +
            '&_fields=id,author_name,author_avatar_urls,date,content,parent';

        const request = fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-WP-Nonce': wpApiSettings.nonce || ''
            }
        }).then(function (response) {
            const total = parseInt(response.headers.get('X-WP-Total') || '0', 10);
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json().then(function (comments) {
                const data = {
                    comments: Array.isArray(comments) ? comments.reverse() : [],
                    total: total || (Array.isArray(comments) ? comments.length : 0)
                };
                cache.set(taskId, data);
                return data;
            });
        }).finally(function () {
            inflight.delete(taskId);
        });

        inflight.set(taskId, request);
        return request;
    }

    function refreshPopoverContent(triggerEl, html) {
        const popover = bootstrap.Popover.getInstance(triggerEl);
        if (!popover) {
            return;
        }
        triggerEl.setAttribute('data-bs-content', html);
        popover.setContent({
            '.popover-header': triggerEl.getAttribute('title') || '',
            '.popover-body': html
        });
        if (typeof popover.update === 'function') {
            popover.update();
        }
    }

    function handleShow(event) {
        const triggerEl = event.target;
        if (!triggerEl || !triggerEl.matches || !triggerEl.matches('.decker-comments-popover')) {
            return;
        }
        const taskId = triggerEl.getAttribute('data-decker-task-id');
        if (!taskId) {
            return;
        }

        if (cache.has(taskId)) {
            const cached = cache.get(taskId);
            refreshPopoverContent(triggerEl, buildContent(cached.comments, cached.total));
            return;
        }

        const loadingHtml = '<div class="text-muted small">' +
            escapeHtml(t('loading_comments', 'Loading comments…')) + '</div>';
        refreshPopoverContent(triggerEl, loadingHtml);

        fetchComments(taskId).then(function (data) {
            const popover = bootstrap.Popover.getInstance(triggerEl);
            if (!popover || !popover.tip) {
                return;
            }
            refreshPopoverContent(triggerEl, buildContent(data.comments, data.total));
        }).catch(function () {
            refreshPopoverContent(
                triggerEl,
                '<div class="text-danger small">' +
                escapeHtml(t('comments_error', 'Could not load comments.')) +
                '</div>'
            );
        });
    }

    const pinnedTriggers = new Set();

    function pin(el) {
        pinnedTriggers.add(el);
        el.classList.add('decker-comments-popover-pinned');
        el.setAttribute('aria-pressed', 'true');
    }

    function unpin(el) {
        pinnedTriggers.delete(el);
        el.classList.remove('decker-comments-popover-pinned');
        el.setAttribute('aria-pressed', 'false');
    }

    function dismissAllPinnedExcept(el, clickTarget) {
        pinnedTriggers.forEach(function (other) {
            if (other === el) {
                return;
            }
            const popover = bootstrap.Popover.getInstance(other);
            const tip = popover && popover.tip;
            if (clickTarget && (other.contains(clickTarget) || (tip && tip.contains(clickTarget)))) {
                return;
            }
            unpin(other);
            if (popover) {
                popover.hide();
            }
        });
    }

    function ensurePopover(el) {
        if (initialized.has(el)) {
            return;
        }
        if (typeof bootstrap === 'undefined' || !bootstrap.Popover) {
            return;
        }
        bootstrap.Popover.getOrCreateInstance(el);
        el.addEventListener('show.bs.popover', handleShow);
        el.addEventListener('hide.bs.popover', function (event) {
            if (pinnedTriggers.has(el)) {
                event.preventDefault();
            }
        });
        el.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const popover = bootstrap.Popover.getInstance(el);
            if (!popover) {
                return;
            }
            if (pinnedTriggers.has(el)) {
                unpin(el);
                popover.hide();
            } else {
                dismissAllPinnedExcept(el, null);
                pin(el);
                popover.show();
            }
        });
        el.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                el.click();
            }
        });
        initialized.add(el);
    }

    function initAll(root) {
        const scope = root || document;
        if (scope.nodeType === Node.ELEMENT_NODE && scope.matches &&
            scope.matches('.decker-comments-popover')) {
            ensurePopover(scope);
        }
        if (typeof scope.querySelectorAll === 'function') {
            scope.querySelectorAll('.decker-comments-popover').forEach(ensurePopover);
        }
    }

    function observe() {
        if (!document.body || typeof MutationObserver === 'undefined') {
            return;
        }
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        initAll(node);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('click', function (event) {
        if (!pinnedTriggers.size) {
            return;
        }
        pinnedTriggers.forEach(function (el) {
            const popover = bootstrap.Popover.getInstance(el);
            const tip = popover && popover.tip;
            if (el === event.target || el.contains(event.target)) {
                return;
            }
            if (tip && tip.contains(event.target)) {
                return;
            }
            unpin(el);
            if (popover) {
                popover.hide();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !pinnedTriggers.size) {
            return;
        }
        pinnedTriggers.forEach(function (el) {
            unpin(el);
            const popover = bootstrap.Popover.getInstance(el);
            if (popover) {
                popover.hide();
            }
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAll();
            observe();
        });
    } else {
        initAll();
        observe();
    }
})();
