<?php
require 'config/auth.php';
require_roles(['admin']);
header('Location: organization.php?tab=units');
exit;
