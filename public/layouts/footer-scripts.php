
<script>
  document.addEventListener('DOMContentLoaded', function () {


  // Asignar eventos para "Assign to me"
  document.querySelectorAll('.assign-to-me').forEach((element) => {
	element.addEventListener('click', function (event) {
	  handleAssignToMe(event.currentTarget);
	});
  });

  // Asignar eventos para "Leave task"
  document.querySelectorAll('.leave-task').forEach((element) => {
	element.addEventListener('click', function (event) {
	  handleLeaveTask(event.currentTarget);
	});
  });

  // Asignar eventos para "Mark for today" y "Unmark for today"
  document.querySelectorAll('.mark-for-today, .unmark-for-today').forEach((element) => {
	element.addEventListener('click', function (event) {
	  handleToggleMarkForToday(event.currentTarget);
	});
  });    





	// document.querySelectorAll('.assign-to-me').forEach((element) => {
	//   element.addEventListener('click', function () {
	//     var taskId = element.getAttribute('data-task-id');
	//     fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/assign', {
	//       method: 'POST',
	//       headers: {
	//         'Content-Type': 'application/json',
	//         'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
	//       },
	//       body: JSON.stringify({ user_id: userId })
	//     })
	//     .then(response => {
	//       if (!response.ok) {
	//         throw new Error('Network response was not ok');
	//       }
	//       return response.json();
	//     })
	//     .then(data => {
	//       if (data.success) {
	//         const taskCard = element.closest('.task');
	//         const avatarGroup = taskCard.querySelector('.avatar-group');
	//         const newAvatar = document.createElement('a');
	//         newAvatar.href = 'javascript: void(0);';
	//         newAvatar.className = 'avatar-group-item';
	//         newAvatar.setAttribute('data-bs-toggle', 'tooltip');
	//         newAvatar.setAttribute('data-bs-placement', 'top');
	//         newAvatar.setAttribute('data-bs-original-title', '<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>');
	//         newAvatar.innerHTML = `<img src="<?php echo esc_url( get_avatar_url( get_current_user_id() ) ); ?>" alt="" class="rounded-circle avatar-xs">`;
	//         avatarGroup.appendChild(newAvatar);

	//         // Toggle menu options
	//         element.style.display = 'none';
	//         taskCard.querySelector('.leave-task').style.display = 'block';
	//       } else {
	//         alert('Failed to assign user to task.');
	//       }
	//     })
	//     .catch(error => console.error('Error:', error));
	//   });
	// });

	// document.querySelectorAll('.leave-task').forEach((element) => {
	//   element.addEventListener('click', function () {
	//     var taskId = element.getAttribute('data-task-id');
	//     fetch('<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/leave', {
	//       method: 'POST',
	//       headers: {
	//         'Content-Type': 'application/json',
	//         'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
	//       },
	//       body: JSON.stringify({ user_id: userId })
	//     })
	//     .then(response => {
	//       if (!response.ok) {
	//         throw new Error('Network response was not ok');
	//       }
	//       return response.json();
	//     })
	//     .then(data => {
	//       if (data.success) {
	//         const taskCard = element.closest('.task');
	//         const avatarGroup = taskCard.querySelector('.avatar-group');
	//         const userAvatar = avatarGroup.querySelector(`a[data-bs-original-title="<?php echo esc_attr( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
	//         if (userAvatar) {
	//           userAvatar.remove();
	//         }

	//         // Toggle menu options
	//         element.style.display = 'none';
	//         taskCard.querySelector('.assign-to-me').style.display = 'block';
	//         taskCard.querySelector('.mark-for-today').style.display = 'none';
	//         taskCard.querySelector('.unmark-for-today').style.display = 'none';
	//       } else {
	//         alert('Failed to leave the task.');
	//       }
	//     })
	//     .catch(error => console.error('Error:', error));
	//   });
	// });


	


	// document.querySelectorAll('.mark-for-today, .unmark-for-today').forEach((element) => {
	//     element.addEventListener('click', function () {
	//         var taskId = element.getAttribute('data-task-id');
	//         var shouldMark = element.classList.contains('mark-for-today');
	//         toggleMarkForToday(taskId, shouldMark);
	//     });
	// });


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
			  // element.closest('.task').remove();

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


function handleAssignToMe(element) {
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
	  const taskCard = element.closest('.task');
	  const avatarGroup = taskCard.querySelector('.avatar-group');
	  const newAvatar = document.createElement('a');
	  newAvatar.href = 'javascript: void(0);';
	  newAvatar.className = 'avatar-group-item';
	  newAvatar.setAttribute('data-bs-toggle', 'tooltip');
	  newAvatar.setAttribute('data-bs-placement', 'top');
	  newAvatar.setAttribute('data-bs-original-title', '<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>');
	  newAvatar.innerHTML = `<img src="<?php echo esc_url( get_avatar_url( get_current_user_id() ) ); ?>" alt="" class="rounded-circle avatar-xs">`;
	  avatarGroup.appendChild(newAvatar);

	  // Alternar opciones del menú
	  element.style.display = 'none';
	  taskCard.querySelector('.leave-task').style.display = 'block';
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
	  const taskCard = element.closest('.task');
	  const avatarGroup = taskCard.querySelector('.avatar-group');
	  const userAvatar = avatarGroup.querySelector(`a[data-bs-original-title="<?php echo esc_attr( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
	  if (userAvatar) {
		userAvatar.remove();
	  }

	  // Alternar opciones del menú
	  element.style.display = 'none';
	  taskCard.querySelector('.assign-to-me').style.display = 'block';
	  taskCard.querySelector('.mark-for-today').style.display = 'none';
	  taskCard.querySelector('.unmark-for-today').style.display = 'none';
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
			const card = element.closest('.task');
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



// // Función para marcar o desmarcar una tarea para hoy
// function toggleMarkForToday(taskId, shouldMark) {
//     const action = shouldMark ? 'mark_relation' : 'unmark_relation';
//     const url = '<?php echo esc_url( rest_url( 'decker/v1/tasks/' ) ); ?>' + encodeURIComponent(taskId) + '/' + action;

//     fetch(url, {
//         method: 'POST',
//         headers: {
//             'Content-Type': 'application/json',
//             'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
//         },
//         body: JSON.stringify({ 
//             user_id: userId, 
//             date: '<?php echo gmdate( 'Y-m-d' ); ?>' 
//         })
//     })
//     .then(response => {
//         if (!response.ok) {
//             throw new Error('La respuesta de la red no fue satisfactoria.');
//         }
//         return response.json();
//     })
//     .then(data => {
//         if (data.success) {
//             // Actualiza la interfaz de usuario según la acción
//             const card = document.querySelector(`[data-task-id="${taskId}"]`).closest('.task');
//             const markElement = card.querySelector('.mark-for-today');
//             const unmarkElement = card.querySelector('.unmark-for-today');
//             const closestAvatar = card.querySelector(`.avatar-group-item[aria-label="<?php echo esc_html( get_userdata( get_current_user_id() )->display_name ); ?>"]`);
			
//             if (shouldMark) {
//                 markElement.style.display = 'none';
//                 unmarkElement.style.display = 'block';
//                 if (closestAvatar) {
//                     closestAvatar.classList.add('today');
//                 }
//                 console.log('Tarea marcada para hoy.');
//             } else {
//                 unmarkElement.style.display = 'none';
//                 markElement.style.display = 'block';
//                 if (closestAvatar) {
//                     closestAvatar.classList.remove('today');
//                 }
//                 console.log('Tarea desmarcada para hoy.');
//             }
//         } else {
//             alert(data.data.message || `Error al ${shouldMark ? 'marcar' : 'desmarcar'} la tarea para hoy.`);
//         }
//     })
//     .catch(error => console.error('Error:', error));
// }

</script>