<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';
require_once 'ludoService.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (empty($body)) {
        $body = $_POST;
    }
}


switch ($action) {

    case 'create_game':
        create_game($conn, $body);
        break;

    case 'get_game':
        get_game($conn, $body);
        break;

    case 'roll_dice':
        roll_dice($conn, $body);
        break;

    case 'move_token':
        move_token($conn, $body);
        break;
    case '':
        api_response('success', [
            'endpoints' => [
                'POST  api.php?action=create_game',
                'POST  api.php?action=get_game',
                'POST  api.php?action=roll_dice',
                'POST  api.php?action=move_token',
            ]
        ], 'API Endpoints Fatched', 200);
        break;

    default:
        api_response('error', [
            'available_actions' => [
                'create_game', 'get_game', 'roll_dice',
                'move_token'
            ]
        ], "Unknown action: $action", 404);
        break;
}
