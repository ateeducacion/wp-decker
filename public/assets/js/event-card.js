(function($) {
    'use strict';

    // Initialize event card functionality
    function initializeEventCard() {
        
        // Handle search functionality
        $('#searchInput').on('keyup', function() {
            const searchText = $(this).val().toLowerCase();
            $('#eventsTable tbody tr').each(function() {
                const title = $(this).find('.event-title').text().toLowerCase();
                const start = $(this).find('.event-start').text().toLowerCase();
                const end = $(this).find('.event-end').text().toLowerCase();
                const location = $(this).find('.event-location').text().toLowerCase();
                const category = $(this).find('.event-category').text().toLowerCase();

                if (title.includes(searchText) || start.includes(searchText) || 
                    end.includes(searchText) || location.includes(searchText) || 
                    category.includes(searchText)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });
        
        // Handle delete event
        $('.delete-event').on('click', function(e) {
            e.preventDefault();
            const id = $(this).data('id');
            const title = $(this).closest('tr').find('.event-title').text().trim();

            if (confirm(deckerVars.strings.confirm_delete_event + ' "' + title + '"')) {
                $.ajax({
                    url: deckerVars.rest_url + 'events/' + id,
                    method: 'DELETE',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', deckerVars.nonces.wp_rest);
                    },
                    success: function(response) {
                        location.reload();
                    },
                    error: function(xhr, status, error) {
                        alert(deckerVars.strings.error_deleting_event + ' ' + error);
                    }
                });
            }
        });
    }

    // Initialize when document is ready
    $(document).ready(function() {
        initializeEventCard();
    });

})(jQuery);
