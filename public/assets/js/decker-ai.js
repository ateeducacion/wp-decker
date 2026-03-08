/* global Swal, deckerVars */

/**
 * Decker AI — browser-only "Improve with AI" integration for the Quill
 * task-description editor.
 *
 * Exposed as window.DeckerAI. Initialised from task-card.js after the
 * Quill instance is created.
 *
 * Uses only built-in browser AI capabilities when available. No server-side
 * fallback or remote providers are used.
 */
window.DeckerAI = (function () {

    /**
     * Browser AI service wrapper.
     *
     * Prefers the current documented Prompt API surface (`globalThis.LanguageModel`)
     * and keeps legacy experimental checks isolated as defensive compatibility
     * fallbacks only.
     *
     * @param {object} config Localized AI configuration.
     */
    function NativeBrowserAIService( config ) {
        this.config = config || {};
    }

    /**
     * Check whether a browser AI API surface is present.
     *
     * @returns {boolean} True when a browser AI API is exposed.
     */
    NativeBrowserAIService.prototype.isSupported = function () {
        return this.getApiHandle() !== null;
    };

    /**
     * Detect browser AI availability.
     *
     * Distinguishes between:
     * - API available and usable
     * - API present but model not ready
     * - Compatible browser where the feature is disabled or unavailable
     * - Unsupported browser
     *
     * @returns {Promise<object>} Availability details.
     */
    NativeBrowserAIService.prototype.getAvailability = async function () {
        var apiHandle    = this.getApiHandle();
        var browserInfo  = this.getBrowserInfo();
        var availability = {
            usable:        false,
            state:         'unsupported',
            browser:       browserInfo.id,
            browser_name:  browserInfo.name,
            help_url:      this.getHelpUrl( browserInfo ),
            api_variant:   apiHandle ? apiHandle.variant : '',
            raw:           '',
        };

        if ( ! apiHandle ) {
            if ( browserInfo.id === 'chrome' || browserInfo.id === 'edge' ) {
                availability.state = 'feature_disabled';
            }

            availability.reason = this.getUnavailableReason( availability );
            return availability;
        }

        if ( typeof apiHandle.api.availability === 'function' ) {
            try {
                availability.raw = await apiHandle.api.availability();
            } catch ( error ) {
                availability.error = error && error.message ? error.message : '';
            }
        }

        availability.state  = this.resolveAvailabilityState( availability.raw, browserInfo, apiHandle );
        availability.usable = availability.state === 'available';
        availability.reason = this.getUnavailableReason( availability );

        return availability;
    };

    /**
     * Create a browser AI session.
     *
     * This should be called from a real user interaction when possible.
     *
     * @param {object} [options] Session options.
     * @returns {Promise<object>} Prompt API session object.
     */
    NativeBrowserAIService.prototype.createSession = async function ( options ) {
        var apiHandle = this.getApiHandle();
        if ( ! apiHandle || typeof apiHandle.api.create !== 'function' ) {
            throw new Error( this.getString( 'ai_session_error' ) );
        }

        if ( ! options || true !== options.skipAvailabilityCheck ) {
            var availability = await this.getAvailability();
            if ( ! availability.usable ) {
                throw new Error( availability.reason );
            }
        }

        return apiHandle.api.create( this.getSessionOptions( options ) );
    };

    /**
     * Prompt the browser AI model.
     *
     * @param {string} text Prompt text.
     * @param {object} [options] Prompt options.
     * @returns {Promise<string>} AI response.
     */
    NativeBrowserAIService.prototype.prompt = async function ( text, options ) {
        var session = options && options.session
            ? options.session
            : await this.createSession( options );

        try {
            return this.sanitizeResponse( await session.prompt( text ) );
        } finally {
            if ( session && typeof session.destroy === 'function' ) {
                session.destroy();
            }
        }
    };

    /**
     * Get the setup/help URL for the current browser family.
     *
     * @param {object} [browserInfo] Browser information.
     * @returns {string} Help URL or empty string.
     */
    NativeBrowserAIService.prototype.getHelpUrl = function ( browserInfo ) {
        var browser = browserInfo || this.getBrowserInfo();

        if ( browser.id === 'chrome' ) {
            return 'https://developer.chrome.com/docs/ai/prompt-api';
        }

        if ( browser.id === 'edge' ) {
            return 'https://learn.microsoft.com/en-us/microsoft-edge/web-platform/prompt-api';
        }

        return '';
    };

    /**
     * Get the localized reason why browser AI is unavailable.
     *
     * @param {object} availability Availability information.
     * @returns {string} User-facing reason.
     */
    NativeBrowserAIService.prototype.getUnavailableReason = function ( availability ) {
        if ( availability.state === 'model_download_required' ) {
            return this.getString( 'ai_download_required' );
        }

        if ( availability.browser === 'edge' ) {
            return this.getString( 'ai_edge_unavailable' );
        }

        if ( availability.browser === 'chrome' ) {
            return this.getString( 'ai_chrome_unavailable' );
        }

        return this.getString( 'ai_browser_unsupported' );
    };

    /**
     * Get the best available Prompt API handle.
     *
     * Prefers the current `LanguageModel` surface. The legacy
     * `ai.languageModel` surface remains isolated here only as a defensive
     * compatibility fallback for experimental builds.
     *
     * @returns {{api: object, variant: string}|null} API handle details.
     */
    NativeBrowserAIService.prototype.getApiHandle = function () {
        if ( typeof globalThis.LanguageModel !== 'undefined' && globalThis.LanguageModel ) {
            return {
                api:     globalThis.LanguageModel,
                variant: 'LanguageModel',
            };
        }

        if (
            typeof globalThis.ai !== 'undefined' &&
            globalThis.ai &&
            typeof globalThis.ai.languageModel !== 'undefined' &&
            globalThis.ai.languageModel
        ) {
            return {
                api:     globalThis.ai.languageModel,
                variant: 'ai.languageModel',
            };
        }

        return null;
    };

    /**
     * Detect the current browser family.
     *
     * @returns {{id: string, name: string}} Browser information.
     */
    NativeBrowserAIService.prototype.getBrowserInfo = function () {
        var userAgent = ( navigator.userAgent || '' ).toLowerCase();

        if ( userAgent.indexOf( 'edg/' ) !== -1 ) {
            return { id: 'edge', name: 'Microsoft Edge' };
        }

        if (
            userAgent.indexOf( 'chrome/' ) !== -1 &&
            userAgent.indexOf( 'edg/' ) === -1 &&
            userAgent.indexOf( 'opr/' ) === -1
        ) {
            return { id: 'chrome', name: 'Google Chrome' };
        }

        if ( userAgent.indexOf( 'firefox/' ) !== -1 ) {
            return { id: 'firefox', name: 'Firefox' };
        }

        if (
            userAgent.indexOf( 'safari/' ) !== -1 &&
            userAgent.indexOf( 'chrome/' ) === -1 &&
            userAgent.indexOf( 'chromium/' ) === -1 &&
            userAgent.indexOf( 'edg/' ) === -1
        ) {
            return { id: 'safari', name: 'Safari' };
        }

        return { id: 'other', name: 'this browser' };
    };

    /**
     * Resolve the logical availability state from the raw browser AI response.
     *
     * @param {*} rawAvailability Raw value returned by availability().
     * @param {object} browserInfo Browser details.
     * @param {object|null} apiHandle Prompt API handle.
     * @returns {string} Availability state.
     */
    NativeBrowserAIService.prototype.resolveAvailabilityState = function ( rawAvailability, browserInfo, apiHandle ) {
        var normalizedAvailability = String( rawAvailability || '' ).toLowerCase();

        if ( normalizedAvailability.indexOf( 'download' ) !== -1 ) {
            return 'model_download_required';
        }

        if ( normalizedAvailability === 'unavailable' ) {
            return browserInfo.id === 'chrome' || browserInfo.id === 'edge'
                ? 'feature_disabled'
                : 'unsupported';
        }

        if ( normalizedAvailability === 'available' ) {
            return 'available';
        }

        if ( apiHandle && typeof apiHandle.api.create === 'function' ) {
            return 'available';
        }

        return browserInfo.id === 'chrome' || browserInfo.id === 'edge'
            ? 'feature_disabled'
            : 'unsupported';
    };

    /**
     * Get session options from prompt options.
     *
     * @param {object} [options] Prompt options.
     * @returns {object} Session options.
     */
    NativeBrowserAIService.prototype.getSessionOptions = function ( options ) {
        if ( options && options.sessionOptions ) {
            return options.sessionOptions;
        }

        return {};
    };

    /**
     * Get a localized string from the AI config.
     *
     * @param {string} key String key.
     * @returns {string} Localized string.
     */
    NativeBrowserAIService.prototype.getString = function ( key ) {
        if ( this.config && this.config.strings && this.config.strings[ key ] ) {
            return this.config.strings[ key ];
        }

        return '';
    };

    /**
     * Strip markdown fences and whitespace from AI output.
     *
     * @param {string} content Raw AI response.
     * @returns {string} Sanitized response.
     */
    NativeBrowserAIService.prototype.sanitizeResponse = function ( content ) {
        if ( ! content ) {
            return '';
        }

        content = content.trim();
        content = content.replace( /^```[a-z]*\s*/i, '' );
        content = content.replace( /\s*```\s*$/i, '' );

        return content.trim();
    };

    /** @type {import('quill').default|null} */
    var quillInstance = null;

    /** @type {Element|Document|null} */
    var deckerContext = null;

    /** @type {object|null} AI-specific config passed from PHP via deckerVars.ai */
    var aiConfig = null;

    /** @type {NativeBrowserAIService|null} */
    var browserAIService = null;

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

        quillInstance     = quill;
        deckerContext     = context;
        aiConfig          = vars && vars.ai ? vars.ai : null;
        browserAIService  = new NativeBrowserAIService( aiConfig );

        if ( ! aiConfig || ! aiConfig.enabled ) {
            return;
        }

        addToolbarButton();
    }

    /**
     * Append an "Improve with AI" button to the Quill toolbar.
     */
    function addToolbarButton() {
        var toolbar = deckerContext.querySelector( '.ql-toolbar' );
        if ( ! toolbar ) {
            return;
        }

        if ( toolbar.querySelector( '.ql-ai-improve' ) ) {
            return;
        }

        var modes = getModes();
        var btn = document.createElement( 'button' );
        btn.type      = 'button';
        btn.className = 'ql-ai-improve';
        btn.title     = aiConfig.strings.improve_with_ai;
        btn.setAttribute( 'aria-label', aiConfig.strings.improve_with_ai );
        btn.setAttribute( 'aria-haspopup', 'true' );
        btn.setAttribute( 'aria-expanded', 'false' );
        btn.innerHTML =
            '<span class="decker-ai-icon" aria-hidden="true">' +
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="none">' +
            '<path d="M8 2.25L9.04 5.4a1 1 0 0 0 .63.63L12.75 7l-3.08.97a1 1 0 0 0-.63.63L8 11.75 6.96 8.6a1 1 0 0 0-.63-.63L3.25 7l3.08-.97a1 1 0 0 0 .63-.63L8 2.25Z" />' +
            '<path d="M2.65 2.1 3 3.15l1.05.35L3 3.85 2.65 4.9 2.3 3.85 1.25 3.5 2.3 3.15 2.65 2.1Z" />' +
            '<path d="m12.6 10.35.35 1.05 1.05.35-1.05.35-.35 1.05-.35-1.05-1.05-.35 1.05-.35.35-1.05Z" />' +
            '</svg>' +
            '</span>' +
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

    /**
     * Get the supported AI actions.
     *
     * @returns {Array<{key: string, label: string}>} Available actions.
     */
    function getModes() {
        return [
            { key: 'improve_writing',     label: aiConfig.strings.mode_improve_writing },
            { key: 'make_shorter',        label: aiConfig.strings.mode_make_shorter },
            { key: 'make_clearer',        label: aiConfig.strings.mode_make_clearer },
            { key: 'fix_grammar',         label: aiConfig.strings.mode_fix_grammar },
            { key: 'make_actionable',     label: aiConfig.strings.mode_make_actionable },
            { key: 'checklist',           label: aiConfig.strings.mode_checklist },
            { key: 'acceptance_criteria', label: aiConfig.strings.mode_acceptance_criteria },
            { key: 'summarize',           label: aiConfig.strings.mode_summarize },
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

    /**
     * Run the AI improvement flow for the selected action.
     *
     * @param {string} mode Rewrite mode key.
     * @returns {Promise<void>} Async flow result.
     */
    async function runImproveFlow( mode ) {
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

        var availabilityPromise = browserAIService.getAvailability();
        // Start session creation from the original user interaction when an API
        // surface exists. Some experimental Prompt API implementations require
        // create() to run directly from a user gesture.
        var sessionPromise      = browserAIService.isSupported()
            ? browserAIService.createSession( { skipAvailabilityCheck: true } ).then( function ( session ) {
                return {
                    session: session,
                    error:   null,
                };
            } ).catch( function ( error ) {
                return {
                    session: null,
                    error:   error,
                };
            } )
            : null;
        var availability        = await availabilityPromise;

        if ( ! availability.usable ) {
            showUnavailableMessage( availability );
            return;
        }

        Swal.fire( {
            title:             aiConfig.strings.improving,
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: function () {
                Swal.showLoading();
            },
        } );

        try {
            var sessionResult = sessionPromise ? await sessionPromise : null;

            if ( sessionResult && sessionResult.error ) {
                throw sessionResult.error;
            }

            var improvedText = await browserAIService.prompt(
                buildPrompt( mode, textToImprove ),
                {
                    session: sessionResult ? sessionResult.session : null,
                }
            );

            if ( ! improvedText ) {
                throw new Error( aiConfig.strings.ai_empty_response );
            }

            var originalPreview = isSelection
                ? quillInstance.getText( savedRange.index, savedRange.length )
                : quillInstance.getText();
            var confirmed = await showPreview( originalPreview, improvedText );

            if ( confirmed ) {
                applyImprovement( improvedText, isSelection ? savedRange : null );
            }
        } catch ( error ) {
            Swal.fire( {
                icon:  'error',
                title: aiConfig.strings.error,
                text:  error && error.message ? error.message : aiConfig.strings.error_message,
            } );
            return;
        }
    }

    /**
     * Build a prompt string for the given rewrite mode.
     *
     * @param {string} mode Rewrite mode key.
     * @param {string} text HTML content.
     * @returns {string} Full prompt.
     */
    function buildPrompt( mode, text ) {
        var prefixes = {
            improve_writing:     aiConfig.prompts.improve_writing,
            make_shorter:        aiConfig.prompts.make_shorter,
            make_clearer:        aiConfig.prompts.make_clearer,
            fix_grammar:         aiConfig.prompts.fix_grammar,
            make_actionable:     aiConfig.prompts.make_actionable,
            checklist:           aiConfig.prompts.checklist,
            acceptance_criteria: aiConfig.prompts.acceptance_criteria,
            summarize:           aiConfig.prompts.summarize,
        };

        var prefix = prefixes[ mode ] || prefixes.improve_writing;

        return prefix + ' ' + aiConfig.prompts.language_instruction + ' ' +
            aiConfig.prompts.response_format + '\n\n' + text;
    }

    /**
     * Show a helpful browser-specific unavailable message.
     *
     * @param {object} availability Availability details.
     */
    function showUnavailableMessage( availability ) {
        var html =
            '<p>' + escapeHtml( aiConfig.strings.ai_unavailable_intro ) + '</p>' +
            '<p class="mb-0">' + escapeHtml( availability.reason ) + '</p>';

        if ( availability.help_url ) {
            html +=
                '<p class="mt-3 mb-0">' +
                '<a href="' + escapeAttr( availability.help_url ) + '" target="_blank" rel="noopener noreferrer">' +
                escapeHtml( aiConfig.strings.ai_help_link ) +
                '</a>' +
                '</p>';
        }

        Swal.fire( {
            icon:  'info',
            title: aiConfig.strings.ai_unavailable_title,
            html:  html,
        } );
    }

    /**
     * Show a before/after preview and ask the user to confirm.
     *
     * @param {string} original Plain-text original.
     * @param {string} improved Improved HTML from AI.
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

    /**
     * Replace the selected range (or full content) with the improved HTML.
     *
     * @param {string} improvedHtml HTML returned by AI.
     * @param {{index:number,length:number}|null} range Selection range, or null for full replacement.
     */
    function applyImprovement( improvedHtml, range ) {
        if ( range && range.length > 0 ) {
            quillInstance.deleteText( range.index, range.length, 'api' );
            quillInstance.clipboard.dangerouslyPasteHTML( range.index, improvedHtml, 'api' );
        } else {
            quillInstance.clipboard.dangerouslyPasteHTML( 0, improvedHtml, 'api' );
        }

        if ( typeof window.deckerHasUnsavedChanges !== 'undefined' ) {
            window.deckerHasUnsavedChanges = true;
        }
    }

    /**
     * Get the HTML of a Quill selection range.
     *
     * @param {{index:number,length:number}} range Selection range.
     * @returns {string} HTML string.
     */
    function getSelectionHtml( range ) {
        if ( typeof quillInstance.getSemanticHTML === 'function' ) {
            return quillInstance.getSemanticHTML( range.index, range.length );
        }

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

        return String( str ).replace( /[&<>"']/g, function ( character ) {
            var replacements = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;',
            };

            return replacements[ character ];
        } );
    }

    return {
        init: init,
    };

}());
