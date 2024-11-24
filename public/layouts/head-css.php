<?php
// functions.php or your plugin's main file

function my_theme_enqueue_assets() {
	// Enqueue App CSS
	// wp_enqueue_style(
	// 	'app-style',
	// 	plugins_url( 'assets/css/app.min.css', __DIR__ ),
	// 	array(),
	// 	'1.0.0'
	// );

	// // Enqueue Remixicon CSS from CDN
	// wp_enqueue_style(
	// 	'remixicon',
	// 	'https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.5.0/remixicon.min.css',
	// 	array(),
	// 	'4.5.0'
	// );

	// Enqueue DataTables CSS from CDN
	// wp_enqueue_style(
	// 	'datatables-css',
	// 	'https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css',
	// 	array(),
	// 	'1.13.8'
	// );
	// wp_enqueue_style(
	// 	'datatables-searchbuilder-css',
	// 	'https://cdn.datatables.net/searchbuilder/1.6.0/css/searchBuilder.dataTables.min.css',
	// 	array( 'datatables-css' ),
	// 	'1.6.0'
	// );
	// wp_enqueue_style(
	// 	'datatables-select-css',
	// 	'https://cdn.datatables.net/select/1.7.0/css/select.dataTables.min.css',
	// 	array( 'datatables-css' ),
	// 	'1.7.0'
	// );
	// wp_enqueue_style(
	// 	'datatables-buttons-css',
	// 	'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css',
	// 	array( 'datatables-css' ),
	// 	'2.4.2'
	// );

	// // Enqueue Quill CSS from CDN
	// wp_enqueue_style(
	// 	'quill-css',
	// 	'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.snow.min.css',
	// 	array(),
	// 	'2.0.2'
	// );

	// // Enqueue Choices.js CSS from CDN
	// wp_enqueue_style(
	// 	'choices-css',
	// 	'https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css',
	// 	array(),
	// 	'10.0.0'
	// );

	// // Enqueue Config JS
	// wp_enqueue_script(
	// 	'config-js',
	// 	plugins_url( 'assets/js/config.js', __DIR__ ),
	// 	array(),
	// 	'1.0.0',
	// 	true
	// );

	// Enqueue DataTables JS from CDN
	// wp_enqueue_script(
	// 	'datatables-js',
	// 	'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
	// 	array( 'jquery' ),
	// 	'1.13.8',
	// 	true
	// );
	// wp_enqueue_script(
	// 	'datatables-searchbuilder-js',
	// 	'https://cdn.datatables.net/searchbuilder/1.6.0/js/dataTables.searchBuilder.min.js',
	// 	array( 'datatables-js' ),
	// 	'1.6.0',
	// 	true
	// );
	// wp_enqueue_script(
	// 	'datatables-select-js',
	// 	'https://cdn.datatables.net/select/1.7.0/js/dataTables.select.min.js',
	// 	array( 'datatables-js' ),
	// 	'1.7.0',
	// 	true
	// );
	// wp_enqueue_script(
	// 	'datatables-buttons-js',
	// 	'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js',
	// 	array( 'datatables-js' ),
	// 	'2.4.2',
	// 	true
	// );

	// // Enqueue Quill JS from CDN
	// wp_enqueue_script(
	// 	'quill-js',
	// 	'https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.min.js',
	// 	array(),
	// 	'2.0.2',
	// 	true
	// );

	// // Enqueue Choices.js from CDN
	// wp_enqueue_script(
	// 	'choices-js',
	// 	'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js',
	// 	array(),
	// 	'10.0.0',
	// 	true
	// );

	// Enqueue Custom Inline Script for userId
	wp_add_inline_script(
		'config-js',
		'const userId = ' . get_current_user_id() . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'my_theme_enqueue_assets' );

show_admin_bar( false );
function my_theme_manage_admin_bar() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// Show the admin bar in debug mode
		show_admin_bar( false );
	} else {
		// Hide the admin bar otherwise
		show_admin_bar( false );
	}
}
add_action( 'after_setup_theme', 'my_theme_manage_admin_bar' );

wp_head();

?>
<!--<script src="<?php echo plugins_url( 'assets/js/config.js', __DIR__ ); ?>"></script>-->
<?php
/*
// // Show the admin bar (just on DEBUG mode)
// if ( defined('WP_DEBUG') && WP_DEBUG ) {
// wp_head();
show_admin_bar( false );
// }
?>

<script type="text/javascript">
	const userId = <?php echo get_current_user_id(); ?>;
</script>
<!-- Theme Config Js -->
<script src="<?php echo plugins_url( 'assets/js/config.js', __DIR__ ); ?>"></script>

<!-- TO:DO Already loaded in app.min.css, maybe we should fix this -->
<!-- Boostrap -->
<!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"> -->

<!-- App css -->
<link href="<?php echo plugins_url( 'assets/css/app.min.css', __DIR__ ); ?>" rel="stylesheet" type="text/css" id="app-style" />

<!-- Icons css -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/remixicon/4.5.0/remixicon.min.css" integrity="sha512-T7lIYojLrqj7eBrV1NvUSZplDBi8mFyIEGFGdox8Bic92Col3GVrscbJkL37AJoDmF2iAh81fRpO4XZukI6kbA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Datatables CSS CDN -->
<link rel='stylesheet' href='https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css' type='text/css' media='all'/>
<link rel='stylesheet' href='https://cdn.datatables.net/searchbuilder/1.6.0/css/searchBuilder.dataTables.min.css' type='text/css' media='all'/>
<link rel='stylesheet' href='https://cdn.datatables.net/select/1.7.0/css/select.dataTables.min.css' type='text/css' media='all'/>
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">


<!-- Quill -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/2.0.2/quill.snow.min.css" integrity="sha512-UmV2ARg2MsY8TysMjhJvXSQHYgiYSVPS5ULXZCsTP3RgiMmBJhf8qP93vEyJgYuGt3u9V6wem73b11/Y8GVcOg==" crossorigin="anonymous" referrerpolicy="no-referrer" />

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

*/