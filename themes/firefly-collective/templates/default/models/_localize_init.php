<?php

    // theme/template/default/models/_localize.php

    // Template main js data
    wp_localize_script($template_handle, 'templateData', array(
        'obj'   => core_test()
    ));