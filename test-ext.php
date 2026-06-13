<?php echo "curl:"; echo extension_loaded("curl")?"YES":"NO"; echo " openssl:"; echo extension_loaded("openssl")?"YES":"NO"; echo " error_msg:"; $e=error_get_last(); echo $e?"yes":"no"; ?>
