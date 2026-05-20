/* global bootstrap */

(function () {
    'use strict';

    const POPOVER_OPTIONS = {
        html: true,
        sanitize: false,
        trigger: 'hover focus',
        placement: 'right',
        fallbackPlacements: ['left', 'top', 'bottom'],
        customClass: 'decker-labels-popover-pop'
    };

    function initOne(trigger) {
        if (!trigger || trigger.dataset.deckerLabelsPopoverInited === '1') {
            return;
        }
        const content = trigger.getAttribute('data-decker-labels-content') || '';
        if (!content) {
            return;
        }

        const existing = bootstrap.Popover.getInstance(trigger);
        if (existing) {
            existing.dispose();
        }

        new bootstrap.Popover(trigger, Object.assign({}, POPOVER_OPTIONS, {
            content: content,
            title: ''
        }));
        trigger.dataset.deckerLabelsPopoverInited = '1';
    }

    function initAll(container) {
        const root = container || document;
        const candidates = [];

        if (root.nodeType === Node.ELEMENT_NODE
            && typeof root.matches === 'function'
            && root.matches('.decker-labels-popover')
        ) {
            candidates.push(root);
        }

        if (typeof root.querySelectorAll === 'function') {
            candidates.push.apply(
                candidates,
                root.querySelectorAll('.decker-labels-popover')
            );
        }

        candidates.forEach(initOne);
    }

    function findTriggerForTip(tip) {
        const triggers = document.querySelectorAll('.decker-labels-popover');
        for (let i = 0; i < triggers.length; i++) {
            const inst = bootstrap.Popover.getInstance(triggers[i]);
            if (inst && inst.tip === tip) {
                return { trigger: triggers[i], popover: inst };
            }
        }
        return null;
    }

    function handleCloseClick(event) {
        const closeBtn = event.target.closest('.decker-labels-popover-close');
        if (!closeBtn) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const tip = closeBtn.closest('.popover');
        if (!tip) {
            return;
        }
        const owner = findTriggerForTip(tip);
        if (owner) {
            owner.popover.hide();
        }
    }

    function observeNewCards() {
        if (!document.body || typeof MutationObserver !== 'function') {
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

    function ready() {
        if (typeof bootstrap === 'undefined' || !bootstrap.Popover) {
            return;
        }
        initAll(document);
        document.addEventListener('click', handleCloseClick, true);
        observeNewCards();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
