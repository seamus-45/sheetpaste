<?php
require 'vendor/autoload.php';

date_default_timezone_set('UTC');

$app = new \Slim\Slim();

$app->get('/', 'api_hint');
$app->get('/post/:id', 'get_post');
$app->get('/posts/:until', 'get_posts');
$app->post('/post', 'add_post');
$app->run();

class PbDB extends SQLite3 {
  function __construct() {
    $this->open('pastebin.db');
    if(!$this->sqlite_table_exists('pastes')) {
      $this->create_table();
    }
  }
  function sqlite_table_exists($table) {
    $result = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
    return $result->fetchArray();
  }
  function create_table() {
    $sql = <<<EOF
      CREATE TABLE pastes
      (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
      content TEXT NOT NULL,
      age INTEGER NOT NULL,
      timestamp INTEGER NOT NULL);
EOF;
    $this->exec($sql);
  }
}

function get_posts($until) {
  $db = new PbDB();
  $timestamp = time();
  $sql = "SELECT id, content, timestamp FROM pastes WHERE timestamp<:until AND age>:timestamp ORDER BY timestamp DESC LIMIT 3";
  $stmt = $db->prepare($sql);
  $stmt->bindParam('until', $until);
  $stmt->bindParam('timestamp', $timestamp);
  $result = $stmt->execute();
  $rows = array();
  $i = 0;
  while($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // cut after 10 lines
    $row['content'] = preg_replace ('~((.*?\x0A){10}).*~s', '\\1...', $row['content']);
    $rows[$i] = $row;
    $i++;
  }
  $db->close();
  if(count($rows)){
    echo json_encode($rows);
  } else {
    echo '{"error": "Paste does not exists"}';
  }
}

function get_post($id) {
  $db = new PbDB();
  $timestamp = time();
  $sql = "SELECT id, content, timestamp FROM pastes WHERE id=:id AND age>:timestamp";
  $stmt = $db->prepare($sql);
  $stmt->bindParam('id', $id);
  $stmt->bindParam('timestamp', $timestamp);
  $result = $stmt->execute();
  $row = $result->fetchArray(SQLITE3_ASSOC);
  $db->close();
  if($row) {
    echo json_encode($row);
  } else {
    echo '{"error": "Paste does not exists"}';
  }
}

function add_post() {
  $db = new PbDB();
  $paste = (object) NULL;
  $timestamp = time();
  $request = \Slim\Slim::getInstance()->request();
  $contype = $request->headers['Content-Type'];
  if($contype == 'application/json') {
    $paste = json_decode($request->getBody());
  } else {
    try {
      $paste->content = $request->params('content');
      $paste->age = $request->params('age');
    } finally { }
  }
  if(!isset($paste->age)) {
    $paste->age = strtotime('+1 week');
  } else {
    $paste->age = strtotime($paste->age);
  }
  if(!isset($paste->content)) {
    $db->close();
    echo '{"error": "Empty paste. Content is not set!"}';
    return;
  }
  $expired = get_expired();
  if($expired) {
    $sql = "UPDATE pastes SET content=:content, age=:age, timestamp=:timestamp WHERE id=:id";
  } else {
    $sql = "INSERT INTO pastes (content, age, timestamp) VALUES (:content, :age, :timestamp)";
  }
  $stmt = $db->prepare($sql);
  $stmt->bindParam('content', $paste->content);
  $stmt->bindParam('age', $paste->age);
  $stmt->bindParam('timestamp', $timestamp);
  if($expired) {
    $stmt->bindParam('id', $expired);
  }
  if($stmt->execute()) {
    $url = "http://" . $_SERVER['SERVER_NAME'] . '/#/paste/'; 
    if($expired) {
      $url .= $expired;
    } else {
      $url .= $db->lastInsertRowId();
    }
    if($contype == 'application/json') {
      echo json_encode(array("url" => $url)); 
    } else {
      echo $url;
    }
  }
  $db->close();
}

function get_expired() {
  $db = new PbDB();
  $timestamp = time();
  $sql = "SELECT id FROM pastes WHERE age<:timestamp LIMIT 1";
  $stmt = $db->prepare($sql);
  $stmt->bindParam('timestamp', $timestamp);
  $result = $stmt->execute();
  $expired = $result->fetchArray(SQLITE3_NUM);
  $db->close();
  if($expired) { return $expired[0]; } else { return false; }
}

function api_hint() {
  ob_start();
  include 'api.html';
  $output = ob_get_clean();
  echo $output;
}
?>

