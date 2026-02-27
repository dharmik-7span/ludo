<?php

require_once 'functions.php';

// create a new game
function create_game($conn, $body)
{
    $errors = [];
    if (!isset($body['total_players']) || $body['total_players'] === '' || $body['total_players'] === null) {
        $errors['total_players'] = 'Total players is required';
    } else {
        $allowedPlayers = [2, 3, 4];
        if (!in_array((int) $body['total_players'], $allowedPlayers, true)) {
            $errors['total_players'] = 'Total players must be 2, 3, or 4';
        }
    }
    if (!isset($body['player_names']) || $body['player_names'] === '' || $body['player_names'] === null) {
        $errors['player_names'] = 'Player names is required';
    }
    if (count($errors) > 0) {
        api_response('error', $errors, 'Validation errors', 400);
        return;
    }
    $total_players = $body['total_players'];
    $player_names = $body['player_names'];
    $game = create_game_db($conn, ['total_players' => $total_players,'player_names' => $player_names]);
    if (!$game) {
        api_response('error', 'Failed to create game', 'Failed to create game', 500);
        return;
    }
    api_response('success', $game, 'Game created', 200);
}

// get a game
function get_game($conn,$body)
{
    $game_id = validate_game_id($conn, $body['game_id']);
    if (!$game_id) {
        api_response('error', 'Invalid game id', 'Invalid game id', 400);
        return;
    }
    
    $game = get_game_db($conn, $game_id);
    if (!$game) {
        api_response('error', 'Game not found', 'Game not found', 404);
        return;
    }

    $tokens = get_tokens_db($conn, $game_id);
    $tokens_per_player = [];

    if ($tokens) {
        foreach ($tokens as $token) {
            $player_number = $token['player_number'];
            if (!isset($tokens_per_player[$player_number])) {
                $tokens_per_player[$player_number] = [];
            }
            $tokens_per_player[$player_number][] = $token;
        }
    }

    $players = get_players_db($conn, $game_id);
    global $PLAYER_COLORS;
    
    $players_data = [];
    foreach ($players as $player) {
        $player_number = $player['player_number'];
        $players_data[] = [
            'player_number' => $player_number,
            'player_name' => $player['player_name'],
            'color' => $PLAYER_COLORS[$player_number] ?? 'unknown',
            'is_current_turn' => ((int) $game['current_turn'] === $player_number),
            'tokens' => $tokens_per_player[$player_number] ?? [],
        ];
    }

    api_response('success', [
        'game' => [
            'id' => $game['id'],
            'total_players' => $game['total_players'],
            'current_turn' => $game['current_turn'],
            'status' => $game['status'],
            'winner' => $game['winner'] ? $game['winner'] : null,
            'created_at' => $game['created_at'],
            'players' => $players_data,
        ],
    ], 'Game state', 200);
}

function roll_dice($conn, $body)
{
    $player = validate_player($conn, $body);
    if (!$player) {
        api_response('error', 'Invalid player', 'Invalid player', 400);
        return;
    }
    $dice_value = rand(1, 6);
    $dice_roll = create_dice_roll_db($conn, ['game_id' => $player['game_id'], 'player_number' => $player['player_number'], 'dice_value' => $dice_value]);
    if (!$dice_roll) {
        api_response('error', 'Failed to roll dice', 'Failed to roll dice', 500);
        return;
    }
    api_response('success', [
        'dice_roll' => $dice_roll,
        'dice_value' => $dice_value,
    ], 'Dice rolled', 200);
}

function move_token($conn, $body, $dice_value = null)
{
    global $PLAYER_START, $SAFE_SQUARES;

    // validate request data
    $errors = validate_required($body, ['game_id', 'player_number', 'token_number']);
    if (!empty($errors)) {
        api_response('error', $errors, 'Validation errors', 400);
        return;
    }

    $game_id = validate_game_id($conn, $body['game_id']);
    if (!$game_id) {
        api_response('error', 'Invalid game id', 'Invalid game id', 400);
        return;
    }
    $player = validate_player($conn, $body);
    if (!$player) {
        api_response('error', 'Invalid player', 'Invalid player', 400);
        return;
    }
    $token_number  = (int) $body['token_number'];
    
    if ($dice_value === null) {
        if (!isset($body['dice_value'])) {
            api_response('error', 'Dice value is required', 'Dice value is required', 400);
            return;
        }
        $dice_value = (int) $body['dice_value'];
    }
    // dice value must be between 1 and 6
    if ($dice_value < 1 || $dice_value > 6) {
        api_response('error', 'dice_value must be between 1 and 6', 'Invalid dice value', 422);
        return;
    }

    // token number must be between 1 and 4
    if ($token_number < 1 || $token_number > 4) {
        api_response('error', 'token_number must be between 1 and 4', 'Invalid token number', 422);
        return;
    }

    $game = get_game_db($conn, $game_id);
    if (!$game) {
        api_response('error', 'Game not found', 'Game not found', 404);
        return;
    }

    // game must not be finished
    if ($game['status'] === 'finished') {
        api_response('error', 'Game already finished', 'Game already finished', 400);
        return;
    }
    // current turn must be the player's turn
    if ((int) $game['current_turn'] !== (int) $player['player_number']) {
        $msg = "Not your turn! Player {$game['current_turn']}'s turn";
        api_response('error', $msg, $msg, 403);
        return;
    }

    $token = get_single_token_db($conn, $game_id, $player['player_number'], $token_number);
    if (!$token) {
        api_response('error', 'Token not found', 'Token not found', 404);
        return;
    }

    // token must not be finished
    if ((int) $token['is_finished'] === 1) {
        api_response('error', 'Token already finished', 'Token already finished', 400);
        return;
    }

    // get token from position
    $from_position = (int) $token['position'];
    $was_home      = ((int) $token['is_home'] === 1); // token is in home

    // if token is in home and dice value is not 6 then error
    if ($was_home && $dice_value !== 6) {
        api_response('error', 'You must roll a 6 to leave home', 'You must roll a 6 to leave home', 400);
        return;
    }

    // if token is in home then move to start position
    if ($was_home) {
        $to_position = isset($PLAYER_START[$player['player_number']]) ? $PLAYER_START[$player['player_number']] : 1;
        $move_type   = 'enter';
    } else {
        $board_size  = 52;
        $to_position = $from_position + $dice_value;
        // if to position is greater than board size then wrap around
        if ($to_position > $board_size) {
            $to_position = (($to_position - 1) % $board_size) + 1;
        }
        $move_type = 'move';
    }

    // check if to position is a safe square
    $is_safe = in_array($to_position, $SAFE_SQUARES, true) ? 1 : 0;

    $killed        = false;
    $killed_player = null;
    $killed_token  = null;

    if (!$is_safe) {
        // check if there is an opponent token on the to position
        $opponent_token = get_token_on_position_db($conn, $game_id, $player['player_number'], $to_position);
        if ($opponent_token) {
            $killed        = true;
            $killed_player = $opponent_token['player_number'];
            $killed_token  = $opponent_token['token_number'];
            send_token_home_db($conn, $opponent_token['id']);
            $move_type = 'kill';
        }
    }

    update_token_position_db($conn, $token['id'], $to_position, 0, 0, $is_safe);
    create_move_db($conn, [
        'game_id'       => $game_id,
        'player_number' => $player['player_number'],
        'token_number'  => $token_number,
        'from_position' => $from_position,
        'to_position'   => $to_position,
        'killed'        => $killed ? 1 : 0,
    ]);

    $is_finished = false;

    // simple winner check: all 4 tokens of this player finished
    $finished_tokens = count_finished_tokens_db($conn, $game_id, $player['player_number']);
    if ($finished_tokens >= 4) {
        $is_finished = true;
        update_game_winner_db($conn, $game_id, $player['player_number']);
    }

    // update turn: if dice is not 6 and game not finished, move to next player
    $extra_turn = ($dice_value === 6);
    if (!$extra_turn && !$is_finished) {
        $total_players = (int) $game['total_players'];
        $current       = (int) $game['current_turn'];
        $next          = $current + 1;
        if ($next > $total_players) {
            $next = 1;
        }
        update_game_turn_db($conn, $game_id, $next);
        $game['current_turn'] = $next;
    }

    $move_result = [
        'success'       => true,
        'message'       => $killed ? 'Token moved and opponent killed' : 'Token moved',
        'from_position' => $from_position,
        'to_position'   => $to_position,
        'move_type'     => $move_type,
        'is_safe'       => (bool) $is_safe,
        'is_finished'   => $is_finished,
        'killed'        => $killed,
        'killed_player' => $killed_player,
        'killed_token'  => $killed_token,
    ];

    $data = [
        'move' => [
            'player_number' => $player['player_number'],
            'token_number'  => $token_number,
            'dice_value'    => $dice_value,
            'from_position' => $move_result['from_position'],
            'to_position'   => $move_result['to_position'],
            'move_type'     => $move_result['move_type'],
            'is_safe'       => $move_result['is_safe'],
            'is_finished'   => $move_result['is_finished'],
        ],
        'kill'           => null,
        'winner'         => $is_finished ? $player['player_number'] : ($game['winner'] ?: null),
        'game_over'      => $is_finished,
        'extra_turn'     => $extra_turn,
        'updated_tokens' => get_player_tokens_db($conn, $game_id, $player['player_number']),
    ];

    if ($move_result['killed']) {
        $data['kill'] = [
            'killed_player' => $move_result['killed_player'],
            'killed_token'  => $move_result['killed_token'],
        ];
    }

    api_response('success', $data, $move_result['message'], 200);
}