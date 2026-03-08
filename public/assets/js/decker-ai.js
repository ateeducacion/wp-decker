/* global Swal, wpApiSettings, deckerVars */

/**
 * Decker AI — "Improve with AI" integration for the Quill task-description editor.
 *
 * Exposed as window.DeckerAI.  Initialised from task-card.js after the
 * Quill instance is created.
 *
 * Supports two execution modes:
 *   1. Browser-native AI (Chrome Prompt API / window.ai.languageModel)
 *   2. Server-side fallback via the WordPress REST API endpoint
 *
 * Public API:
 *   DeckerAI.init( quill, context, deckerVars )
 */
window.DeckerAI = (function () {

    /** @type {import('quill').default|null} */
    var quillInstance = null;

    /** @type {Element|Document|null} */
    var deckerContext = null;

    /** @type {object|null} AI-specific config passed from PHP via deckerVars.ai */
    var aiConfig = null;

    /**
     * Selection range saved when the button is clicked (before focus is lost).
     * @type {{index:number,length:number}|null}
     */
    var savedRange = null;

    /**
     * Reference to the AI dropdown container element.
     * @type {Element|null}
     */
    var dropdownElement = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Initialise the AI module.
     *
     * @param {object} quill      Quill editor instance.
     * @param {Element} context   DOM context that contains the editor.
     * @param {object} vars       deckerVars object from PHP (contains vars.ai).
     */
    function init( quill, context, vars ) {
        if ( ! quill || ! context ) {
            return;
        }

        quillInstance = quill;
        deckerContext = context;
        aiConfig      = ( vars && vars.ai ) ? vars.ai : null;

        if ( ! aiConfig || ! aiConfig.enabled ) {
            return;
        }

        addToolbarButton();
    }

    // -------------------------------------------------------------------------
    // Browser AI detection
    // -------------------------------------------------------------------------

    /**
     * Check whether the browser exposes a built-in language model (Prompt API).
     *
     * @returns {boolean} True when window.ai.languageModel is available.
     */
    function hasBrowserAI() {
        return (
            typeof window.ai !== 'undefined' &&
            window.ai !== null &&
            typeof window.ai.languageModel !== 'undefined' &&
            window.ai.languageModel !== null
        );
    }

    /**
     * Determine which AI provider to use.
     *
     * @returns {string} 'browser', 'server', or 'none'.
     */
    function getProvider() {
        if ( hasBrowserAI() ) {
            return 'browser';
        }
        if ( aiConfig && aiConfig.server_available ) {
            return 'server';
        }
        return 'none';
    }

    // -------------------------------------------------------------------------
    // Toolbar button
    // -------------------------------------------------------------------------

    /**
     * Append an "Improve with AI" button to the Quill toolbar.
     */
    function addToolbarButton() {
        var toolbar = deckerContext.querySelector( '.ql-toolbar' );
        if ( ! toolbar ) {
            return;
        }

        // Guard against duplicate buttons when init() is called more than once.
        if ( toolbar.querySelector( '.ql-ai-improve' ) ) {
            return;
        }

        var modes = getModes();
        var btn = document.createElement( 'button' );
        btn.type      = 'button';
        btn.className = 'ql-ai-improve';
        btn.title     = aiConfig.strings.improve_with_ai;
        btn.setAttribute( 'aria-haspopup', 'true' );
        btn.setAttribute( 'aria-expanded', 'false' );
        btn.innerHTML =
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14" fill="currentColor" aria-hidden="true">' +
            '<path d="M7.657 6.247c.11-.33.576-.33.686 0l.645 1.937a2.89 2.89 0 0 0 1.829 1.828l1.936.645c.33.11.33.576 0 .686l-1.937.645a2.89 2.89 0 0 0-1.828 1.829l-.645 1.936a.361.361 0 0 1-.686 0l-.645-1.937a2.89 2.89 0 0 0-1.828-1.828l-1.937-.645a.361.361 0 0 1 0-.686l1.937-.645a2.89 2.89 0 0 0 1.828-1.828l.645-1.937zM3.794 1.148a.217.217 0 0 1 .412 0l.387 1.162c.173.518.579.924 1.097 1.097l1.162.387a.217.217 0 0 1 0 .412l-1.162.387A1.734 1.734 0 0 0 4.593 5.69l-.387 1.162a.217.217 0 0 1-.412 0L3.407 5.69A1.734 1.734 0 0 0 2.31 4.593l-1.162-.387a.217.217 0 0 1 0-.412l1.162-.387A1.734 1.734 0 0 0 3.407 2.31l.387-1.162zM10.863.099a.145.145 0 0 1 .274 0l.258.774c.115.346.386.617.732.732l.774.258a.145.145 0 0 1 0 .274l-.774.258a1.156 1.156 0 0 0-.732.732l-.258.774a.145.145 0 0 1-.274 0l-.258-.774a1.156 1.156 0 0 0-.732-.732L9.1 2.137a.145.145 0 0 1 0-.274l.774-.258c.346-.115.617-.386.732-.732L10.863.1z"/>' +
            '</svg> ' +
            '<span>' + escapeHtml( aiConfig.strings.improve_with_ai ) + '</span>' +
            '<span class="decker-ai-caret" aria-hidden="true">▾</span>';

        var menu = document.createElement( 'div' );
        menu.className = 'decker-ai-dropdown-menu';

        modes.forEach( function ( mode ) {
            var item = document.createElement( 'button' );
            item.type = 'button';
            item.className = 'decker-ai-dropdown-item';
            item.setAttribute( 'data-mode', mode.key );
            item.textContent = mode.label;
            item.addEventListener( 'mousedown', saveCurrentRange );
            item.addEventListener( 'click', function ( event ) {
                event.preventDefault();
                event.stopPropagation();
                closeDropdown();
                runImproveFlow( mode.key );
            } );
            menu.appendChild( item );
        } );

        btn.addEventListener( 'mousedown', saveCurrentRange );
        btn.addEventListener( 'click', function ( event ) {
            event.preventDefault();
            event.stopPropagation();
            toggleDropdown();
        } );

        var span = document.createElement( 'span' );
        span.className = 'ql-formats decker-ai-dropdown';
        span.appendChild( btn );
        span.appendChild( menu );
        toolbar.appendChild( span );

        dropdownElement = span;
        document.addEventListener( 'click', handleDocumentClick );
        document.addEventListener( 'keydown', handleDocumentKeydown );
    }

    // -------------------------------------------------------------------------
    // Toolbar dropdown helpers
    // -------------------------------------------------------------------------

    /**
     * Get the supported AI actions.
     *
     * @returns {Array<{key: string, label: string}>} Available actions.
     */
    function getModes() {
        return [
            { key: 'improve',         label: aiConfig.strings.mode_improve },
            { key: 'shorten',         label: aiConfig.strings.mode_shorten },
            { key: 'clarify',         label: aiConfig.strings.mode_clarify },
            { key: 'professionalize', label: aiConfig.strings.mode_professionalize },
            { key: 'proofread',       label: aiConfig.strings.mode_proofread },
        ];
    }

    /**
     * Save the current Quill selection before focus changes.
     */
    function saveCurrentRange() {
        savedRange = quillInstance.getSelection();
    }

    /**
     * Toggle the AI dropdown.
     */
    function toggleDropdown() {
        if ( ! dropdownElement ) {
            return;
        }

        if ( dropdownElement.classList.contains( 'is-open' ) ) {
            closeDropdown();
            return;
        }

        dropdownElement.classList.add( 'is-open' );
        dropdownElement.querySelector( '.ql-ai-improve' ).setAttribute( 'aria-expanded', 'true' );
    }

    /**
     * Close the AI dropdown.
     */
    function closeDropdown() {
        if ( ! dropdownElement ) {
            return;
        }

        dropdownElement.classList.remove( 'is-open' );
        dropdownElement.querySelector( '.ql-ai-improve' ).setAttribute( 'aria-expanded', 'false' );
    }

    /**
     * Close the dropdown when clicking outside it.
     *
     * @param {MouseEvent} event Native click event.
     */
    function handleDocumentClick( event ) {
        if ( dropdownElement && ! dropdownElement.contains( event.target ) ) {
            closeDropdown();
        }
    }

    /**
     * Close the dropdown on Escape.
     *
     * @param {KeyboardEvent} event Native keyboard event.
     */
    function handleDocumentKeydown( event ) {
        if ( 'Escape' === event.key ) {
            closeDropdown();
        }
    }

    // -------------------------------------------------------------------------
    // Main improve flow
    // -------------------------------------------------------------------------

    /**
     * Run the AI improvement flow for the selected action.
     *
     * @param {string} mode Rewrite mode key.
     */
    function runImproveFlow( mode ) {
        var provider = getProvider();

        if ( provider === 'none' ) {
            Swal.fire( {
                icon:  'warning',
                title: aiConfig.strings.error,
                text:  aiConfig.strings.no_ai_available,
            } );
            return;
        }

        var textToImprove;
        var isSelection;

        if ( savedRange && savedRange.length > 0 ) {
            textToImprove = getSelectionHtml( savedRange );
            isSelection   = true;
        } else {
            textToImprove = quillInstance.root.innerHTML;
            isSelection   = false;
        }

        var textContent = isSelection
            ? quillInstance.getText( savedRange.index, savedRange.length )
            : quillInstance.getText();
        if ( ! textContent.trim() ) {
            Swal.fire( {
                icon:  'warning',
                title: aiConfig.strings.no_content,
                text:  aiConfig.strings.no_content_message,
            } );
            return;
        }

        Swal.fire( {
            title:              aiConfig.strings.improving,
            allowOutsideClick:  false,
            showConfirmButton:  false,
            didOpen: function () {
                Swal.showLoading();
            },
        } );

        improveText( textToImprove, mode, provider ).then( function ( result ) {
            if ( ! result.success ) {
                Swal.fire( {
                    icon:  'error',
                    title: aiConfig.strings.error,
                    text:  result.error,
                } );
                return;
            }

            var originalPreview = isSelection
                ? quillInstance.getText( savedRange.index, savedRange.length )
                : quillInstance.getText();

            showPreview( originalPreview, result.improved_text ).then( function ( confirmed ) {
                if ( confirmed ) {
                    applyImprovement( result.improved_text, isSelection ? savedRange : null );
                }
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Step 2 — AI text improvement (dispatcher)
    // -------------------------------------------------------------------------

    /**
     * Route the improvement request to the appropriate provider.
     *
     * @param {string} text     Text (HTML) to improve.
     * @param {string} mode     Rewrite mode key.
     * @param {string} provider 'browser' or 'server'.
     * @returns {Promise<{success:boolean, improved_text?:string, error?:string}>}
     */
    function improveText( text, mode, provider ) {
        if ( provider === 'browser' ) {
            return callBrowserAI( text, mode );
        }
        return callServerAI( text, mode );
    }

    // -------------------------------------------------------------------------
    // Step 2a — Browser-native AI (Prompt API)
    // -------------------------------------------------------------------------

    /**
     * Build a prompt string for the given rewrite mode.
     *
     * @param {string} mode Rewrite mode key.
     * @param {string} text HTML content.
     * @returns {string} Full prompt.
     */
    function buildPrompt( mode, text ) {
        var prefixes = {
            improve:         aiConfig.prompts.improve,
            shorten:         aiConfig.prompts.shorten,
            clarify:         aiConfig.prompts.clarify,
            professionalize: aiConfig.prompts.professionalize,
            proofread:       aiConfig.prompts.proofread,
        };

        var prefix = prefixes[ mode ] || prefixes.improve;
        return prefix + ' ' + aiConfig.prompts.language_instruction + ' ' +
            aiConfig.prompts.response_format + '\n\n' + text;
    }

    /**
     * Use the browser-native Prompt API (window.ai.languageModel) to improve text.
     *
     * @param {string} text Text (HTML) to improve.
     * @param {string} mode Rewrite mode key.
     * @returns {Promise<{success:boolean, improved_text?:string, error?:string}>}
     */
    function callBrowserAI( text, mode ) {
        var prompt = buildPrompt( mode, text );

        return window.ai.languageModel.create().then( function ( session ) {
            return session.prompt( prompt ).then( function ( response ) {
                session.destroy();
                var cleaned = sanitizeBrowserResponse( response );
                if ( ! cleaned ) {
                    return { success: false, error: aiConfig.strings.error_message };
                }
                return { success: true, improved_text: cleaned };
            } );
        } ).catch( function ( err ) {
            return {
                success: false,
                error:   aiConfig.strings.error_message + ( err.message ? ' (' + err.message + ')' : '' ),
            };
        } );
    }

    /**
     * Strip markdown code fences and leading/trailing whitespace from browser AI response.
     *
     * @param {string} content Raw response from AI.
     * @returns {string} Cleaned HTML.
     */
    function sanitizeBrowserResponse( content ) {
        if ( ! content ) {
            return '';
        }
        content = content.trim();
        // Remove opening fence with optional language label.
        content = content.replace( /^```[a-z]*\s*/i, '' );
        // Remove closing fence.
        content = content.replace( /\s*```\s*$/i, '' );
        return content.trim();
    }

    // -------------------------------------------------------------------------
    // Step 2b — Server-side AI (REST API)
    // -------------------------------------------------------------------------

    /**
     * Call the Decker REST endpoint to improve the text.
     *
     * @param {string} text Text (HTML) to improve.
     * @param {string} mode Rewrite mode key.
     * @returns {Promise<{success:boolean, improved_text?:string, error?:string}>}
     */
    function callServerAI( text, mode ) {
        return fetch(
            wpApiSettings.root + 'decker/v1/ai/improve',
            {
                method:  'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   wpApiSettings.nonce,
                },
                body: JSON.stringify( { text: text, mode: mode } ),
            }
        ).then( function ( response ) {
            return response.json().then( function ( data ) {
                if ( response.ok ) {
                    return { success: true, improved_text: data.improved_text };
                }
                var errorMsg = ( data && data.message )
                    ? data.message
                    : aiConfig.strings.error_message;
                return { success: false, error: errorMsg };
            } );
        } ).catch( function ( err ) {
            return {
                success: false,
                error:   aiConfig.strings.error_message + ( err.message ? ' (' + err.message + ')' : '' ),
            };
        } );
    }

    // -------------------------------------------------------------------------
    // Step 3 — Preview modal
    // -------------------------------------------------------------------------

    /**
     * Show a before/after preview and ask the user to confirm.
     *
     * @param {string} original  Plain-text original.
     * @param {string} improved  Improved HTML from AI.
     * @returns {Promise<boolean>} True if user accepted the replacement.
     */
    function showPreview( original, improved ) {
        var html =
            '<div class="decker-ai-preview">' +
                '<div class="mb-3">' +
                    '<h6 class="text-muted mb-1">' + escapeHtml( aiConfig.strings.original_text ) + '</h6>' +
                    '<div class="decker-ai-text-box">' + escapeHtml( original ) + '</div>' +
                '</div>' +
                '<div>' +
                    '<h6 class="text-success mb-1">' + escapeHtml( aiConfig.strings.improved_text ) + '</h6>' +
                    '<div class="decker-ai-text-box improved">' + improved + '</div>' +
                '</div>' +
            '</div>';

        return Swal.fire( {
            title:             aiConfig.strings.preview_title,
            html:              html,
            confirmButtonText: aiConfig.strings.accept,
            cancelButtonText:  aiConfig.strings.cancel,
            showCancelButton:  true,
            width:             '700px',
            customClass: {
                popup: 'decker-ai-swal',
            },
        } ).then( function ( result ) {
            return result.isConfirmed;
        } );
    }

    // -------------------------------------------------------------------------
    // Step 4 — Apply improvement
    // -------------------------------------------------------------------------

    /**
     * Replace the selected range (or full content) with the improved HTML.
     *
     * @param {string}                       improvedHtml HTML returned by AI.
     * @param {{index:number,length:number}|null} range   Selection range, or null for full replacement.
     */
    function applyImprovement( improvedHtml, range ) {
        if ( range && range.length > 0 ) {
            // Delete selection and paste improved HTML at that position.
            quillInstance.deleteText( range.index, range.length, 'api' );
            quillInstance.clipboard.dangerouslyPasteHTML( range.index, improvedHtml, 'api' );
        } else {
            // Full-content replacement.
            quillInstance.clipboard.dangerouslyPasteHTML( 0, improvedHtml, 'api' );
        }

        // Flag unsaved changes so task-card.js shows the save prompt.
        if ( typeof window.deckerHasUnsavedChanges !== 'undefined' ) {
            window.deckerHasUnsavedChanges = true;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the HTML of a Quill selection range.
     *
     * Uses getSemanticHTML() (Quill 2.x) when available, falling back to
     * plain text for older versions.
     *
     * @param {{index:number,length:number}} range Selection range.
     * @returns {string} HTML string.
     */
    function getSelectionHtml( range ) {
        if ( typeof quillInstance.getSemanticHTML === 'function' ) {
            return quillInstance.getSemanticHTML( range.index, range.length );
        }
        // Fallback: plain text wrapped in a paragraph.
        return '<p>' + escapeHtml( quillInstance.getText( range.index, range.length ) ) + '</p>';
    }

    /**
     * Escape a string for safe insertion into HTML text nodes.
     *
     * @param {string} str Raw string.
     * @returns {string} HTML-escaped string.
     */
    function escapeHtml( str ) {
        if ( ! str ) {
            return '';
        }
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    /**
     * Escape a string for safe use in an HTML attribute value.
     *
     * @param {string} str Raw string.
     * @returns {string} Attribute-safe string.
     */
    function escapeAttr( str ) {
        if ( ! str ) {
            return '';
        }
        return String( str ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
    }

    // -------------------------------------------------------------------------
    // Module exports
    // -------------------------------------------------------------------------

    return {
        init: init,
    };

}());
