(function (window, document) {
	'use strict';

	function ensureLabel(control, name) {
		if (!control || !control.id || document.querySelector('label[for="' + control.id + '"]')) {
			return;
		}

		const text = control.getAttribute('aria-label') || control.getAttribute('placeholder') ||
			(control.options && control.options.length ? control.options[0].textContent : '');
		if (!text.trim()) {
			return;
		}

		const label = document.createElement('label');
		label.className = 'visually-hidden';
		label.htmlFor = control.id;
		label.textContent = text.trim();
		control.parentNode.insertBefore(label, control);
		control.name = control.name || name;
	}

	function replaceActionAnchor(anchor) {
		if (!anchor || anchor.tagName !== 'A') {
			return null;
		}

		const button = document.createElement('button');
		button.type = 'button';
		Array.from(anchor.attributes).forEach(function (attribute) {
			if (attribute.name !== 'href') {
				button.setAttribute(attribute.name, attribute.value);
			}
		});
		while (anchor.firstChild) {
			button.appendChild(anchor.firstChild);
		}
		anchor.replaceWith(button);
		return button;
	}

	function getPopoverAccessibleName(icon) {
		const content = icon.getAttribute('data-bs-content') || '';
		return String(content).replace(/\s+/g, ' ').trim();
	}

	function replacePopoverIcon(icon) {
		if (!icon || icon.tagName === 'BUTTON') {
			return null;
		}

		const accessibleName = getPopoverAccessibleName(icon);
		if (!accessibleName) {
			return null;
		}

		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn btn-link p-0 border-0 align-baseline ms-1';
		button.setAttribute('aria-label', accessibleName);
		Array.from(icon.attributes).forEach(function (attribute) {
			if (attribute.name.indexOf('data-bs-') === 0) {
				button.setAttribute(attribute.name, attribute.value);
			}
		});

		const replacementIcon = document.createElement('i');
		replacementIcon.className = icon.className.replace(/\bms-1\b/g, '').trim();
		replacementIcon.setAttribute('aria-hidden', 'true');
		button.appendChild(replacementIcon);
		icon.replaceWith(button);
		return button;
	}

	function enhanceTaskSection(container, index) {
		let section = container;
		if (container.tagName !== 'SECTION') {
			section = document.createElement('section');
			Array.from(container.attributes).forEach(function (attribute) {
				section.setAttribute(attribute.name, attribute.value);
			});
			while (container.firstChild) {
				section.appendChild(container.firstChild);
			}
			container.replaceWith(section);
		}

		const heading = section.querySelector('.task-header');
		if (heading) {
			heading.id = heading.id || 'decker-task-stack-' + (index + 1);
			section.setAttribute('aria-labelledby', heading.id);
		}

		const list = section.querySelector('.task-list-items');
		if (list) {
			list.setAttribute('role', 'list');
			list.querySelectorAll('.card').forEach(function (card) {
				card.setAttribute('role', 'listitem');
			});
		}
		return section;
	}

	function enhance(root) {
		const searchInput = root.getElementById('searchInput');
		if (searchInput) {
			searchInput.setAttribute('autocomplete', 'off');
			ensureLabel(searchInput, 'decker_search');
		}
		ensureLabel(root.getElementById('boardUserFilter'), 'decker_user_filter');
		replaceActionAnchor(root.getElementById('fix-order-btn'));

		root.querySelectorAll('i[data-bs-toggle="popover"][data-bs-content]').forEach(replacePopoverIcon);
		root.querySelectorAll('.board > .tasks').forEach(enhanceTaskSection);
		root.querySelectorAll('.alert').forEach(function (alert) {
			if (!alert.hasAttribute('role')) {
				alert.setAttribute('role', 'status');
			}
			alert.setAttribute('aria-live', 'polite');
		});
	}

	window.DeckerAgentSemantics = { enhance: enhance };
	enhance(document);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			enhance(document);
		});
	}
}(window, document));
