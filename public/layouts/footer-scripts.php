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

    document.querySelectorAll('.mark-for-today').forEach((element) => {
      element.addEventListener('click', function () {
        var taskId = element.getAttribute('data-task-id');
        fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/mark_relation', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
          },
          body: JSON.stringify({ user_id: userId, date: '<?php echo date( 'Y-m-d' ); ?>' })
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            // Toggle menu options
            element.style.display = 'none';
            element.closest('.card').querySelector('.unmark-for-today').style.display = 'block';

            const closestAvatar = element.closest('.card').querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
            if (closestAvatar) {
              closestAvatar.classList.add('today');
            }

          } else {
            alert('Failed to mark task for today.');
          }
        })
        .catch(error => console.error('Error:', error));
      });
    });

    document.querySelectorAll('.unmark-for-today').forEach((element) => {
      element.addEventListener('click', function () {
        var taskId = element.getAttribute('data-task-id');
        fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/unmark_relation', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
          },
          body: JSON.stringify({ user_id: userId, date: '<?php echo date( 'Y-m-d' ); ?>' })
        })
        .then(response => {
          if (!response.ok) {
            throw new Error('Network response was not ok');
          }
          return response.json();
        })
        .then(data => {
          if (data.success) {
            // Toggle menu options
            element.style.display = 'none';
            element.closest('.card').querySelector('.mark-for-today').style.display = 'block';
        
            const closestAvatar = element.closest('.card').querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
            if (closestAvatar) {
              closestAvatar.classList.remove('today');
            }

          } else {
            alert('Failed to unmark task for today.');
          }
        })
        .catch(error => console.error('Error:', error));
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
              element.closest('.card').remove();
            } else {
              alert('Failed to archive task.');
            }
          })
          .catch(error => console.error('Error:', error));
        }
      });
    });
  });
</script>