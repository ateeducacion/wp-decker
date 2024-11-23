
<?php

// // Show the admin bar (just on DEBUG mode)
// if ( defined('WP_DEBUG') && WP_DEBUG ) {
//     wp_head();
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

