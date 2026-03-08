


(function() {
    'use strict';
    console.log('loading event-card.js');

    // Global variables received from PHP
    const strings = deckerVars.strings;

    /**
     * Returns an ISO UTC string ending with "Z".
     * @param {string} localValue - 'YYYY‑MM‑DDTHH:MM'
     */
    function localToUtc(localValue){
        const d = new Date(localValue);
        return d.toISOString();           // ⇒ 2025‑07‑27T10:00:00.000Z
    }

    /**
     * Converts UTC → value for the <input>.
     *  ─ If 'YYYY‑MM‑DD' (all-day) arrives, return it as is.
 *  ─ If 'YYYY‑MM‑DD HH:MM:SS' arrives, add the “T” and “Z”.
     *  ─ If ISO with “Z” arrives, convert to local and trim seconds.
     */
function utcToLocalValue(utcStr){
    if (!utcStr) return '';

    // all‑day → no changes
    if (/^\d{4}-\d{2}-\d{2}$/.test(utcStr)) return utcStr;

    // allow "YYYY‑MM‑DD HH:MM:SS"
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(utcStr)){
        utcStr = utcStr.replace(' ', 'T') + 'Z';
    }

    const d = new Date(utcStr);          // still in UTC
    const pad = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`
         + `T${pad(d.getHours())}:${pad(d.getMinutes())}`;   // **local**
}



    // Function to delete an event
    function deleteEvent(id, title) {
        if (confirm(strings.confirm_delete_event + ' "' + title + '"')) {
            fetch(wpApiSettings.root + wpApiSettings.versionString + 'decker_event/' + id, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': wpApiSettings.nonce,
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(() => {
                location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                alert(strings.error_deleting_event);
            });
        }
    }

    window.deleteEvent = deleteEvent;


    /**
     * Initialize the event form features.
     * @param {Element} context - DOM context where to initialize (defaults to document).
     */
    function initializeEventCard(context = document) {


     



        const startInput = context.querySelector('#event-start');
        const endInput = context.querySelector('#event-end');

   // ---------- default values ---------- //
        (function setDefaultTimes(){
            if (startInput.value) return;                // an existing event is opened

           // a) if FullCalendar passed a day (click on an empty cell)…
            const modal     = document.querySelector('#event-modal');
            const clickDate = modal?.dataset.tempEventDate
                ? (() => {
                    const [y, m, d] = modal.dataset.tempEventDate.split('-').map(Number);
                   return new Date(y, m - 1, d);  // ← this version uses the local time
                })()
                : null;

            // b) otherwise use the current time
            const base = new Date();

            if (clickDate) {
                base.setFullYear(clickDate.getFullYear());
                base.setMonth(clickDate.getMonth());
                base.setDate(clickDate.getDate());
            }


            // round base to the next :00 or :30 and add 1 h
            base.setSeconds(0,0);
            const m = base.getMinutes();
            if (m % 30) base.setMinutes(m + (30 - m % 30));

            const start = new Date(base);
            const end   = new Date(start.getTime() + 60*60*1000);

            startInput.value = toLocalDatetimeString(start);
            endInput.value   = toLocalDatetimeString(end);
        })();



const isAllDay = context.querySelector('#event-allday')?.checked;

// Set the type of the inputs before assigning values
if (isAllDay) {
    startInput.type = 'date';
    endInput.type = 'date';
} else {
    startInput.type = 'datetime-local';
    endInput.type = 'datetime-local';
}


        // Synchronize dates: the "to" cannot be earlier than the "from"
        if (startInput && endInput) {
            startInput.addEventListener('change', () => {
                endInput.min = startInput.value;
            });

            endInput.addEventListener('change', () => {
                startInput.max = endInput.value;
            });
        }


        let assignedUsersChoices = null;


        document.querySelectorAll('[data-utc]').forEach(input => {
            const raw = input.dataset.utc;
            if (!raw) return;

            // If the input is type="date" leave the date as is,
           // otherwise use the conversion function.
            input.value = (input.type === 'date')
                ? raw
                : utcToLocalValue(raw);
        });

function toLocalDatetimeString(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toLocalDateString(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}



const allDaySwitch = context.querySelector('#event-allday');
// const startInput = context.querySelector('#event-start');
// const endInput = context.querySelector('#event-end');

if (allDaySwitch && startInput && endInput) {
    // Set the correct input types on load based on all-day checkbox
    if (allDaySwitch.checked) {
        startInput.value = startInput.value.split('T')[0] || // coming from datetime-local
                           new Date().toISOString().slice(0, 10);
        endInput.value   = endInput.value.split('T')[0]   ||
                           startInput.value;

        startInput.type = 'date';
        endInput.type = 'date';
    } else {
        startInput.type = 'datetime-local';
        endInput.type = 'datetime-local';
    }

    // Handle changes when the checkbox is toggled manually
 


allDaySwitch.addEventListener('change', () => {
    const isAllDay = allDaySwitch.checked;

    const safeParse = (value) => {
        if (!value) return null;
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            value += 'T00:00';
        }
        const d = new Date(value);
        return isNaN(d.getTime()) ? null : d;
    };

    const currentStart = safeParse(startInput.value);
    const currentEnd   = safeParse(endInput.value || startInput.value);

    if (isAllDay) {
        startInput.type = 'date';
        endInput.type = 'date';

        startInput.value = toLocalDateString(currentStart || new Date());
        endInput.value   = toLocalDateString(currentEnd   || new Date());
    } else {
        startInput.type = 'datetime-local';
        endInput.type   = 'datetime-local';

       // Keep the day and round the time
        const base = currentStart || new Date();
        const now = new Date();
        now.setSeconds(0, 0);

        const minutes = now.getMinutes();
        if (minutes % 30 !== 0) {
            now.setMinutes(minutes + (30 - (minutes % 30)));
        }

       // Combine the original day with the rounded time
        const start = new Date(base);
        start.setHours(now.getHours(), now.getMinutes(), 0, 0);

        const end = new Date(start.getTime() + 60 * 60 * 1000);

        startInput.value = toLocalDatetimeString(start);
        endInput.value   = toLocalDatetimeString(end);
    }
});

}

        // Initialize Choices.js
        const assignedUsersSelect = context.querySelector('#event-assigned-users');
        if (assignedUsersSelect) {
            assignedUsersChoices = new Choices(assignedUsersSelect, { 
                removeItemButton: true,
                searchEnabled: true,
                placeholder: true,
                placeholderValue: strings.select_users_placeholder,
                searchPlaceholderValue: strings.search_users_placeholder
            });
        }


        // Select all users handler
        const selectAllLink = context.querySelector('#event-assigned-users-select-all');
        if (selectAllLink && assignedUsersChoices) {
            selectAllLink.addEventListener('click', function(e) {
                e.preventDefault();
                const allOptions = Array.from(assignedUsersSelect.options).map(opt => ({
                    value: opt.value,
                    label: opt.textContent.trim()
                }));
                assignedUsersChoices.removeActiveItems();
                assignedUsersChoices.setChoices(allOptions, 'value', 'label', true);
                allOptions.forEach(option => {
                    assignedUsersChoices.setChoiceByValue(option.value);
                });
            });
        }

        // Form submission handler
        const form = context.querySelector('#form-event');
        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!form.checkValidity()) {
                    e.stopPropagation();
                    form.classList.add('was-validated');
                    return;
                }

                const formData = new FormData(form);
                const eventId = formData.get('event_id');
                const isEdit = eventId > 0;

                // Build request parameters
                const url = wpApiSettings.root + wpApiSettings.versionString + 'decker_event' + (isEdit ? '/' + eventId : '');
                const method = isEdit ? 'PUT' : 'POST';


const isAllDay = form.querySelector('#event-allday').checked;

const startUtc = isAllDay
    ? startInput.value            // 'YYYY‑MM‑DD'
    : localToUtc(startInput.value);

const endUtc   = isAllDay
    ? endInput.value
    : localToUtc(endInput.value);

const eventData = {
    title : formData.get('event_title'),
    status: 'publish',
    meta  : {
        event_start      : startUtc,
        event_end        : endUtc,
        event_allday     : isAllDay,
        event_category   : formData.get('event_category'),
        event_assigned_users : formData.getAll('event_assigned_users[]').map(Number),
        event_location   : formData.get('event_location'),
        event_url        : formData.get('event_url')
    },
    content: formData.get('event_description')
};

                // const isAllDay = form.querySelector('#event-allday').checked;
                // let startValue = formData.get('event_start');
                // let endValue = formData.get('event_end');

                // // if (isAllDay) {
                // //     startValue = `${startValue}T00:00`;
                // //     endValue = `${endValue}T23:59`;
                // // }


                // // Prepare event data
                // const eventData = {
                //     title: formData.get('event_title'),
                //     status: 'publish',
                //     meta: {
                //         // Convert data to UTC
                //         event_start: startValue,
                //         event_end: endValue,
                //         event_allday: formData.get('event_allday') === 'on',
                //         event_category: formData.get('event_category'),
                //         event_assigned_users: formData.getAll('event_assigned_users[]').map(Number),
                //         event_location: formData.get('event_location'),
                //         event_url: formData.get('event_url')
                //     },
                //     content: formData.get('event_description')
                // };

                try {
                    const response = await fetch(url, {
                        method: method,
                        headers: {
                            'X-WP-Nonce': wpApiSettings.nonce,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(eventData)
                    });

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || strings.error_saving_event);
                    }

                    location.reload();
                } catch (error) {
                    console.error('Error:', error);
                    alert(error.message || strings.error_saving_event);
                }
            });
        }

        // Delete event handler
        context.querySelectorAll('.delete-event').forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                const id = this.dataset.id;
                const title = context.querySelector('#event-title').value || '';
                deleteEvent(id, title);
            });
        });
    }
    
    // Export functions globally so they can be called from HTML
    window.initializeEventCard = initializeEventCard;


// Automatically initialize if the content is loaded directly on the page
    document.addEventListener('DOMContentLoaded', function() {
       // Check if the task form exists directly on the page
        const eventForm = document.querySelector('#event-form');
       if (eventForm && !eventForm.closest('.event-modal')) { // Ensure that it is not inside a modal
            initializeEventCard(document);
        }


    });

})();
