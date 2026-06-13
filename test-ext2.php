<?php $e = get_loaded_extensions(); echo in_array("curl", $e) ? "curl:LOADED" : "curl:NOT_LOADED"; echo " | error_log:"; echo ini_get("error_log"); ?>
