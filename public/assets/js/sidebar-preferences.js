/**
 * Persist user-specific sidebar preferences in the browser.
 *
 * @param {Window}   root    Browser window.
 * @param {Function} factory Preferences factory.
 * @package
 */

( function ( root, factory ) {
	'use strict';

	const preferences = factory( root, root.document );
	root.DeckerSidebarPreferences = preferences;

	if ( typeof module === 'object' && module.exports ) {
		module.exports = preferences;
	}

	preferences.init();
} )(
	typeof window !== 'undefined' ? window : globalThis,
	function ( root, document ) {
		'use strict';

		const EXPANDED_MENU_KEY = 'decker.sidebar.expandedMenu';
		const SHOW_BOARD_STATUS_KEY = 'decker.sidebar.showBoardStatus';
		const SIDEBAR_SIZE_KEY = 'decker.sidebar.size';
		const HIDE_BOARD_STATUS_CLASS = 'decker-hide-board-status';

		/**
		 * Read a preference without breaking the interface when storage is unavailable.
		 *
		 * @param {string} key Preference key.
		 * @return {string|null} Stored value, or null when unavailable.
		 */
		function readPreference( key ) {
			try {
				return root.localStorage.getItem( key );
			} catch {
				return null;
			}
		}

		/**
		 * Store a preference without breaking the interface when storage is unavailable.
		 *
		 * @param {string} key   Preference key.
		 * @param {string} value Preference value.
		 */
		function writePreference( key, value ) {
			try {
				root.localStorage.setItem( key, value );
			} catch {
				// The UI remains usable when storage is blocked or full.
			}
		}

		/**
		 * Synchronize a collapse toggle with its menu state.
		 *
		 * @param {HTMLElement} menu     Collapsible menu.
		 * @param {boolean}     expanded Whether the menu is expanded.
		 */
		function syncMenuToggle( menu, expanded ) {
			document
				.querySelectorAll(
					'.side-nav [data-bs-toggle="collapse"][aria-controls]'
				)
				.forEach( function ( toggle ) {
					if ( toggle.getAttribute( 'aria-controls' ) !== menu.id ) {
						return;
					}

					toggle.setAttribute(
						'aria-expanded',
						expanded ? 'true' : 'false'
					);
					toggle.classList.toggle( 'collapsed', ! expanded );
				} );
		}

		/**
		 * Restore and observe the expanded sidebar menu.
		 */
		function initializeExpandedMenu() {
			const menus = document.querySelectorAll(
				'.side-nav .collapse[id]'
			);
			const storedMenuId = readPreference( EXPANDED_MENU_KEY );

			menus.forEach( function ( menu ) {
				const expanded =
					storedMenuId === null
						? menu.classList.contains( 'show' )
						: menu.id === storedMenuId;
				menu.classList.toggle( 'show', expanded );
				syncMenuToggle( menu, expanded );

				if ( menu.dataset.deckerPreferenceBound === 'true' ) {
					return;
				}

				menu.dataset.deckerPreferenceBound = 'true';
				menu.addEventListener( 'shown.bs.collapse', function () {
					writePreference( EXPANDED_MENU_KEY, menu.id );
				} );
				menu.addEventListener( 'hidden.bs.collapse', function () {
					const expandedMenuId = readPreference( EXPANDED_MENU_KEY );
					if (
						expandedMenuId === null ||
						expandedMenuId === menu.id
					) {
						writePreference( EXPANDED_MENU_KEY, '' );
					}
				} );
			} );
		}

		/**
		 * Apply the board status indicator preference.
		 *
		 * @param {boolean} showIndicators Whether status indicators should be visible.
		 */
		function applyBoardStatusPreference( showIndicators ) {
			document.documentElement.classList.toggle(
				HIDE_BOARD_STATUS_CLASS,
				! showIndicators
			);

			const toggle = document.getElementById(
				'sidebar-board-status-check'
			);
			if ( toggle ) {
				toggle.checked = showIndicators;
			}
		}

		/**
		 * Restore and observe the board status indicator preference.
		 */
		function initializeBoardStatusPreference() {
			const toggle = document.getElementById(
				'sidebar-board-status-check'
			);
			const showIndicators =
				readPreference( SHOW_BOARD_STATUS_KEY ) !== 'false';

			applyBoardStatusPreference( showIndicators );

			if ( ! toggle || toggle.dataset.deckerPreferenceBound === 'true' ) {
				return;
			}

			toggle.dataset.deckerPreferenceBound = 'true';
			toggle.addEventListener( 'change', function () {
				writePreference(
					SHOW_BOARD_STATUS_KEY,
					toggle.checked ? 'true' : 'false'
				);
				applyBoardStatusPreference( toggle.checked );
			} );
		}

		/**
		 * Restore and observe the desktop sidebar size controlled by the menu button.
		 */
		function initializeSidebarSize() {
			const storedSize = readPreference( SIDEBAR_SIZE_KEY );
			const menuToggle = document.querySelector( '.button-toggle-menu' );

			if ( root.innerWidth > 1140 && storedSize ) {
				document.documentElement.setAttribute(
					'data-sidenav-size',
					storedSize
				);
			}

			if (
				! menuToggle ||
				menuToggle.dataset.deckerPreferenceBound === 'true'
			) {
				return;
			}

			menuToggle.dataset.deckerPreferenceBound = 'true';
			menuToggle.addEventListener( 'click', function () {
				const currentSize =
					document.documentElement.getAttribute(
						'data-sidenav-size'
					);
				if ( root.innerWidth > 1140 && currentSize ) {
					writePreference( SIDEBAR_SIZE_KEY, currentSize );
				}
			} );
		}

		/**
		 * Initialize persistent sidebar preferences.
		 */
		function init() {
			if ( ! document ) {
				return;
			}

			initializeExpandedMenu();
			initializeBoardStatusPreference();
			initializeSidebarSize();
		}

		return {
			EXPANDED_MENU_KEY,
			SHOW_BOARD_STATUS_KEY,
			SIDEBAR_SIZE_KEY,
			init,
		};
	}
);
