/**
 * Global Search for Decker
 * Keyboard shortcut: Ctrl+K or Cmd+K
 */
(function () {
	'use strict';

	let searchModal = null;
	let searchInput = null;
	let searchResults = null;
	let selectedIndex = -1;
	let currentResults = [];
	let searchTimeout = null;
	let modalInstance = null;

	/**
	 * Initialize the global search functionality
	 */
	function init() {
		createSearchModal();
		registerKeyboardShortcut();
	}

	/**
	 * Create the search modal HTML and append to body
	 */
	function createSearchModal() {
		const modalHTML = `
			<div class="modal fade" id="deckerGlobalSearchModal" tabindex="-1" aria-labelledby="deckerGlobalSearchModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered modal-lg">
					<div class="modal-content">
						<div class="modal-header border-0 pb-0">
							<input type="text" 
								id="deckerGlobalSearchInput" 
								class="form-control form-control-lg border-0" 
								placeholder="${deckerSearchVars.strings.search_placeholder}"
								autocomplete="off">
						</div>
						<div class="modal-body pt-0">
							<div id="deckerGlobalSearchResults" class="search-results" style="max-height: 400px; overflow-y: auto;">
								<div class="text-muted text-center py-3">
									<i class="ri-search-line fs-3"></i>
									<p class="mt-2">${deckerSearchVars.strings.search_hint}</p>
								</div>
							</div>
						</div>
						<div class="modal-footer border-0 pt-0 pb-3">
							<div class="d-flex justify-content-between w-100 small text-muted">
								<span><kbd>&uarr;</kbd> <kbd>&darr;</kbd> ${deckerSearchVars.strings.navigate}</span>
								<span><kbd>Enter</kbd> ${deckerSearchVars.strings.select}</span>
								<span><kbd>Esc</kbd> ${deckerSearchVars.strings.close}</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		`;

		document.body.insertAdjacentHTML('beforeend', modalHTML);

		searchModal = document.getElementById('deckerGlobalSearchModal');
		searchInput = document.getElementById('deckerGlobalSearchInput');
		searchResults = document.getElementById('deckerGlobalSearchResults');

		// Initialize Bootstrap modal
		modalInstance = new bootstrap.Modal(searchModal, {
			keyboard: false // We handle keyboard events manually
		});

		// Add event listeners
		searchInput.addEventListener('input', handleSearchInput);
		searchInput.addEventListener('keydown', handleKeyNavigation);
		
		// Focus input when modal is shown
		searchModal.addEventListener('shown.bs.modal', function () {
			searchInput.focus();
		});
		
		// Reset on modal close
		searchModal.addEventListener('hidden.bs.modal', resetSearch);
	}

	/**
	 * Register global keyboard shortcut (Ctrl+K or Cmd+K)
	 */
	function registerKeyboardShortcut() {
		document.addEventListener('keydown', function (event) {
			// Check for Ctrl+K (Windows/Linux) or Cmd+K (Mac)
			if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
				event.preventDefault();
				openSearchModal();
			}
		});

		// Also listen for click on the search trigger button
		const searchTrigger = document.getElementById('deckerGlobalSearchTrigger');
		if (searchTrigger) {
			searchTrigger.addEventListener('click', function (event) {
				event.preventDefault();
				openSearchModal();
			});
		}
	}

	/**
	 * Open the search modal and focus the input
	 */
	function openSearchModal() {
		modalInstance.show();
	}

	/**
	 * Handle search input changes
	 */
	function handleSearchInput() {
		const query = searchInput.value.trim();

		// Clear previous timeout
		if (searchTimeout) {
			clearTimeout(searchTimeout);
		}

		// Show loading state
		if (query.length >= 2) {
			searchResults.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Loading...</span></div></div>';

			// Debounce search
			searchTimeout = setTimeout(function () {
				performSearch(query);
			}, 300);
		} else if (query.length === 0) {
			resetSearch();
		}
	}

	/**
	 * Perform the search via REST API
	 */
	function performSearch(query) {
		const url = deckerSearchVars.restUrl + 'decker/v1/tasks/search?search=' + encodeURIComponent(query);

		fetch(url, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': deckerSearchVars.nonce
			}
		})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('Network response was not ok');
			}
			return response.json();
		})
		.then(function (data) {
			if (data.success && data.tasks) {
				displayResults(data.tasks);
			} else {
				displayNoResults();
			}
		})
		.catch(function (error) {
			console.error('Search error:', error);
			searchResults.innerHTML = '<div class="alert alert-danger">' + deckerSearchVars.strings.error + '</div>';
		});
	}

	/**
	 * Display search results
	 */
	function displayResults(tasks) {
		currentResults = tasks;
		selectedIndex = -1;

		if (tasks.length === 0) {
			displayNoResults();
			return;
		}

		let html = '<div class="list-group list-group-flush">';
		tasks.forEach(function (task, index) {
			html += `
				<a href="${task.url}" 
					class="list-group-item list-group-item-action search-result-item" 
					data-index="${index}">
					<div class="d-flex w-100 justify-content-between align-items-center">
						<h6 class="mb-1">${escapeHtml(task.title)}</h6>
						<small class="text-muted">${escapeHtml(task.stack_label)}</small>
					</div>
					<small class="text-muted">
						<i class="ri-folder-line"></i> ${escapeHtml(task.board)}
					</small>
				</a>
			`;
		});
		html += '</div>';

		searchResults.innerHTML = html;

		// Add click handlers
		const resultItems = searchResults.querySelectorAll('.search-result-item');
		resultItems.forEach(function (item) {
			item.addEventListener('click', handleResultClick);
			item.addEventListener('mouseenter', function () {
				updateSelection(parseInt(item.getAttribute('data-index')));
			});
		});
	}

	/**
	 * Display "no results" message
	 */
	function displayNoResults() {
		searchResults.innerHTML = `
			<div class="text-muted text-center py-3">
				<i class="ri-search-line fs-3"></i>
				<p class="mt-2">${deckerSearchVars.strings.no_results}</p>
			</div>
		`;
		currentResults = [];
		selectedIndex = -1;
	}

	/**
	 * Handle keyboard navigation (arrow keys and Enter)
	 */
	function handleKeyNavigation(event) {
		// Handle Escape key regardless of results
		if (event.key === 'Escape') {
			event.preventDefault();
			modalInstance.hide();
			return;
		}

		// Other key navigation requires results
		if (currentResults.length === 0) {
			return;
		}

		switch (event.key) {
			case 'ArrowDown':
				event.preventDefault();
				selectedIndex = (selectedIndex + 1) % currentResults.length;
				updateSelection(selectedIndex);
				break;

			case 'ArrowUp':
				event.preventDefault();
				selectedIndex = selectedIndex <= 0 ? currentResults.length - 1 : selectedIndex - 1;
				updateSelection(selectedIndex);
				break;

			case 'Enter':
				event.preventDefault();
				if (selectedIndex >= 0 && currentResults[selectedIndex]) {
					navigateToTask(currentResults[selectedIndex].url);
				}
				break;
		}
	}

	/**
	 * Update visual selection of result items
	 */
	function updateSelection(index) {
		selectedIndex = index;
		const items = searchResults.querySelectorAll('.search-result-item');
		items.forEach(function (item, idx) {
			if (idx === selectedIndex) {
				item.classList.add('active');
				// Scroll into view if needed
				item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
			} else {
				item.classList.remove('active');
			}
		});
	}

	/**
	 * Handle result item click
	 */
	function handleResultClick(event) {
		event.preventDefault();
		const url = event.currentTarget.getAttribute('href');
		navigateToTask(url);
	}

	/**
	 * Navigate to task
	 */
	function navigateToTask(url) {
		modalInstance.hide();
		window.location.href = url;
	}

	/**
	 * Reset search state
	 */
	function resetSearch() {
		searchInput.value = '';
		searchResults.innerHTML = `
			<div class="text-muted text-center py-3">
				<i class="ri-search-line fs-3"></i>
				<p class="mt-2">${deckerSearchVars.strings.search_hint}</p>
			</div>
		`;
		currentResults = [];
		selectedIndex = -1;
	}

	/**
	 * Escape HTML to prevent XSS
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
