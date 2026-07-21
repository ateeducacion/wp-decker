/**
 * Decker checklist support for the WordPress classic (TinyMCE) task editor.
 *
 * Renders and edits checkable checklists using the exact markup Quill stores
 * (<li data-list="checked|unchecked">), so task descriptions stay compatible
 * with both editors during the transition period. There is no format
 * conversion: the editor DOM is exactly what gets saved.
 *
 * The checkboxes themselves are drawn with CSS (::before glyphs) from the
 * content_style passed to the editor in task-card.js; this file only manages
 * the data-list attributes and the toolbar button.
 *
 * @package Decker
 */

( function() {
	'use strict';

	var CHECKED   = 'checked';
	var UNCHECKED = 'unchecked';
	var ATTR      = 'data-list';

	// Approximate width (px) of the CSS-drawn checkbox marker. Clicks between
	// the item's padding edge and this offset toggle the checked state.
	var MARKER_WIDTH = 24;

	/**
	 * Whether the given node is a checklist item.
	 *
	 * @param {Node} li Candidate node.
	 * @return {boolean} True when it is an <li> with a data-list attribute.
	 */
	function isChecklistItem( li ) {
		return !! ( li && 'LI' === li.nodeName && li.hasAttribute && li.hasAttribute( ATTR ) );
	}

	/**
	 * Whether a list element contains at least one checklist item.
	 *
	 * @param {Element} list A <ul> or <ol> element.
	 * @return {boolean} True when any child is a checklist item.
	 */
	function listHasChecklist( list ) {
		return !! ( list && list.querySelector( 'li[' + ATTR + ']' ) );
	}

	/**
	 * Flip a checklist item between checked and unchecked.
	 *
	 * @param {Element} li The checklist <li>.
	 */
	function toggleItemState( li ) {
		if ( ! isChecklistItem( li ) ) {
			return;
		}
		li.setAttribute( ATTR, CHECKED === li.getAttribute( ATTR ) ? UNCHECKED : CHECKED );
	}

	/**
	 * Turn a whole list into a checklist, or back into a plain list.
	 *
	 * @param {Element} list   A <ul> or <ol> element.
	 * @param {boolean} enable True to add checklist state, false to remove it.
	 */
	function setListChecklist( list, enable ) {
		if ( ! list ) {
			return;
		}
		Array.prototype.forEach.call( list.children, function( li ) {
			if ( 'LI' !== li.nodeName ) {
				return;
			}
			if ( enable ) {
				// Preserve an existing checked state when re-enabling.
				if ( ! li.hasAttribute( ATTR ) ) {
					li.setAttribute( ATTR, UNCHECKED );
				}
			} else {
				li.removeAttribute( ATTR );
			}
		} );
	}

	/**
	 * Get the list item that currently holds the caret.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 * @return {Element|null} The <li> under the caret, if any.
	 */
	function getCaretListItem( editor ) {
		return editor.dom.getParent( editor.selection.getStart(), 'li' );
	}

	/**
	 * Notify the editor that content changed so dirty tracking and undo work.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 */
	function notifyChange( editor ) {
		editor.nodeChanged();
		editor.fire( 'change' );
	}

	/**
	 * Toolbar action: toggle the current list (creating one if needed)
	 * between checklist and plain list.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 */
	function toggleChecklist( editor ) {
		editor.undoManager.transact( function() {
			var list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );

			// Outside a list: create one first.
			if ( ! list ) {
				editor.execCommand( 'InsertUnorderedList' );
				list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );
			}

			if ( ! list ) {
				return;
			}

			setListChecklist( list, ! listHasChecklist( list ) );
			notifyChange( editor );
		} );
	}

	/**
	 * Click handler: toggle an item when the CSS marker area is clicked.
	 *
	 * Clicks on the item text land far from the padding edge and are ignored.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 * @param {Event}  event  The click event.
	 */
	function handleClick( editor, event ) {
		var li = event.target;

		if ( ! isChecklistItem( li ) ) {
			return;
		}

		if ( event.offsetX < 0 || event.offsetX > MARKER_WIDTH ) {
			return;
		}

		event.preventDefault();
		editor.undoManager.transact( function() {
			toggleItemState( li );
		} );
		notifyChange( editor );
	}

	/**
	 * Enter handler: a freshly created (empty) checklist item never starts
	 * checked, whether TinyMCE cloned the data-list attribute or dropped it.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 */
	function handleEnter( editor ) {
		var li = getCaretListItem( editor );
		var previous;

		if ( ! li ) {
			return;
		}

		var isEmpty = '' === li.textContent.replace( /\u200B/g, '' ).trim();
		if ( ! isEmpty ) {
			return;
		}

		if ( isChecklistItem( li ) ) {
			if ( CHECKED === li.getAttribute( ATTR ) ) {
				li.setAttribute( ATTR, UNCHECKED );
			}
			return;
		}

		// Attribute not cloned: inherit checklist membership from the sibling.
		previous = li.previousElementSibling;
		if ( isChecklistItem( previous ) ) {
			li.setAttribute( ATTR, UNCHECKED );
		}
	}

	/**
	 * ExecCommand handler: switching a checklist to a plain ordered or
	 * unordered list through the native buttons strips the checklist state.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 * @param {Object} event  The ExecCommand event.
	 */
	function handleExecCommand( editor, event ) {
		if ( 'InsertUnorderedList' !== event.command && 'InsertOrderedList' !== event.command ) {
			return;
		}

		var list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );
		if ( list && listHasChecklist( list ) ) {
			setListChecklist( list, false );
		}
	}

	/**
	 * Attach checklist behavior to a TinyMCE editor instance.
	 *
	 * Called from the editor's setup callback in task-card.js so the default
	 * WordPress plugin list stays untouched.
	 *
	 * @param {Object} editor TinyMCE editor instance.
	 */
	function attach( editor ) {
		if ( ! editor ) {
			return;
		}

		var tooltip = ( window.deckerVars && window.deckerVars.strings && window.deckerVars.strings.checklist ) || 'Checklist';

		if ( editor.addButton ) {
			// TinyMCE 4 (bundled with WordPress).
			editor.addButton( 'decker_checklist', {
				text: '☑',
				icon: false,
				tooltip: tooltip,
				onclick: function() {
					toggleChecklist( editor );
				},
				onPostRender: function() {
					var button = this;
					editor.on( 'NodeChange', function() {
						button.active( isChecklistItem( getCaretListItem( editor ) ) );
					} );
				},
			} );
		} else if ( editor.ui && editor.ui.registry ) {
			// TinyMCE 5+.
			editor.ui.registry.addToggleButton( 'decker_checklist', {
				text: '☑',
				tooltip: tooltip,
				onAction: function() {
					toggleChecklist( editor );
				},
				onSetup: function( api ) {
					var handler = function() {
						api.setActive( isChecklistItem( getCaretListItem( editor ) ) );
					};
					editor.on( 'NodeChange', handler );
					return function() {
						editor.off( 'NodeChange', handler );
					};
				},
			} );
		}

		editor.on( 'click', function( event ) {
			handleClick( editor, event );
		} );

		editor.on( 'keyup', function( event ) {
			if ( 13 === event.keyCode ) {
				handleEnter( editor );
			}
		} );

		editor.on( 'ExecCommand', function( event ) {
			handleExecCommand( editor, event );
		} );
	}

	window.DeckerChecklist = {
		attach: attach,
		isChecklistItem: isChecklistItem,
		listHasChecklist: listHasChecklist,
		toggleItemState: toggleItemState,
		setListChecklist: setListChecklist,
		toggleChecklist: toggleChecklist,
	};
}() );
