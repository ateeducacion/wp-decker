/**
 * Decker Collaborative Editing Module
 *
 * Provides real-time collaborative editing for Quill editor using Yjs and WebRTC.
 * This module handles peer-to-peer synchronization without requiring server-side setup.
 *
 * @package Decker
 */

// Use esm.sh with shared yjs dependency to avoid "Yjs was already imported" error
import * as Y from 'https://esm.sh/yjs@13.6.20';
import { WebrtcProvider } from 'https://esm.sh/y-webrtc@10.3.0?deps=yjs@13.6.20';
import { QuillBinding } from 'https://esm.sh/y-quill@1.0.0?deps=yjs@13.6.20';

(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.deckerCollabConfig || {};
    const signalingServer = config.signalingServer || 'wss://signaling.yjs.dev';
    const roomPrefix = config.roomPrefix || 'decker-task-';
    const userName = config.userName || 'Anonymous';
    const userColor = config.userColor || getRandomColor();
    const userId = config.userId || 0;
    const userAvatar = config.userAvatar || null;

    // Store for active collaboration sessions
    const sessions = new Map();

    /**
     * Generate a random color for user cursor
     */
    function getRandomColor() {
        const colors = [
            '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
            '#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9'
        ];
        return colors[Math.floor(Math.random() * colors.length)];
    }

    /**
     * Create an avatar element (image or fallback to initials)
     * @param {string|null} avatarUrl - URL of the avatar image
     * @param {string} name - User's display name
     * @param {string} color - User's color
     * @param {boolean} isSelf - Whether this is the current user
     * @returns {HTMLElement} The avatar element
     */
    function createAvatarElement(avatarUrl, name, color, isSelf = false) {
        const avatar = document.createElement('div');
        avatar.className = 'decker-collab-user-avatar';
        avatar.title = name;

        if (avatarUrl) {
            // Use WordPress avatar image
            const img = document.createElement('img');
            img.src = avatarUrl;
            img.alt = name;
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.borderRadius = '50%';
            img.style.objectFit = 'cover';
            // Fallback to initials if image fails to load
            img.onerror = () => {
                avatar.removeChild(img);
                avatar.style.backgroundColor = color;
                avatar.textContent = (name || 'U').charAt(0).toUpperCase();
            };
            avatar.appendChild(img);
        } else {
            // Fallback to initials
            avatar.style.backgroundColor = color;
            avatar.textContent = (name || 'U').charAt(0).toUpperCase();
        }

        // Add border: green for self, user's color for others
        if (isSelf) {
            avatar.style.border = '2px solid #28a745';
        } else {
            avatar.style.border = `2px solid ${color}`;
        }

        return avatar;
    }

    /**
     * Create awareness cursor styles
     */
    function createAwarenessStyles() {
        if (document.getElementById('decker-collab-styles')) {
            return;
        }

        const style = document.createElement('style');
        style.id = 'decker-collab-styles';
        style.textContent = `
            .decker-collab-cursor {
                position: absolute;
                width: 2px;
                pointer-events: none;
                z-index: 1000;
            }
            .decker-collab-cursor-label {
                position: absolute;
                top: -18px;
                left: 0;
                font-size: 11px;
                padding: 2px 6px;
                border-radius: 3px;
                white-space: nowrap;
                color: white;
                font-weight: 500;
            }
            .decker-collab-status {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 4px 12px;
                font-size: 12px;
                background: #f8f9fa;
                border-radius: 4px;
                margin-bottom: 0;
            }
            .decker-collab-status-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: #28a745;
                animation: decker-pulse 2s infinite;
            }
            .decker-collab-status-dot.disconnected {
                background: #dc3545;
                animation: none;
            }
            .decker-collab-status-dot.connecting {
                background: #ffc107;
            }
            .decker-collab-status-dot.disabled {
                background: #6c757d;
                animation: none;
            }
            .decker-collab-users {
                display: flex;
                gap: 4px;
                margin-left: auto;
            }
            .decker-collab-user-avatar {
                width: 24px;
                height: 24px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 10px;
                font-weight: bold;
                border: 2px solid white;
                box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            }
            @keyframes decker-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }

            /* Field collaboration indicators */
            .decker-field-editor {
                position: absolute;
                top: -18px;
                right: 8px;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                color: white;
                pointer-events: none;
                animation: decker-fade-in 0.2s ease;
                z-index: 10;
            }

            /* Higher position for Choices.js fields (assignees, labels) */
            .choices.decker-field-editing-container .decker-field-editor {
                top: -24px;
                z-index: 100;
            }

            /* Ensure choices container allows overflow for indicator */
            .choices.decker-field-editing-container {
                overflow: visible !important;
            }

            .decker-field-editing {
                box-shadow: 0 0 0 2px var(--editor-color) !important;
                transition: box-shadow 0.2s ease;
            }

            .decker-remote-change {
                animation: decker-flash 0.4s ease;
            }

            @keyframes decker-fade-in {
                from { opacity: 0; transform: translateY(5px); }
                to { opacity: 1; transform: translateY(0); }
            }

            @keyframes decker-flash {
                0% { background-color: inherit; }
                30% { background-color: rgba(40, 167, 69, 0.15); }
                100% { background-color: inherit; }
            }

            /* Archived task overlay */
            .decker-archived-overlay {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(255, 255, 255, 0.9);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 1000;
                border-radius: 4px;
            }

            .decker-archived-message {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 10px;
                padding: 20px 40px;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                border-radius: 8px;
                color: #721c24;
                font-size: 16px;
                font-weight: 500;
            }

            .decker-archived-message i {
                font-size: 32px;
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Create a status bar for the collaborative session
     */
    function createStatusBar(container, awareness) {
        const statusBar = document.createElement('div');
        statusBar.className = 'decker-collab-status';
        statusBar.innerHTML = `
            <span class="decker-collab-status-dot connecting" title="Connecting..."></span>
            <span class="decker-collab-status-text">${config.strings?.connecting || 'Connecting...'}</span>
            <div class="decker-collab-users"></div>
        `;

        // Try to insert in the bottom action bar (next to Save button)
        const actionBar = container.querySelector('.d-flex.justify-content-end.align-items-center');
        if (actionBar) {
            // Change justify-content-end to justify-content-between
            actionBar.classList.remove('justify-content-end');
            actionBar.classList.add('justify-content-between');
            // Insert at the beginning
            actionBar.insertBefore(statusBar, actionBar.firstChild);
        } else {
            // Fallback: insert before the editor
            const editorContainer = container.querySelector('#editor-container') || container.querySelector('#editor');
            if (editorContainer && editorContainer.parentNode) {
                editorContainer.parentNode.insertBefore(statusBar, editorContainer);
            }
        }

        return statusBar;
    }

    /**
     * Check if signaling WebSocket is actually connected
     */
    function isSignalingConnected(provider) {
        // Check if any signaling connection is open
        if (provider.signalingConns && provider.signalingConns.length > 0) {
            for (const conn of provider.signalingConns) {
                if (conn.ws && conn.ws.readyState === WebSocket.OPEN) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Update the status bar with current users
     * @param {Object} trackingState - Object to track state between updates
     * @param {boolean} isDisabled - If true, show "off" state
     */
    function updateStatusBar(statusBar, awareness, provider, trackingState, isDisabled = false) {
        if (!statusBar) return;

        const dot = statusBar.querySelector('.decker-collab-status-dot');
        const text = statusBar.querySelector('.decker-collab-status-text');
        const usersContainer = statusBar.querySelector('.decker-collab-users');

        // Clear all status classes
        dot.classList.remove('connecting', 'disconnected', 'disabled');

        if (isDisabled) {
            // Collaboration disabled after max retries
            dot.classList.add('disabled');
            text.textContent = config.strings?.collaborative_mode_off || 'Collaborative mode: off';
            if (trackingState.connectionState !== 'disabled') {
                usersContainer.innerHTML = '';
                trackingState.connectionState = 'disabled';
                trackingState.userIds = null;
            }
            return;
        }

        // Check real signaling connection status
        const signalingOk = isSignalingConnected(provider);

        if (signalingOk) {
            text.textContent = config.strings?.collaborative_mode || 'Collaborative mode';

            // Get current user IDs (excluding self)
            const states = awareness.getStates();
            const currentUserIds = [];
            states.forEach((state, clientId) => {
                if (state.user && clientId !== awareness.clientID) {
                    currentUserIds.push(clientId);
                }
            });
            // Sort to ensure consistent comparison
            currentUserIds.sort();
            const currentUserIdsStr = currentUserIds.join(',');

            // Only rebuild avatars if users changed or connection state changed
            if (trackingState.userIds !== currentUserIdsStr || trackingState.connectionState !== 'connected') {
                usersContainer.innerHTML = '';

                states.forEach((state, clientId) => {
                    if (state.user && clientId !== awareness.clientID) {
                        const avatar = createAvatarElement(
                            state.user.avatar,
                            state.user.name || 'Unknown user',
                            state.user.color || '#6c757d'
                        );
                        avatar.dataset.clientId = clientId;
                        usersContainer.appendChild(avatar);
                    }
                });

                // Add self indicator
                const selfAvatar = createAvatarElement(
                    userAvatar,
                    `${userName} (${config.strings?.you || 'you'})`,
                    userColor,
                    true // isSelf
                );
                selfAvatar.dataset.clientId = 'self';
                usersContainer.appendChild(selfAvatar);

                trackingState.userIds = currentUserIdsStr;
                trackingState.connectionState = 'connected';
            }
        } else {
            // Not connected - show connecting state, no avatars
            dot.classList.add('connecting');
            text.textContent = config.strings?.connecting || 'Connecting...';
            if (trackingState.connectionState !== 'connecting') {
                usersContainer.innerHTML = '';
                trackingState.connectionState = 'connecting';
                trackingState.userIds = null;
            }
        }
    }

    /**
     * Initialize collaborative editing for a Quill instance
     *
     * @param {Quill} quillInstance - The Quill editor instance
     * @param {string|number} documentId - Unique identifier for the document (e.g., task ID)
     * @param {HTMLElement} container - The container element for the editor
     * @returns {Object} Session object with cleanup methods
     */
    function initCollaboration(quillInstance, documentId, container) {
        if (!quillInstance || !documentId) {
            console.warn('Decker Collaboration: Missing quill instance or document ID');
            return null;
        }

        // Check if already initialized for this document
        const sessionKey = `${roomPrefix}${documentId}`;
        if (sessions.has(sessionKey)) {
            console.log('Decker Collaboration: Session already exists for', sessionKey);
            return sessions.get(sessionKey);
        }

        createAwarenessStyles();

        // Create Yjs document
        const ydoc = new Y.Doc();
        const ytext = ydoc.getText('quill');
        const formFields = ydoc.getMap('formFields');

        // Create WebRTC provider with its built-in awareness
        const provider = new WebrtcProvider(sessionKey, ydoc, {
            signaling: [signalingServer],
            password: null,
            maxConns: 20,
            filterBcConns: true,
            peerOpts: {}
        });

        // Use the provider's built-in awareness
        const awareness = provider.awareness;

        // Sync state tracking for event-based initialization
        let isSynced = false;
        let syncPromiseResolve = null;
        const syncPromise = new Promise(resolve => {
            syncPromiseResolve = resolve;
        });

        // Listen for WebRTC provider sync event (fires when synced with peers)
        // y-webrtc may emit a boolean or an object { synced: boolean }
        provider.on('synced', (event) => {
            const synced = typeof event === 'boolean' ? event : event?.synced;
            if (synced && !isSynced) {
                isSynced = true;
                console.log('Decker Collaboration: Synced with peers');
                syncPromiseResolve();
            }
        });

        // Fast single-user detection: check periodically until we confirm status
        let singleUserCheckCount = 0;
        const maxSingleUserChecks = 10; // Check up to 10 times (100ms * 10 = 1 second max)
        let singleUserTimerId = null;

        const checkSingleUser = () => {
            if (isSynced) return; // Already synced, stop checking

            singleUserCheckCount++;
            const signalingOk = isSignalingConnected(provider);
            const peerCount = awareness.getStates().size;

            // If signaling is connected and we're alone after a few checks, proceed
            if (signalingOk && peerCount <= 1 && singleUserCheckCount >= 3) {
                console.log('Decker Collaboration: Single user mode detected (check #' + singleUserCheckCount + ')');
                isSynced = true;
                syncPromiseResolve();
                return;
            }

            // If we have peers, wait for proper sync
            if (peerCount > 1) {
                console.log('Decker Collaboration: Multiple peers detected, waiting for sync');
                return; // Stop checking, let 'synced' event handle it
            }

            // Keep checking until max attempts
            if (singleUserCheckCount < maxSingleUserChecks) {
                singleUserTimerId = setTimeout(checkSingleUser, 100);
            } else if (!isSynced) {
                // All checks exhausted without resolution — resolve sync immediately
                isSynced = true;
                syncPromiseResolve();
            }
        };

        // Start checking immediately
        singleUserTimerId = setTimeout(checkSingleUser, 100);

        // Safety timeout: reduced to 2 seconds (only as last resort)
        const syncTimeout = setTimeout(() => {
            if (!isSynced) {
                console.warn('Decker Collaboration: Sync timeout (2s), proceeding');
                isSynced = true;
                syncPromiseResolve();
            }
        }, 2000);

        // Clear timeout when sync completes normally
        syncPromise.then(() => clearTimeout(syncTimeout));

        // Set local user state
        awareness.setLocalStateField('user', {
            name: userName,
            color: userColor,
            id: userId,
            avatar: userAvatar
        });

        // Create status bar
        const statusBar = createStatusBar(container, awareness);

        // Track state for status bar updates to avoid unnecessary re-renders
        const statusBarTrackingState = {
            userIds: null,
            connectionState: null
        };

        // Bind Quill to Yjs
        // NOTE: Do NOT pass awareness to QuillBinding - y-quill's internal cursor tracking
        // uses RelativePosition which is incompatible with Quill 2.0 (causes 'tname' error).
        // We handle cursors manually using quill-cursors module and awareness API directly.
        const binding = new QuillBinding(ytext, quillInstance);

        // Get the cursors module from Quill (if available)
        const cursorsModule = quillInstance.getModule('cursors');
        console.log('Decker Collaboration: cursorsModule =', cursorsModule);

        // Track selection change handler for cleanup
        let selectionChangeHandler = null;

        // Setup remote cursor management if cursors module is available
        if (cursorsModule) {
            console.log('Decker Collaboration: Cursors module detected, enabling remote cursors');

            // Track selection changes and update awareness
            selectionChangeHandler = (range, oldRange, source) => {
                if (range) {
                    // User has focus and selection - update cursor position
                    awareness.setLocalStateField('cursor', {
                        index: range.index,
                        length: range.length
                    });
                } else {
                    // User lost focus (clicked outside editor) - clear cursor
                    awareness.setLocalStateField('cursor', null);
                }
            };
            quillInstance.on('selection-change', selectionChangeHandler);

            // Listen for awareness changes to update remote cursors
            awareness.on('change', () => {
                const states = awareness.getStates();

                // Get current cursor IDs in the module
                const currentCursorIds = new Set();

                states.forEach((state, clientId) => {
                    if (clientId === awareness.clientID) return; // Skip self
                    if (!state.user || !state.cursor) return;

                    const cursorId = `cursor-${clientId}`;
                    currentCursorIds.add(cursorId);

                    // Create or update cursor
                    try {
                        const existingCursor = cursorsModule.cursors().find(c => c.id === cursorId);
                        if (!existingCursor) {
                            cursorsModule.createCursor(cursorId, state.user.name, state.user.color);
                        }
                        cursorsModule.moveCursor(cursorId, {
                            index: state.cursor.index,
                            length: state.cursor.length
                        });
                    } catch (e) {
                        console.warn('Error updating cursor:', e);
                    }
                });

                // Remove cursors for disconnected users
                cursorsModule.cursors().forEach(cursor => {
                    if (!currentCursorIds.has(cursor.id)) {
                        cursorsModule.removeCursor(cursor.id);
                    }
                });
            });
        } else {
            console.warn('Decker Collaboration: Cursors module NOT found in Quill instance. Remote cursors will not be displayed.');
        }

        // Connection retry tracking
        let connectionAttempts = 0;
        let isDisabled = false;
        const maxRetries = 5;
        const retryInterval = 2000; // Check every 2 seconds

        // Update status on awareness changes
        awareness.on('change', () => {
            if (!isDisabled) {
                updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, false);
            }
        });

        // Update status on provider status changes
        provider.on('status', () => {
            if (!isDisabled) {
                updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, false);
            }
        });

        // Periodic connection check
        const connectionChecker = setInterval(() => {
            if (isDisabled) {
                clearInterval(connectionChecker);
                return;
            }

            const signalingOk = isSignalingConnected(provider);

            if (signalingOk) {
                // Connected - reset counter
                connectionAttempts = 0;
                updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, false);
            } else {
                // Not connected
                connectionAttempts++;
                console.log(`Decker Collaboration: Connection attempt ${connectionAttempts}/${maxRetries}`);

                if (connectionAttempts >= maxRetries) {
                    // Max retries reached - disable collaboration
                    isDisabled = true;
                    clearInterval(connectionChecker);
                    console.warn('Decker Collaboration: Max retries reached, disabling collaborative mode');
                    updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, true);
                } else {
                    updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, false);
                }
            }
        }, retryInterval);

        // Initial status update
        updateStatusBar(statusBar, awareness, provider, statusBarTrackingState, false);

        // Create session object
        const session = {
            ydoc,
            ytext,
            formFields,
            provider,
            awareness,
            binding,
            statusBar,
            documentId,
            container,

            /**
             * Get the current document content
             */
            getContent() {
                return ytext.toString();
            },

            /**
             * Check if initial sync is complete
             * @returns {boolean}
             */
            isSynced() {
                return isSynced;
            },

            /**
             * Register a callback for when sync completes
             * @param {Function} callback - Function to call when synced
             */
            onSynced(callback) {
                if (isSynced) {
                    callback();
                } else {
                    syncPromise.then(callback);
                }
            },

            /**
             * Initialize content with fallback to original HTML.
             * Should be called via onSynced() to ensure proper timing.
             * @param {string} originalHtml - Original HTML content from server
             */
            initializeContentWithFallback(originalHtml) {
                // Only set content if Y.js document is empty
                if (ytext.length === 0 && originalHtml && originalHtml.trim() !== '' && originalHtml !== '<p><br></p>') {
                    console.log('Decker Collaboration: Y.js empty after sync, initializing with original content:', originalHtml);

                    try {
                        // Convert HTML to Quill delta
                        const delta = quillInstance.clipboard.convert({ html: originalHtml });
                        console.log('Decker Collaboration: Converted delta:', delta);

                        if (delta && delta.ops && delta.ops.length > 0) {
                            // Apply delta to Y.js text
                            ytext.applyDelta(delta.ops);
                            console.log('Decker Collaboration: Applied delta to Y.js, ytext.length:', ytext.length);

                            // If Quill is still empty after applying to Y.js, set content directly
                            // This handles cases where the binding doesn't propagate correctly
                            setTimeout(() => {
                                const quillText = quillInstance.getText().trim();
                                if (quillText === '' && ytext.length > 0) {
                                    console.log('Decker Collaboration: Quill still empty, forcing content from Y.js');
                                    // Get delta from Y.js and apply to Quill
                                    const ytextDelta = ytext.toDelta();
                                    quillInstance.setContents(ytextDelta, 'api');
                                }
                            }, 100);
                        } else {
                            // Fallback: insert text-only content if delta conversion failed
                            console.log('Decker Collaboration: Delta conversion returned empty, using direct insert');
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = originalHtml;
                            const plainText = tempDiv.textContent || '';
                            ytext.insert(0, plainText);
                        }
                    } catch (error) {
                        console.error('Decker Collaboration: Error initializing content:', error);
                        // Last resort: try to set Quill content directly
                        try {
                            quillInstance.clipboard.dangerouslyPasteHTML(0, originalHtml, 'api');
                        } catch (e) {
                            console.error('Decker Collaboration: Fallback also failed:', e);
                        }
                    }
                } else if (ytext.length > 0) {
                    console.log('Decker Collaboration: Y.js has content from sync, keeping it. Length:', ytext.length);
                }
            },

            /**
             * Check if connected to peers
             */
            isConnected() {
                return provider.connected;
            },

            /**
             * Get list of connected users
             */
            getConnectedUsers() {
                const users = [];
                awareness.getStates().forEach((state, clientId) => {
                    if (state.user) {
                        users.push({
                            ...state.user,
                            clientId,
                            isLocal: clientId === awareness.clientID
                        });
                    }
                });
                return users;
            },

            /**
             * Set the currently active (focused) field for this user
             * @param {string|null} fieldId - The ID of the focused field, or null to clear
             */
            setActiveField(fieldId) {
                awareness.setLocalStateField('activeField', fieldId);
            },

            /**
             * Clear the active field for this user
             */
            clearActiveField() {
                awareness.setLocalStateField('activeField', null);
            },

            /**
             * Destroy the collaboration session
             */
            destroy() {
                // Clear all timers to prevent post-destroy callbacks
                clearInterval(connectionChecker);
                clearTimeout(syncTimeout);
                clearTimeout(singleUserTimerId);
                isSynced = true; // Prevent any pending callbacks from firing

                // Clear cursor from awareness before disconnecting
                awareness.setLocalStateField('cursor', null);

                // Remove selection change handler if it exists
                if (selectionChangeHandler) {
                    quillInstance.off('selection-change', selectionChangeHandler);
                }

                // Clear all remote cursors from the module
                if (cursorsModule) {
                    cursorsModule.clearCursors();
                }

                binding.destroy();
                provider.disconnect();
                provider.destroy();
                ydoc.destroy();

                if (statusBar && statusBar.parentNode) {
                    statusBar.parentNode.removeChild(statusBar);
                }

                sessions.delete(sessionKey);
                console.log('Decker Collaboration: Session destroyed for', sessionKey);
            }
        };

        sessions.set(sessionKey, session);
        console.log('Decker Collaboration: Session initialized for', sessionKey);

        return session;
    }

    /**
     * Destroy a collaboration session by document ID
     */
    function destroyCollaboration(documentId) {
        const sessionKey = `${roomPrefix}${documentId}`;
        const session = sessions.get(sessionKey);
        if (session) {
            session.destroy();
        }
    }

    /**
     * Destroy all active collaboration sessions
     */
    function destroyAllSessions() {
        sessions.forEach(session => session.destroy());
    }

    // Expose to global scope for integration with task-card.js
    window.DeckerCollaboration = {
        init: initCollaboration,
        destroy: destroyCollaboration,
        destroyAll: destroyAllSessions,
        getSessions: () => new Map(sessions),
        isEnabled: () => config.enabled === true
    };

    // Cleanup on page unload
    window.addEventListener('beforeunload', destroyAllSessions);

    console.log('Decker Collaboration module loaded');

})();
