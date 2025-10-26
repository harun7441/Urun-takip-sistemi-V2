<?php
// Cookie'leri temizle (logout)
setcookie('logged_in_user', '', time() - 3600, '/');
setcookie('login_time', '', time() - 3600, '/');

// Login sayfasına yönlendir
header('Location: login.php');
exit();
