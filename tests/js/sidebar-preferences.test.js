/**
 * Unit tests for persistent sidebar preferences.
 *
 * @package
 */

const preferences = require( '../../public/assets/js/sidebar-preferences.js' );

/**
 * Render the sidebar elements managed by the preferences module.
 *
 * @param {string} expandedMenu The menu rendered expanded by PHP.
 */
function renderSidebar( expandedMenu = '' ) {
	document.documentElement.className = '';
	document.documentElement.setAttribute( 'data-sidenav-size', 'default' );
	document.body.innerHTML = `
		<button type="button" class="button-toggle-menu">Menu</button>
		<ul class="side-nav">
			<li>
				<a data-bs-toggle="collapse" href="#sidebarTasks" aria-controls="sidebarTasks" aria-expanded="${
					expandedMenu === 'sidebarTasks'
				}">Tasks</a>
				<div id="sidebarTasks" class="collapse ${
					expandedMenu === 'sidebarTasks' ? 'show' : ''
				}"></div>
			</li>
			<li>
				<a data-bs-toggle="collapse" href="#sidebarBoards" aria-controls="sidebarBoards" aria-expanded="${
					expandedMenu === 'sidebarBoards'
				}">Boards</a>
				<div id="sidebarBoards" class="collapse ${
					expandedMenu === 'sidebarBoards' ? 'show' : ''
				}">
					<span class="decker-sidebar-board-badges">Status</span>
				</div>
			</li>
		</ul>
		<input type="checkbox" id="sidebar-board-status-check" name="sidebar-board-status">
	`;
}

describe( 'sidebar preferences', () => {
	beforeEach( () => {
		window.localStorage.clear();
		Object.defineProperty( window, 'innerWidth', {
			configurable: true,
			value: 1440,
		} );
		renderSidebar();
	} );

	test( 'stores and restores the expanded sidebar menu', () => {
		preferences.init();
		document
			.getElementById( 'sidebarBoards' )
			.dispatchEvent(
				new Event( 'shown.bs.collapse', { bubbles: true } )
			);

		expect(
			window.localStorage.getItem( preferences.EXPANDED_MENU_KEY )
		).toBe( 'sidebarBoards' );

		renderSidebar( 'sidebarTasks' );
		preferences.init();

		expect(
			document
				.getElementById( 'sidebarBoards' )
				.classList.contains( 'show' )
		).toBe( true );
		expect(
			document
				.getElementById( 'sidebarTasks' )
				.classList.contains( 'show' )
		).toBe( false );
		expect(
			document
				.querySelector( '[aria-controls="sidebarBoards"]' )
				.getAttribute( 'aria-expanded' )
		).toBe( 'true' );
	} );

	test( 'stores an explicitly closed sidebar state', () => {
		window.localStorage.setItem(
			preferences.EXPANDED_MENU_KEY,
			'sidebarBoards'
		);
		preferences.init();
		document
			.getElementById( 'sidebarBoards' )
			.dispatchEvent(
				new Event( 'hidden.bs.collapse', { bubbles: true } )
			);

		renderSidebar( 'sidebarTasks' );
		preferences.init();

		expect(
			document.querySelectorAll( '.side-nav .collapse.show' )
		).toHaveLength( 0 );
	} );

	test( 'persists the board status indicator visibility', () => {
		preferences.init();
		const toggle = document.getElementById( 'sidebar-board-status-check' );

		expect( toggle.checked ).toBe( true );

		toggle.checked = false;
		toggle.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		expect(
			window.localStorage.getItem( preferences.SHOW_BOARD_STATUS_KEY )
		).toBe( 'false' );
		expect(
			document.documentElement.classList.contains(
				'decker-hide-board-status'
			)
		).toBe( true );

		renderSidebar();
		preferences.init();

		expect(
			document.getElementById( 'sidebar-board-status-check' ).checked
		).toBe( false );
		expect(
			document.documentElement.classList.contains(
				'decker-hide-board-status'
			)
		).toBe( true );
	} );

	test( 'stores and restores the desktop sidebar size changed by the menu button', () => {
		const button = document.querySelector( '.button-toggle-menu' );
		button.addEventListener( 'click', function () {
			document.documentElement.setAttribute(
				'data-sidenav-size',
				'condensed'
			);
		} );
		preferences.init();

		button.click();

		expect(
			window.localStorage.getItem( preferences.SIDEBAR_SIZE_KEY )
		).toBe( 'condensed' );

		renderSidebar();
		preferences.init();

		expect(
			document.documentElement.getAttribute( 'data-sidenav-size' )
		).toBe( 'condensed' );
	} );
} );
