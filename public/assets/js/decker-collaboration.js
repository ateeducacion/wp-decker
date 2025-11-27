/**
 * Decker Collaborative Editing Module
 *
 * Provides real-time collaborative editing for Quill editor using Yjs and WebRTC.
 * This module handles peer-to-peer synchronization without requiring server-side setup.
 *
 * @package Decker
 */

import * as Y from 'https://esm.sh/yjs@13';
import { WebrtcProvider } from 'https://esm.sh/y-webrtc@10';
import { QuillBinding } from 'https://esm.sh/y-quill@0.1';

(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.deckerCollabConfig || {};
    const signalingServer = config.signalingServer || 'wss://signaling.yjs.dev';
    const roomPrefix = config.roomPrefix || 'decker-task-';
    const userName = config.userName || 'Anonymous';
    const userColor = config.userColor || getRandomColor();
    const userId = config.userId || 0;

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
     * @param {boolean} isDisabled - If true, show "off" state
     */
    function updateStatusBar(statusBar, awareness, provider, isDisabled = false) {
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
            usersContainer.innerHTML = '';
            return;
        }

        // Check real signaling connection status
        const signalingOk = isSignalingConnected(provider);

        if (signalingOk) {
            text.textContent = config.strings?.collaborative_mode || 'Collaborative mode';

            // Only show avatars when connected
            usersContainer.innerHTML = '';
            const states = awareness.getStates();

            states.forEach((state, clientId) => {
                if (state.user && clientId !== awareness.clientID) {
                    const avatar = document.createElement('div');
                    avatar.className = 'decker-collab-user-avatar';
                    avatar.style.backgroundColor = state.user.color || '#6c757d';
                    avatar.textContent = (state.user.name || 'U').charAt(0).toUpperCase();
                    avatar.title = state.user.name || 'Unknown user';
                    usersContainer.appendChild(avatar);
                }
            });

            // Add self indicator
            const selfAvatar = document.createElement('div');
            selfAvatar.className = 'decker-collab-user-avatar';
            selfAvatar.style.backgroundColor = userColor;
            selfAvatar.style.border = '2px solid #28a745';
            selfAvatar.textContent = userName.charAt(0).toUpperCase();
            selfAvatar.title = `${userName} (${config.strings?.you || 'you'})`;
            usersContainer.appendChild(selfAvatar);
        } else {
            // Not connected - show connecting state, no avatars
            dot.classList.add('connecting');
            text.textContent = config.strings?.connecting || 'Connecting...';
            usersContainer.innerHTML = '';
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

        // Set local user state
        awareness.setLocalStateField('user', {
            name: userName,
            color: userColor,
            id: userId
        });

        // Create status bar
        const statusBar = createStatusBar(container, awareness);

        // Bind Quill to Yjs
        const binding = new QuillBinding(ytext, quillInstance, awareness);

        // Connection retry tracking
        let connectionAttempts = 0;
        let isDisabled = false;
        const maxRetries = 5;
        const retryInterval = 2000; // Check every 2 seconds

        // Update status on awareness changes
        awareness.on('change', () => {
            if (!isDisabled) {
                updateStatusBar(statusBar, awareness, provider, false);
            }
        });

        // Update status on provider status changes
        provider.on('status', () => {
            if (!isDisabled) {
                updateStatusBar(statusBar, awareness, provider, false);
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
                updateStatusBar(statusBar, awareness, provider, false);
            } else {
                // Not connected
                connectionAttempts++;
                console.log(`Decker Collaboration: Connection attempt ${connectionAttempts}/${maxRetries}`);

                if (connectionAttempts >= maxRetries) {
                    // Max retries reached - disable collaboration
                    isDisabled = true;
                    clearInterval(connectionChecker);
                    console.warn('Decker Collaboration: Max retries reached, disabling collaborative mode');
                    updateStatusBar(statusBar, awareness, provider, true);
                } else {
                    updateStatusBar(statusBar, awareness, provider, false);
                }
            }
        }, retryInterval);

        // Initial status update
        updateStatusBar(statusBar, awareness, provider, false);

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
             * Set initial content (only if document is empty)
             */
            setInitialContent(html) {
                if (ytext.length === 0 && html) {
                    // Use Quill's clipboard to properly convert HTML to Delta
                    const delta = quillInstance.clipboard.convert(html);
                    ytext.applyDelta(delta.ops);
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
                // Clear connection checker interval
                clearInterval(connectionChecker);

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
