<!-- theme/views/app.php -->

<?php

    $active_template = firefly_collective_get_active_template();
    $template_path   = get_template_directory_uri() . '/templates/' . $active_template;
?>

<div id="app-root"></div>

<div id="website-app"></div>
<div id="loader" class="ff-app-loader" aria-hidden="true"><span class="ff-app-loader-ring"></span></div>