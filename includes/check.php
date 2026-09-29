<?php

session_start();

function require_login() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /websys/login.php");
        exit();
    }
}

function require_role($expected_role) {
    require_login();
    if ($_SESSION['role_name'] !== $expected_role) {
        header("Location: /websys/login.php?error=unauthorized");
        exit();
    }
}