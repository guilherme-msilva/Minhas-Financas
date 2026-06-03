<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

if (!isset($_SESSION['ocultar_valores'])) {
    $_SESSION['ocultar_valores'] = false;
}

// Alterna o valor
$_SESSION['ocultar_valores'] = !$_SESSION['ocultar_valores'];

header('Content-Type: application/json');
echo json_encode([
    'success' => true, 
    'ocultar_valores' => $_SESSION['ocultar_valores']
]);
