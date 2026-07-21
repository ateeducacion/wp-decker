/**
 * Progressive semantic enhancements for Decker interfaces.
 *
 * Enhancements are additive and idempotent: they set ARIA attributes and add
 * hidden labels on the existing markup. Nodes are never replaced, so event
 * listeners, saved references, and Bootstrap component instances are preserved.
 * `enhance()` may be called again after any dynamic render.
 */
(function (window, document) {
	'use strict';

	function ensureLabel(control) {
		// Skip controls that are already named: an existing aria-label or a
		// matching <label> wins, so a hidden label would be redundant.
		if (!control || !control.id || control.hasAttribute('aria-label') ||
			document.querySelector('label[for="' + control.id + '"]')) {
			return;
		}

		const text = control.getAttribute('placeholder') ||
			(control.options && control.options.length ? control.options[0].textContent : '');
		if (!text.trim()) {
			return;
		}

		const label = document.createElement('label');
		label.className = 'visually-hidden';
		label.htmlFor = control.id;
		label.textContent = text.trim();
		control.parentNode.insertBefore(label, control);
	}

	/**
	 * Make an icon-only popover trigger keyboard-focusable and announced without
	 * replacing the element, which would detach its Bootstrap popover instance.
	 */
	function enhancePopoverIcon(icon) {
		if (!icon || icon.hasAttribute('aria-label')) {
			return;
		}

		const accessibleName = String(icon.getAttribute('data-bs-content') || '').replace(/\s+/g, ' ').trim();
		if (!accessibleName) {
			return;
		}

		icon.setAttribute('role', 'button');
		icon.setAttribute('tabindex', '0');
		icon.setAttribute('aria-label', accessibleName);

		// A non-native element does not activate on Enter/Space, so the role="button"
		// promise would be empty. Toggle the Bootstrap popover on those keys; the
		// aria-label guard above keeps this listener from being bound twice.
		icon.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' && event.key !== ' ') {
				return;
			}
			event.preventDefault();
			if (window.bootstrap && window.bootstrap.Popover) {
				window.bootstrap.Popover.getOrCreateInstance(icon).toggle();
			}
		});
	}

	/**
	 * Label a task column region and expose list semantics on its cards, in place.
	 */
	function enhanceTaskSection(section, index) {
		if (!section.hasAttribute('role')) {
			section.setAttribute('role', 'group');
		}

		const heading = section.querySelector('.task-header');
		if (heading) {
			heading.id = heading.id || 'decker-task-stack-' + (index + 1);
			section.setAttribute('aria-labelledby', heading.id);
		}

		const list = section.querySelector('.task-list-items');
		if (list) {
			list.setAttribute('role', 'list');
			// Only direct children are valid list items for the list role.
			list.querySelectorAll(':scope > .card').forEach(function (card) {
				card.setAttribute('role', 'listitem');
			});
		}
	}

	/**
	 * Errors and warnings are assertive alerts; everything else is a polite status.
	 */
	function enhanceAlert(alert) {
		if (alert.hasAttribute('role')) {
			return;
		}

		if (alert.classList.contains('alert-danger') || alert.classList.contains('alert-warning')) {
			alert.setAttribute('role', 'alert');
		} else {
			alert.setAttribute('role', 'status');
			alert.setAttribute('aria-live', 'polite');
		}
	}

	function enhance(root) {
		const scope = root || document;
		// querySelector works on both Document and Element, so it accepts any root.
		const byId = function (id) {
			return scope.querySelector('#' + id);
		};

		const searchInput = byId('searchInput');
		if (searchInput) {
			searchInput.setAttribute('autocomplete', 'off');
			ensureLabel(searchInput);
		}
		ensureLabel(byId('boardUserFilter'));

		scope.querySelectorAll('i[data-bs-toggle="popover"][data-bs-content]').forEach(enhancePopoverIcon);
		scope.querySelectorAll('.board > .tasks').forEach(enhanceTaskSection);
		scope.querySelectorAll('.alert').forEach(enhanceAlert);
	}

	window.DeckerAgentSemantics = { enhance: enhance };
	enhance(document);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			enhance(document);
		});
	}
}(window, document));
