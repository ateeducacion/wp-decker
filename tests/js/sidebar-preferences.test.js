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

	test( 'does not touch the board status indicators, a site-wide setting', () => {
		preferences.init();

		expect( preferences.SHOW_BOARD_STATUS_KEY ).toBeUndefined();
		expect( Object.keys( window.localStorage ) ).not.toContain(
			'decker.sidebar.showBoardStatus'
		);
		expect(
			document.documentElement.classList.contains(
				'decker-hide-board-status'
			)
		).toBe( false );
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
