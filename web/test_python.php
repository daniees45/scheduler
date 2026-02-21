<?php
$cmd = "/usr/local/bin/python3 -c 'import sys; print(sys.executable); print(sys.path); import dateutil; print(\"dateutil imported successfully\")' 2>&1";
$output = shell_exec($cmd);
echo "<pre>$output</pre>";
?>
