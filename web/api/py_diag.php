<?php
echo "Python Version: " . shell_exec('/usr/local/bin/python3 --version') . "\n";
echo "Python Path: " . shell_exec('which /usr/local/bin/python3') . "\n";
echo "Pip List:\n" . shell_exec('/usr/local/bin/python3 -m pip list') . "\n";
echo "Environment:\n" . shell_exec('env') . "\n";
?>
