<?php
/**
 * File footer-scripts
 *
 * @package    Decker
 * @subpackage Decker/public
 * @author     ATE <ate.educacion@gobiernodecanarias.org>
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

?>
<script>
document.addEventListener('DOMContentLoaded', function () {


  // Bind events for "Assign to me"
  document.querySelectorAll('.assign-to-me').forEach((element) => {
	element.addEventListener('click', function (event) {
		event.preventDefault();
	  handleAssignToMe(event.currentTarget);
	});
  });

  // Bind events for "Leave task"
  document.querySelectorAll('.leave-task').forEach((element) => {
	element.addEventListener('click', function (event) {
		event.preventDefault();
	  handleLeaveTask(event.currentTarget);
	});
  });

  // Bind events for "Mark for today" and "Unmark for today"
  document.querySelectorAll('.mark-for-today, .unmark-for-today').forEach((element) => {
	element.addEventListener('click', function (event) {
		event.preventDefault();
	  handleToggleMarkForToday(event.currentTarget);
	});
  });    


	document.querySelectorAll('.archive-task,.unarchive-task').forEach((element) => {

	  element.removeEventListener('click', archiveTaskHandler);
	  element.addEventListener('click', archiveTaskHandler);

	});


	// Add event listener for "Fix Order" button
	const fixOrderButton = document.getElementById('fix-order-btn');
	if (fixOrderButton) {
	  fixOrderButton.addEventListener('click', function (event) {
		  event.preventDefault();

	  if (confirm('Are you sure you want to fix the order?')) {

		  const boardId = fixOrderButton.getAttribute('data-board-id');
		  if (boardId) {
			fetch('<?php echo esc_url( rest_url( 'decker/v1/fix-order/' ) ); ?>' + encodeURIComponent(boardId), {
			  method: 'POST',
			  headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
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

function archiveTaskHandler(event) {
	event.preventDefault();
	const element = event.currentTarget;
	const taskId = element.getAttribute('data-task-id');
	const isArchived = element.classList.contains('unarchive-task'); // Check if it's already archived

	const newStatus = isArchived ? 'publish' : 'archived';

	Swal.fire({
		title: isArchived ? deckerVars.strings.confirm_unarchive_task_title : deckerVars.strings.confirm_archive_task_title,
		text: isArchived ? deckerVars.strings.confirm_unarchive_task_text : deckerVars.strings.confirm_archive_task_text,
		icon: "warning",
		showCancelButton: true,
		confirmButtonColor: "#d33",
		cancelButtonColor: "#3085d6",
		confirmButtonText: isArchived ? deckerVars.strings.unarchive_task : deckerVars.strings.archive_task,
		cancelButtonText: deckerVars.strings.cancel
	}).then((result) => {
		if (result.isConfirmed) {
			fetch(`${wpApiSettings.root}wp/v2/tasks/${taskId}`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': wpApiSettings.nonce
				},
				body: JSON.stringify({ status: newStatus })
			})
			.then(response => {
				if (!response.ok) throw new Error('Network response was not ok');
				return response.json();
			})
			.then(data => {
				if (data.status === newStatus) {
					Swal.fire({
						title: deckerVars.strings.success,
						text: isArchived ? deckerVars.strings.task_unarchived_success : deckerVars.strings.task_archived_success,
						icon: "success"
					}).then(() => {
						location.reload(); // Reload the page after confirmation
					});
				}
			})
			.catch(error => {
				console.error('Error:', error);
				Swal.fire({
					title: deckerVars.strings.error,
					text: deckerVars.strings.error_archiving_task,
					icon: "error"
				});
			});
		}
	});
}


function archiveTaskHandler_old(event) {
  event.preventDefault();
  const element = event.currentTarget;
  const taskId = element.getAttribute('data-task-id');
  const isArchived = element.classList.contains('unarchive-task'); // Check if it's already archived
  
  const newStatus = isArchived ? 'publish' : 'archived';
  const confirmationMessage = isArchived 
	? 'Are you sure you want to unarchive this task?' 
	: 'Are you sure you want to archive this task?';

  if (confirm(confirmationMessage)) {
	fetch(`${wpApiSettings.root}wp/v2/tasks/${taskId}`, {
	  method: 'POST',
	  headers: {
		'Content-Type': 'application/json',
		'X-WP-Nonce': wpApiSettings.nonce
	  },
	  body: JSON.stringify({
		status: newStatus
	  })
	})
	.then(response => {
	  if (!response.ok) throw new Error('Network response was not ok');
	  return response.json();
	})
	.then(data => {
	  if (data.status === newStatus) {
		// // Update the UI without reloading
		// const card = element.closest('.task');
		// card.classList.toggle('archived-task', newStatus === 'archived');
		
		// // Change the button text
		// element.textContent = newStatus === 'archived' ? 'Unarchive' : 'Archive';
		// element.classList.toggle('unarchive-task', newStatus === 'archived');
		// element.classList.toggle('archive-task', newStatus !== 'archived');
		
		// Opcional: Mostrar feedback visual
		// showToast(newStatus === 'archived' ? 'Task archived' : 'Task unarchived');

			  // Reload the page if the request was successful
			  location.reload();

	  }
	})
	.catch(error => {
	  console.error('Error:', error);
	  alert('Operation failed: ' + error.message);
	});
  }
}

// function archiveTaskHandler_old(event) {
// 	  event.preventDefault();
// 	  const element = event.currentTarget;
// 		var taskId = element.getAttribute('data-task-id');
// 		if (confirm('Are you sure you want to archive this task?')) {
// 		  fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/archive', {
// 			method: 'POST',
// 			headers: {
// 			  'Content-Type': 'application/json',
// 			  'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
// 			},
// 			body: JSON.stringify({ status: 'archived' })
// 		  })
// 		  .then(response => {
// 			if (!response.ok) {
// 			  throw new Error('Network response was not ok');
// 			}
// 			return response.json();
// 		  })
// 		  .then(data => {
// 			if (data.success) {

// 			  // TO-DO: Maybe will be better just remove the card, but we reload just for better debuggin
// 			  // element.closest('.task').remove();

// 			  // Reload the page if the request was successful
// 			  location.reload();   

// 			} else {
// 			  alert('Failed to archive task.');
// 			}
// 		  })
// 		  .catch(error => console.error('Error:', error));
// 		}

// }

function handleAssignToMe(element) {
  var taskId = element.getAttribute('data-task-id');
  fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/assign', {
	method: 'POST',
	headers: {
	  'Content-Type': 'application/json',
	  'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
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
	  const taskCard = element.closest('.task');
	  const avatarGroup = taskCard.querySelector('.avatar-group');
	  const newAvatar = document.createElement('a');
	  newAvatar.href = 'javascript: void(0);';
	  newAvatar.className = 'avatar-group-item position-relative';
	  newAvatar.setAttribute('data-bs-toggle', 'tooltip');
	  newAvatar.setAttribute('data-bs-placement', 'top');
	  newAvatar.setAttribute('data-bs-original-title', '<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>');
	  newAvatar.innerHTML = `<img src="<?php echo esc_url( get_avatar_url( get_current_user_id() ) ); ?>" alt="" class="rounded-circle avatar-xs">`;
	  avatarGroup.appendChild(newAvatar);

	  // Toggle menu options
	element.classList.add('hidden');
	taskCard.querySelector('.leave-task').classList.remove('hidden');
	taskCard.querySelector('.mark-for-today').classList.remove('hidden');

	  // element.style.display = 'none';
	  // taskCard.querySelector('.leave-task').style.display = 'block';
	} else {
	  alert('Failed to assign user to task.');
	}
  })
  .catch(error => console.error('Error:', error));
}

function handleLeaveTask(element) {
  var taskId = element.getAttribute('data-task-id');
  fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/leave', {
	method: 'POST',
	headers: {
	  'Content-Type': 'application/json',
	  'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
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
	  const taskCard = element.closest('.task');
	  const avatarGroup = taskCard.querySelector('.avatar-group');
	  const userAvatar = avatarGroup.querySelector(`a[data-bs-original-title="<?php echo esc_attr( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
	  if (userAvatar) {
		userAvatar.remove();
	  }

	  // Toggle menu options
	element.classList.add('hidden');
	taskCard.querySelector('.assign-to-me').classList.remove('hidden');
	taskCard.querySelector('.mark-for-today').classList.add('hidden');
	taskCard.querySelector('.unmark-for-today').classList.add('hidden');

	  // element.style.display = 'none';
	  // taskCard.querySelector('.assign-to-me').style.display = 'block';
	  // taskCard.querySelector('.mark-for-today').style.display = 'none';
	  // taskCard.querySelector('.unmark-for-today').style.display = 'none';
	} else {
	  alert('Failed to leave the task.');
	}
  })
  .catch(error => console.error('Error:', error));
}

function handleToggleMarkForToday(element) {
  var taskId = element.getAttribute('data-task-id');
  var shouldMark = element.classList.contains('mark-for-today');
  toggleMarkForToday(taskId, shouldMark, element);
}


function toggleMarkForToday(taskId, shouldMark, element) {
	const action = shouldMark ? 'mark_relation' : 'unmark_relation';
	const url = '<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/' + action;

	fetch(url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': '<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>'
		},
		body: JSON.stringify({ 
			user_id: userId, 
			date: '<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>' 
		})
	})
	.then(response => {
		if (!response.ok) {
			throw new Error('Network response was not ok.');
		}
		return response.json();
	})
	.then(data => {
		if (data.success) {
			// Update the user interface based on the action
			const card = element.closest('.task');
			const markElement = card.querySelector('.mark-for-today');
			const unmarkElement = card.querySelector('.unmark-for-today');
			const closestAvatar = card.querySelector(`.avatar-group-item:not(.avatar-group-item-responsable)[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
			
			if (shouldMark) {
		markElement.classList.add('hidden');
		unmarkElement.classList.remove('hidden');
		if (closestAvatar) {
			closestAvatar.classList.add('today');
		}
				// markElement.style.display = 'none';
				// unmarkElement.style.display = 'block';
				// if (closestAvatar) {
				// 	closestAvatar.classList.add('today');
				// }
				console.log('Task marked for today.');
			} else {
		unmarkElement.classList.add('hidden');
		markElement.classList.remove('hidden');
		if (closestAvatar) {
			closestAvatar.classList.remove('today');
		}
				// unmarkElement.style.display = 'none';
				// markElement.style.display = 'block';
				// if (closestAvatar) {
				// 	closestAvatar.classList.remove('today');
				// }
				console.log('Task unmarked for today.');
			}
		} else {
			alert(data.data.message || `Error ${shouldMark ? 'marking' : 'unmakring'} task for today.`);
		}
	})
	.catch(error => console.error('Error:', error));
}

</script>
