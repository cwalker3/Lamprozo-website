<!-- theme/views/app.php -->

<?php

    $active_template = firefly_collective_get_active_template();
    $template_path   = get_template_directory_uri() . '/templates/' . $active_template;
?>

<div id="app-root"></div>

<div id="website-app"></div>
<img src="<?=$template_path?>/images/loading.gif" id="loader">