<?php
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

$config = [
  'settings' => [
    'displayErrorDetails' => false,
  ],
];

require 'vendor/autoload.php';

date_default_timezone_set('UTC');

$app = new \Slim\App($config);

$app->get('/', 'api_hint');
$app->get('/post/{id}', 'get_post');
$app->get('/posts/{until}', 'get_posts');
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

function get_post(Request $request, Response $response, $args) {
  $db = new PbDB();
  $id = (int)$args['id'];
  $timestamp = time();
  $sql = "SELECT id, content, timestamp FROM pastes WHERE id=:id AND age>:timestamp";
  $stmt = $db->prepare($sql);
  $stmt->bindParam('id', $id);
  $stmt->bindParam('timestamp', $timestamp);
  $result = $stmt->execute();
  $row = $result->fetchArray(SQLITE3_ASSOC);
  $db->close();
  if($row) {
    return $response->withJson($row);
  } else {
    return $response->withJson(array("error" => "Paste does not exists!!"), 404);
  }
}

function get_posts(Request $request, Response $response, $args) {
  $db = new PbDB();
  $until = (int)$args['until'];
  $timestamp = time();
  $sql = "SELECT id, content, timestamp FROM pastes WHERE timestamp<:until AND age>:timestamp ORDER BY timestamp DESC LIMIT 10";
  $stmt = $db->prepare($sql);
  $stmt->bindParam('until', $until);
  $stmt->bindParam('timestamp', $timestamp);
  $result = $stmt->execute();
  $rows = array();
  $i = 0;
  while($row = $result->fetchArray(SQLITE3_ASSOC)) {
    // cut paste's body after 10 lines. it is just a preview.
    $row['content'] = preg_replace ('~((.*?\x0A){10}).*~s', '\\1', $row['content']);
    $rows[$i] = $row;
    $i++;
  }
  $db->close();
  if(count($rows)){
    return $response->withJson($rows);
  } else {
    return $response->withJson(array("error" => "No more pastes!"), 416);
  }
}

function add_post(Request $request, Response $response, $args) {
  $db = new PbDB();
  $timestamp = time();
  $paste = (object)$request->getParsedBody();
  if(!isset($paste->age)) {
    $paste->age = strtotime('+1 week');
  } else {
    $paste->age = strtotime($paste->age);
  }
  if(!isset($paste->content)) {
    $db->close();
    return $response->withJson(array("error" => "Empty paste!"), 204);
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
    if($request->getContentType() == 'application/json') {
      return $response->withJson(array("url" => $url));
    } else {
      return $response->getBody()->write($url);
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

