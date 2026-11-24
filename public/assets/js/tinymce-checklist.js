/**
 * Decker checklist support for the WordPress classic (TinyMCE) task editor.
 *
 * Stores checklist state on li[data-list="checked|unchecked"].
 * Ordinary and nested lists keep independent states.
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
	 * @return {boolean} True only for a known checklist state.
	 */
	function isChecklistItem( li ) {
		return !! ( li && 'LI' === li.nodeName && li.getAttribute && ( CHECKED === li.getAttribute( ATTR ) || UNCHECKED === li.getAttribute( ATTR ) ) );
	}

	/**
	 * Whether a list element contains at least one checklist item.
	 *
	 * @param {Element} list A <ul> or <ol> element.
	 * @return {boolean} True when any child is a checklist item.
	 */
	function listHasChecklist( list ) {
		return !! ( list && Array.prototype.some.call( list.children, isChecklistItem ) );
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
				if ( ! isChecklistItem( li ) ) {
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
		if ( ! canEdit( editor ) ) return;
		if ( editor.selection.getEnd && editor.dom.getParent( editor.selection.getStart(), 'ul,ol' ) !== editor.dom.getParent( editor.selection.getEnd(), 'ul,ol' ) ) return;
		editor.undoManager.transact( function() {
			var list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );
			var existing = null;

			// Outside a list: create one first.
			if ( ! list ) {
				existing = new Set(editor.getBody ? editor.getBody().querySelectorAll('li') : []);
				editor.deckerCreatingChecklist = true;
				try { editor.execCommand( 'InsertUnorderedList' ); }
				finally { editor.deckerCreatingChecklist = false; }
				list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );
			}

			if ( ! list ) {
				return;
			}

			var enable = existing ? true : ! listHasChecklist( list );
			if ( enable && list.nodeName === 'OL' ) {
				var ul = list.ownerDocument.createElement( 'ul' );
				Array.prototype.forEach.call( list.attributes, function( attribute ) {
					if ( [ 'start', 'type', 'reversed' ].indexOf( attribute.name ) === -1 ) ul.setAttribute( attribute.name, attribute.value );
				} );
				while ( list.firstChild ) ul.appendChild( list.firstChild );
				list.parentNode.replaceChild( ul, list );
				list = ul;
			}
			if (existing) {
				Array.prototype.forEach.call(list.children, function(li) {
					if (li.nodeName === 'LI' && !existing.has(li)) li.setAttribute(ATTR, UNCHECKED);
				});
			} else {
				setListChecklist( list, enable );
			}
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
		if ( ! canEdit( editor ) ) return;
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
	function handleEnter( editor, previousItem ) {
		if ( ! canEdit( editor ) ) return;
		var li = getCaretListItem( editor );
		var previous;

		if ( ! li ) {
			return;
		}

        if ( previousItem ) {
            if (previousItem.items && previousItem.items.has(li)) return;
            if ( li !== previousItem.node && isChecklistItem( previousItem.node ) ) {
                li.setAttribute( ATTR, UNCHECKED );
                previousItem.node.setAttribute( ATTR, previousItem.state );
                notifyChange( editor );
            }
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
		if ( ! canEdit( editor ) || editor.deckerCreatingChecklist ) return;
		if ( 'InsertUnorderedList' !== event.command && 'InsertOrderedList' !== event.command ) {
			return;
		}

		var list = editor.dom.getParent( editor.selection.getStart(), 'ul,ol' );
		if ( list && listHasChecklist( list ) ) {
			setListChecklist( list, false );
		}
	}

	/** Whether this editor currently permits mutations, including a lost lock. */
	function canEdit( editor ) {
		var body = editor.getBody && editor.getBody();
		return !editor.readonly && !(editor.settings && editor.settings.readonly) &&
			(!editor.getBody || (body && body.getAttribute('contenteditable') !== 'false'));
	}

	/** Keyboard/toolbar equivalent of clicking the current item's marker. */
	function toggleCaretItem( editor ) {
		if ( !canEdit(editor) ) return;
		var li = getCaretListItem(editor);
		if ( !isChecklistItem(li) ) return;
		editor.undoManager.transact(function() { toggleItemState(li); });
		notifyChange(editor);
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

		var itemLabel = (window.deckerVars && window.deckerVars.strings && window.deckerVars.strings.toggle_checklist_item) || 'Toggle checklist item';
		if (editor.addButton) {
			editor.addButton('decker_check_item', {
				text: '✓', icon: false, tooltip: itemLabel,
				onclick: function() { toggleCaretItem(editor); },
				onPostRender: function() {
					var button = this;
					editor.on('NodeChange', function() {
						var li = getCaretListItem(editor);
						var checked = isChecklistItem(li) && li.getAttribute(ATTR) === CHECKED;
						button.active(checked);
						button.disabled(!canEdit(editor) || !isChecklistItem(li));
						button.getEl().setAttribute('aria-pressed', checked ? 'true' : 'false');
					});
				}
			});
		}
		if (editor.addShortcut) {
			editor.addShortcut('meta+shift+32', itemLabel, function() { toggleCaretItem(editor); });
		}
		var beforeEnter = null;
		editor.on('BeforeAddUndo', function(event) {
			if (beforeEnter && getCaretListItem(editor) !== beforeEnter.node) {
				handleEnter(editor, beforeEnter);
				// Core captures the split before keyup: include the new pending state.
				event.level.content = editor.getContent({format: 'raw'});
			}
		});
		editor.on('keydown', function(event) {
			beforeEnter = null;
			if (event.keyCode === 13 && !event.shiftKey && canEdit(editor)) {
				var li = getCaretListItem(editor);
				if (isChecklistItem(li)) beforeEnter = {node: li, state: li.getAttribute(ATTR), items: editor.getBody ? new Set(editor.getBody().querySelectorAll('li')) : null};
			}
		}, true);
		editor.on('input', function() {
			if (beforeEnter) handleEnter(editor, beforeEnter);
		});
		editor.on( 'click', function( event ) {
			handleClick( editor, event );
		} );

		editor.on( 'keyup', function( event ) {
			if ( 13 === event.keyCode ) {
				handleEnter( editor, beforeEnter );
				beforeEnter = null;
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
