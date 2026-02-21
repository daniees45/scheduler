<?php
echo "User: " . shell_exec('whoami') . "\n";
echo "Root writable: " . (is_writable('../../') ? 'Yes' : 'No') . "\n";
echo "CWD: " . getcwd() . "\n";
?>
