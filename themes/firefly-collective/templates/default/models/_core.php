<?php

    // theme/template/default/models/core.php

    function core_test() {
        $obj = new stdClass();
        $obj->message = "Hello Worlds!";
        return $obj;
    }