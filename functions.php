<?php

// Player colors and where each player starts on the board
$PLAYER_COLORS = [1 => 'red', 2 => 'green', 3 => 'yellow', 4 => 'blue'];
$PLAYER_START  = [1 => 1, 2 => 14, 3 => 27, 4 => 40];

// Star squares where you can't be killed
$SAFE_SQUARES = [1, 9, 14, 22, 27, 35, 40, 48];


// Send JSON response and stop
function api_response($type, $data, $message, $code = 200)
{
    http_response_code($code);
    echo json_encode(['type' => $type, 'data' => $data, 'message' => $message], JSON_PRETTY_PRINT);
    exit;
}

// validate fields
function validate_required($body, $fields)
{
    $errors = [];
    foreach ($fields as $field) {
        if (!isset($body[$field]) || $body[$field] === '' || $body[$field] === null) {
            $errors[$field] = $field . ' is required';
        }
    }
    return $errors;
}



// db helper functions
function create_game_db($conn, $data)
{
  $sql = "INSERT INTO games (total_players) VALUES ({$data['total_players']})";
  $result = $conn->query($sql);
  if (!$result) {
    return false;
  }
  $game_id = $conn->insert_id;

  $data['game_id'] = $game_id;
  create_players_db($conn, $data);
  create_tokens_db($conn, $data);
  return $data;
}


function create_players_db($conn, $data)
{
  for ($i = 1; $i <= $data['total_players']; $i++) {
    $sql = "INSERT INTO players (game_id, player_number, player_name, is_active) VALUES ({$data['game_id']}, {$i}, 'Player {$i}', 1)";
    $result = $conn->query($sql);
    if (!$result) {
      return false;
    }
  }
  return true;
}

function create_tokens_db($conn, $data)
{
  $tokensPerPlayer = 4;
  for ($player = 1; $player <= $data['total_players']; $player++) {
    for ($token = 1; $token <= $tokensPerPlayer; $token++) {
      $sql = "INSERT INTO tokens (game_id, player_number, token_number) VALUES ({$data['game_id']}, {$player}, {$token})";
      $result = $conn->query($sql);
      if (!$result) {
        return false;
      }
    }
  }
  return true;
}

function get_game_db($conn, $game_id)
{
  $sql = "SELECT * FROM games WHERE id = {$game_id}";
  $result = $conn->query($sql);
  if (!$result) {
    return null;
  }
  return $result->fetch_assoc();
}

function get_players_db($conn, $game_id)
{
  $sql = "SELECT * FROM players WHERE game_id = {$game_id}";
  $result = $conn->query($sql);
  if (!$result) {
    return null;
  }
  return $result->fetch_all(MYSQLI_ASSOC);
}

function validate_game_id($conn, $game_id)
{
  $sql = "SELECT * FROM games WHERE id = {$game_id}";
  $result = $conn->query($sql);
  if (!$result) {
    return false;
  }
  $game = $result->fetch_assoc();
  if (!$game) {
    return false;
  }
  return $game['id'];
}

function validate_player($conn, $body)
{
  $game_id = validate_game_id($conn, $body['game_id']);
  if (!$game_id) {
    api_response('error', 'Invalid game id', 'Invalid game id', 400);
  }
  $sql = "SELECT * FROM players WHERE game_id = {$game_id} AND player_number = {$body['player_number']}";
  $result = $conn->query($sql);
  if (!$result) {
    return false;
  }
  $player = $result->fetch_assoc();
  if (!$player) {
    return false;
  }
  return $player;
}

function get_tokens_db($conn, $game_id)
{
  $sql = "SELECT * FROM tokens WHERE game_id = {$game_id}";
  $result = $conn->query($sql);
  if (!$result) {
    return null;
  }
  return $result->fetch_all(MYSQLI_ASSOC);
}

function create_dice_roll_db($conn, $data)
{
  $sql = "INSERT INTO dice_rolls (game_id, player_number, dice_value) VALUES ({$data['game_id']}, {$data['player_number']}, {$data['dice_value']})";
  $result = $conn->query($sql);
  if (!$result) {
    return false;
  }
  $dice_roll_id = $conn->insert_id;

  return [
    'id' => $dice_roll_id,
    'game_id' => $data['game_id'],
    'player_number' => $data['player_number'],
    'dice_value' => $data['dice_value'],
    'rolled_at' => date('Y-m-d H:i:s'),
  ];
}

function get_single_token_db($conn, $game_id, $player_number, $token_number)
{
  $sql = "SELECT * FROM tokens WHERE game_id = {$game_id} AND player_number = {$player_number} AND token_number = {$token_number} LIMIT 1";
  $result = $conn->query($sql);
  if (!$result) {
    return null;
  }
  return $result->fetch_assoc();
}

function get_token_on_position_db($conn, $game_id, $current_player, $position)
{
  $sql = "SELECT * FROM tokens WHERE game_id = {$game_id} AND player_number <> {$current_player} AND position = {$position} AND is_finished = 0 LIMIT 1";
  $result = $conn->query($sql);
  if (!$result) {
    return null;
  }
  return $result->fetch_assoc();
}

function send_token_home_db($conn, $token_id)
{
  $sql = "UPDATE tokens SET position = -1, is_home = 1, is_finished = 0, is_safe = 0 WHERE id = {$token_id}";
  return $conn->query($sql);
}

function update_token_position_db($conn, $token_id, $position, $is_home, $is_finished, $is_safe)
{
  $sql = "UPDATE tokens SET position = {$position}, is_home = {$is_home}, is_finished = {$is_finished}, is_safe = {$is_safe} WHERE id = {$token_id}";
  return $conn->query($sql);
}

function create_move_db($conn, $data)
{
  $sql = "INSERT INTO moves (game_id, player_number, token_number, from_position, to_position, killed_opponent, moved_at) VALUES ({$data['game_id']}, {$data['player_number']}, {$data['token_number']}, {$data['from_position']}, {$data['to_position']}, {$data['killed']}, NOW())";
  $result = $conn->query($sql);
  if (!$result) {
    return false;
  }
  return true;
}

function get_player_tokens_db($conn, $game_id, $player_number)
{
  $sql = "SELECT * FROM tokens WHERE game_id = {$game_id} AND player_number = {$player_number}";
  $result = $conn->query($sql);
  if (!$result) {
    return [];
  }
  return $result->fetch_all(MYSQLI_ASSOC);
}

function count_finished_tokens_db($conn, $game_id, $player_number)
{
  $sql = "SELECT COUNT(*) AS finished_count FROM tokens WHERE game_id = {$game_id} AND player_number = {$player_number} AND is_finished = 1";
  $result = $conn->query($sql);
  if (!$result) {
    return 0;
  }
  $row = $result->fetch_assoc();
  return (int) $row['finished_count'];
}

function update_game_winner_db($conn, $game_id, $player_number)
{
  $sql = "UPDATE games SET winner = {$player_number}, status = 'finished', updated_at = NOW() WHERE id = {$game_id}";
  return $conn->query($sql);
}

function update_game_turn_db($conn, $game_id, $next_turn)
{
  $sql = "UPDATE games SET current_turn = {$next_turn}, updated_at = NOW() WHERE id = {$game_id}";
  return $conn->query($sql);
}