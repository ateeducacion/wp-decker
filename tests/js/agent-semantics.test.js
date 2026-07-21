/**
 * Unit tests for public/assets/js/agent-semantics.js.
 *
 * Verifies the progressive enhancements are additive and idempotent: nodes are
 * never replaced, ARIA roles are correct, and enhance() accepts any root.
 *
 * @package Decker
 */

/* eslint-disable no-undef */

const path = require( 'path' );

// Load the browser IIFE against the jsdom globals; it attaches window.DeckerAgentSemantics.
require( path.resolve( __dirname, '../../public/assets/js/agent-semantics.js' ) );
const { enhance } = window.DeckerAgentSemantics;

function setBoard() {
	document.body.innerHTML = `
		<input id="searchInput" placeholder="Search">
		<select id="boardUserFilter" aria-label="Filter by user"><option>All</option></select>
		<i data-bs-toggle="popover" data-bs-content="Board description" class="ri-information-line ms-1"></i>
		<div class="board">
			<div class="tasks" data-plugin="dragula">
				<h5 class="task-header">TO-DO</h5>
				<div id="task-list-to-do" class="task-list-items">
					<div class="card">A</div>
					<div class="card">B</div>
				</div>
			</div>
		</div>
		<div class="alert alert-success">Saved</div>
		<div class="alert alert-danger">Failed</div>
	`;
}

describe( 'agent-semantics enhance', () => {
	beforeEach( setBoard );

	test( 'labels controls in place without replacing them', () => {
		const before = document.getElementById( 'searchInput' );
		enhance( document );
		expect( document.getElementById( 'searchInput' ) ).toBe( before );
		expect( before.getAttribute( 'autocomplete' ) ).toBe( 'off' );
		expect( document.querySelector( 'label[for="searchInput"]' ) ).not.toBeNull();
	} );

	test( 'makes an icon popover trigger accessible without replacing it', () => {
		const icon = document.querySelector( 'i[data-bs-toggle="popover"]' );
		enhance( document );
		// The same <i> element remains, so its Bootstrap popover instance survives.
		expect( document.querySelector( 'i[data-bs-toggle="popover"]' ) ).toBe( icon );
		expect( icon.tagName ).toBe( 'I' );
		expect( icon.getAttribute( 'role' ) ).toBe( 'button' );
		expect( icon.getAttribute( 'tabindex' ) ).toBe( '0' );
		expect( icon.getAttribute( 'aria-label' ) ).toBe( 'Board description' );
	} );

	test( 'labels the task region and applies list semantics without replacing the container', () => {
		const tasks = document.querySelector( '.board > .tasks' );
		enhance( document );
		expect( document.querySelector( '.board > .tasks' ) ).toBe( tasks );
		expect( tasks.tagName ).toBe( 'DIV' );
		expect( tasks.getAttribute( 'role' ) ).toBe( 'group' );

		const heading = tasks.querySelector( '.task-header' );
		expect( heading.id ).not.toBe( '' );
		expect( tasks.getAttribute( 'aria-labelledby' ) ).toBe( heading.id );

		const list = document.getElementById( 'task-list-to-do' );
		expect( list.getAttribute( 'role' ) ).toBe( 'list' );
		list.querySelectorAll( '.card' ).forEach( ( card ) => {
			expect( card.getAttribute( 'role' ) ).toBe( 'listitem' );
		} );
	} );

	test( 'uses an assertive role for errors and a polite status otherwise', () => {
		enhance( document );
		expect( document.querySelector( '.alert-danger' ).getAttribute( 'role' ) ).toBe( 'alert' );
		expect( document.querySelector( '.alert-danger' ).hasAttribute( 'aria-live' ) ).toBe( false );

		const success = document.querySelector( '.alert-success' );
		expect( success.getAttribute( 'role' ) ).toBe( 'status' );
		expect( success.getAttribute( 'aria-live' ) ).toBe( 'polite' );
	} );

	test( 'is idempotent when run repeatedly', () => {
		enhance( document );
		enhance( document );
		expect( document.querySelectorAll( 'label[for="searchInput"]' ).length ).toBe( 1 );
	} );

	test( 'accepts an element root, not only document', () => {
		expect( () => enhance( document.body ) ).not.toThrow();
		expect( document.querySelector( '.board > .tasks' ).getAttribute( 'role' ) ).toBe( 'group' );
	} );
} );
