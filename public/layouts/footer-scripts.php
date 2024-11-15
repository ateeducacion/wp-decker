<!-- Vendor js -->
<script
  src="https://code.jquery.com/jquery-3.7.1.min.js"
  integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
  crossorigin="anonymous"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

<!-- Datatables JS CDN -->
<script type="text/javascript" src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/searchbuilder/1.6.0/js/dataTables.searchBuilder.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/select/1.7.0/js/dataTables.select.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script type="text/javascript" src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>


<!-- Quill -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.min.js" integrity="sha512-1nmY9t9/Iq3JU1fGf0OpNCn6uXMmwC1XYX9a6547vnfcjCY1KvU9TE5e8jHQvXBoEH7hcKLIbbOjneZ8HCeNLA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<!-- marked -->
<!-- <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script> -->

<!-- Choices.js -->
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>


<!-- App js -->
<script src="<?php echo plugins_url( '../assets/js/app.js', __FILE__ ); ?>"></script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    
    document.querySelectorAll('.assign-to-me').forEach((element) => {
      element.addEventListener('click', function () {
        var taskId = element.getAttribute('data-task-id');
        fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/assign', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
          },
          body: JSON.stringify({ user_id: userId })
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            const taskCard = element.closest('.card');
            const avatarGroup = taskCard.querySelector('.avatar-group');
            const newAvatar = document.createElement('a');
            newAvatar.href = 'javascript: void(0);';
            newAvatar.className = 'avatar-group-item';
            newAvatar.setAttribute('data-bs-toggle', 'tooltip');
            newAvatar.setAttribute('data-bs-placement', 'top');
            newAvatar.setAttribute('data-bs-original-title', '<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>');
            newAvatar.innerHTML = `<img src="<?php echo esc_url( get_avatar_url( get_current_user_id() ) ); ?>" alt="" class="rounded-circle avatar-xs">`;
            avatarGroup.appendChild(newAvatar);

            // Toggle menu options
            element.style.display = 'none';
            taskCard.querySelector('.leave-task').style.display = 'block';
          } else {
            alert('Failed to assign user to task.');
          }
        })
        .catch(error => console.error('Error:', error));
      });
    });

    document.querySelectorAll('.leave-task').forEach((element) => {
      element.addEventListener('click', function () {
        var taskId = element.getAttribute('data-task-id');
        fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/leave', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
          },
          body: JSON.stringify({ user_id: userId })
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            const taskCard = element.closest('.card');
            const avatarGroup = taskCard.querySelector('.avatar-group');
            const userAvatar = avatarGroup.querySelector(`a[data-bs-original-title="<?php echo esc_attr( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
            if (userAvatar) {
              userAvatar.remove();
            }

            // Toggle menu options
            element.style.display = 'none';
            taskCard.querySelector('.assign-to-me').style.display = 'block';
            taskCard.querySelector('.mark-for-today').style.display = 'none';
            taskCard.querySelector('.unmark-for-today').style.display = 'none';
          } else {
            alert('Failed to leave the task.');
          }
        })
        .catch(error => console.error('Error:', error));
      });
    });


    


    document.querySelectorAll('.mark-for-today, .unmark-for-today').forEach((element) => {
        element.addEventListener('click', function () {
            var taskId = element.getAttribute('data-task-id');
            var shouldMark = element.classList.contains('mark-for-today');
            toggleMarkForToday(taskId, shouldMark);
        });
    });


    document.querySelectorAll('.archive-task').forEach((element) => {
      element.addEventListener('click', function () {
        var taskId = element.getAttribute('data-task-id');
        if (confirm('Are you sure you want to archive this task?')) {
          fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/archive', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
            },
            body: JSON.stringify({ status: 'archived' })
          })
          .then(response => {
            if (!response.ok) {
              throw new Error('Network response was not ok');
            }
            return response.json();
          })
          .then(data => {
            if (data.success) {

              // TO-DO: Maybe will be better just remove the card, but we reload just for better debuggin
              // element.closest('.card').remove();

              // Reload the page if the request was successful
              location.reload();   

            } else {
              alert('Failed to archive task.');
            }
          })
          .catch(error => console.error('Error:', error));
        }
      });
    });


    // Add event listener for "Fix Order" button
    const fixOrderButton = document.getElementById('fix-order-btn');
    if (fixOrderButton) {
      fixOrderButton.addEventListener('click', function () {

      if (confirm('Are you sure you want to fix the order?')) {

          const boardId = fixOrderButton.getAttribute('data-board-id');
          if (boardId) {
            fetch('<?php echo esc_url( rest_url( 'decker/v1/fix-order/' ) ); ?>' + encodeURIComponent(boardId), {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
              }
            })
            .then(response => {
              if (!response.ok) {
                throw new Error('Network response was not ok');
              }
              return response.json();
            })
            .then(data => {
              if (data.success) {
                alert(data.message);
                // Reload the page if the request was successful
                location.reload();              
              }
            })
            .catch(error => console.error('Error:', error));
          } else {
            console.error('Board ID not found.');
          }

        }
      });
    }



  });


// Función para marcar o desmarcar una tarea para hoy
function toggleMarkForToday(taskId, shouldMark) {
    const action = shouldMark ? 'mark_relation' : 'unmark_relation';
    const url = '<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/' + action;

    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
        },
        body: JSON.stringify({ 
            user_id: userId, 
            date: '<?php echo gmdate( 'Y-m-d' ); ?>' 
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('La respuesta de la red no fue satisfactoria.');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Actualiza la interfaz de usuario según la acción
            const card = document.querySelector(`[data-task-id="${taskId}"]`).closest('.card');
            const markElement = card.querySelector('.mark-for-today');
            const unmarkElement = card.querySelector('.unmark-for-today');
            const closestAvatar = card.querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
            
            if (shouldMark) {
                markElement.style.display = 'none';
                unmarkElement.style.display = 'block';
                if (closestAvatar) {
                    closestAvatar.classList.add('today');
                }
                console.log('Tarea marcada para hoy.');
            } else {
                unmarkElement.style.display = 'none';
                markElement.style.display = 'block';
                if (closestAvatar) {
                    closestAvatar.classList.remove('today');
                }
                console.log('Tarea desmarcada para hoy.');
            }
        } else {
            alert(data.data.message || `Error al ${shouldMark ? 'marcar' : 'desmarcar'} la tarea para hoy.`);
        }
    })
    .catch(error => console.error('Error:', error));
}

</script>