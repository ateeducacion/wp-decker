/* global jQuery, Swal, deckerVars */

/**
 * Decker session expiry handling.
 *
 * Uses WordPress Heartbeat authentication signals to distinguish an expired
 * session from server unavailability. The warning is shown at most once after
 * the user dismisses it, avoiding a modal on every Heartbeat response.
 */
(function($) {
    'use strict';

    var sessionWarningDismissed = false;
    var sessionWarningOpen = false;

    /**
     * Return a localized Decker UI string.
     *
     * @param {string} key String key from deckerVars.strings.
     * @return {string} Localized string or an empty string.
     */
    function getDeckerString(key) {
        if (
            typeof deckerVars === 'undefined'
            || !deckerVars.strings
            || !deckerVars.strings[key]
        ) {
            return '';
        }

        return deckerVars.strings[key];
    }

    /**
     * Show the expired-session warning once until authentication is restored.
     */
    function showSessionExpiredWarning() {
        var message = getDeckerString('session_expired_message');
        var unsavedChangesTitle = getDeckerString('unsaved_changes_title');

        window.deckerSessionExpired = true;

        if (
            sessionWarningDismissed
            || sessionWarningOpen
            || typeof Swal === 'undefined'
            || !message
        ) {
            return;
        }

        sessionWarningOpen = true;

        if (
            window.deckerHasUnsavedChanges === true
            && unsavedChangesTitle
        ) {
            message += ' ' + unsavedChangesTitle + '.';
        }

        Swal.fire({
            icon: 'warning',
            title: 'Decker',
            text: message,
            confirmButtonText: getDeckerString('ok') || 'OK',
            showCancelButton: true,
            cancelButtonText: getDeckerString('cancel') || 'Cancel',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then(function(result) {
            sessionWarningOpen = false;

            if (result.isConfirmed) {
                window.location.reload();
                return;
            }

            sessionWarningDismissed = true;
        });
    }

    /**
     * Reset the warning state when Heartbeat confirms authentication again.
     */
    function clearSessionExpiredState() {
        window.deckerSessionExpired = false;
        sessionWarningDismissed = false;
    }

    window.deckerSessionExpired = false;

    $(document).on('heartbeat-tick', function(event, data) {
        if (data && data['wp-auth-check'] === false) {
            showSessionExpiredWarning();
            return;
        }

        if (data && data['wp-auth-check'] === true) {
            clearSessionExpiredState();
        }
    });

    $(document).on('heartbeat-nonces-expired', function() {
        showSessionExpiredWarning();
    });

})(jQuery);
