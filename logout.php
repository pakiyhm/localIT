<?php
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
logout();
header('Location: index.php');
exit;
