// Log when this script is loaded
console.log('loading decker-public.js');

document.addEventListener('DOMContentLoaded', function () {
    document.body.addEventListener('click', function (event) {
        if (event.target.closest('.copy-task-url')) {
            event.preventDefault();
            const link = event.target.closest('.copy-task-url');
            const url = link.getAttribute('data-task-url');

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function () {
                    Swal.fire({
                        title: deckerVars.strings.task_url_copied,
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        icon: 'success'
                    });
                }, function (err) {
                    window.prompt(deckerVars.strings.task_url_copy_error, url);
                });
            } else {
                window.prompt(deckerVars.strings.task_url_copy_error, url);
            }
        }
    });
});


// Code for the Gantt chart on tasks
(function () {
	let chart = null;

	/**
	 * Set the height of the canvas dynamically.
	 *
	 * @param {number|null} heightPx - The desired height in pixels, or null to remove the style.
	 */
	function setCanvasHeight(heightPx) {
		const canvas = document.getElementById('work-days-chart');
		if (canvas) {
			canvas.style.height = heightPx ? `${heightPx}px` : '';
		}
	}

	/**
	 * Render the Gantt chart using Chart.js based on taskData.
	 * Avoids re-rendering if the chart already exists.
	 */
	function renderGanttChart() {
		if (typeof Chart === 'undefined' || typeof window.deckerGanttData === 'undefined') {
			return;
		}

		const taskData = window.deckerGanttData;
		if (!taskData.length || chart) return;

		const toMs = d => Date.parse(d); // Convert date string to timestamp (ms).

		// Color palette for users
		const palette = [
			'#0d6efd', '#198754', '#ffc107', '#dc3545',
			'#20c997', '#6f42c1', '#fd7e14', '#0dcaf0'
		];

		const datasets = taskData.map((t, idx) => ({
			label: t.user,
			data: t.dates.map(d => ({ x: toMs(d), y: t.user })),
			showLine: false,
			pointRadius: 5,
			pointHoverRadius: 6,
			backgroundColor: palette[idx % palette.length],
		}));

		// Calculate X axis range (min and max timestamps)
		const allDates = taskData.flatMap(t => t.dates).map(toMs);
		const dayMs = 86400000;
		const minX = Math.min(...allDates) - dayMs;
		const maxX = Math.max(...allDates) + dayMs;

		const canvas = document.getElementById('work-days-chart');
		if (!canvas) return;

		const ctx = canvas.getContext('2d');
		chart = new Chart(ctx, {
			type: 'scatter',
			data: { datasets },
			options: {
				responsive: true,
				maintainAspectRatio: false,
				parsing: false,
				scales: {
					x: {
						type: 'linear',
						min: minX,
						max: maxX,
						title: { display: true },
						ticks: {
							callback: value => new Date(value).toLocaleDateString(undefined, {
								day: '2-digit',
								month: 'short',
							}),
							autoSkip: true,
							maxRotation: 45,
							minRotation: 45,
						},
					},
					y: {
						type: 'category',
						labels: taskData.map(t => t.user),
						offset: true,
					},
				},
				plugins: {
					legend: { position: 'bottom' },
					tooltip: {
						callbacks: {
							label: ctx =>
								`${ctx.dataset.label} – ${new Date(ctx.raw.x).toLocaleDateString()}`,
						},
					},
				},
			},
		});
	}

	/**
	 * Trigger chart rendering and set canvas height when the Gantt tab is shown.
	 */
	document.addEventListener('shown.bs.tab', function (event) {
		if (event.target.getAttribute('href') === '#gantt-tab') {
			const taskData = window.deckerGanttData || [];
			setCanvasHeight(100 + (taskData.length * 25));
			renderGanttChart();
		}
	});

	/**
	 * Restore canvas height when the Gantt tab is hidden (optional cleanup).
	 */
	document.addEventListener('hidden.bs.tab', function (event) {
		if (event.target.getAttribute('href') === '#gantt-tab') {
                        setCanvasHeight(null); // remove the style
		}
	});

	/**
	 * Destroy the chart when the modal is closed, to ensure fresh rendering next time.
	 */
	document.addEventListener('hidden.bs.modal', function () {
		if (chart !== null) {
			chart.destroy();
			chart = null;
		}
	});
})();
