<?php
class RedisLog {
  protected $client;
  protected $key;
  protected $types = array();

  /**
   * @var RedisLog
   */
  protected static $instance;

  /**
   * Singleton pattern.
   */
  public static function getInstance($conf = array()) {
    if (!isset(self::$instance)) {
      self::$instance = new RedisLog();
    }
    return self::$instance;
  }

  protected function __construct() {
    global $conf;

    // Build the appropriate config.
    if (empty($conf['redis_watchdog_host'])) {
      $conf['redis_watchdog_host'] = isset($conf['redis_client_host']) ? $conf['redis_client_host'] : Redis_Client::REDIS_DEFAULT_HOST;
    }
    if (empty($conf['redis_watchdog_port'])) {
      $conf['redis_watchdog_port'] = isset($conf['redis_client_port']) ? $conf['redis_client_port'] : Redis_Client::REDIS_DEFAULT_PORT;
    }
    if (!isset($conf['redis_watchdog_base'])) {
      $conf['redis_watchdog_base'] = isset($conf['redis_client_base']) ? $conf['redis_client_base'] : Redis_Client::REDIS_DEFAULT_BASE;
    }
    if (!isset($conf['redis_watchdog_password'])) {
      $conf['redis_watchdog_password'] = isset($conf['redis_client_password']) ? $conf['redis_client_password'] : Redis_Client::REDIS_DEFAULT_PASSWORD;
    }
    if (!isset($conf['redis_watchdog_socket'])) {
      $conf['redis_watchdog_socket'] = isset($conf['redis_client_socket']) ? $conf['redis_client_socket'] : Redis_Client::REDIS_DEFAULT_SOCKET;
    }

    $this->client = Redis_Client::getClientInterface()->getClient(
      $conf['redis_watchdog_host'],
      $conf['redis_watchdog_port'],
      $conf['redis_watchdog_base'],
      $conf['redis_watchdog_password'],
      $conf['redis_watchdog_socket']
    );

    // See if we can fallback to a site-prefix.
    if (!isset($conf['redis_watchdog_prefix'])) {
      $conf['redis_watchdog_prefix'] = '';
      if (isset($conf['cache_prefix'])) {
        $conf['redis_watchdog_prefix'] = $conf['cache_prefix'];
      }
    }
    $this->key = $conf['redis_watchdog_prefix'] . 'drupal:watchdog';
  }

  /**
   * Implements hook_watchdog().
   */
  function log(array $log_entry) {
    // The user object may not exist in all conditions, so 0 is substituted if needed.
    $user_uid = isset($log_entry['user']->uid) ? $log_entry['user']->uid : 0;
    $wid = $this->getId();
    $message = array(
      'wid' => $wid,
      'uid' => $user_uid,
      'type' => substr($log_entry['type'], 0, 64),
      'message' => $log_entry['message'],
      'variables' => serialize($log_entry['variables']),
      'severity' => $log_entry['severity'],
      'link' => substr($log_entry['link'], 0, 255),
      'location' => $log_entry['request_uri'],
      'referer' => $log_entry['referer'],
      'hostname' => substr($log_entry['ip'], 0, 128),
      'timestamp' => $log_entry['timestamp'],
    );
    $message = (object) $message;
    $this->client->hSet($this->key, $wid, serialize($message));
  }


  protected function getId() {
    return $this->client->hIncrBy($this->key, 'counter', 1);
  }

  public function getMessageTypes() {
    if (empty($this->types)) {
      $types = $this->client->get($this->key . ':types');
      $this->types = unserialize($types);
    }
    return $this->types;
  }

  public function get($wid) {
    $result = $this->client->hGet($this->key, $wid);
    return $result ? unserialize($result) : FALSE;
  }

  public function getMultiple($limit = 50, $sort_field = 'wid', $sort_direction = 'DESC') {
    $logs = array();
    $types = array();
    $max_wid = $this->client->hGet($this->key, 'counter');
    $keys = range($max_wid, $max_wid - $limit);
    $res = $this->client->hmGet($this->key, $keys);
    foreach ($res as $entry) {
      $entry = unserialize($entry);
      $logs[] = $entry;
      if (!in_array($entry->type, $types)) {
        $types[] = $entry->type;
      }
    }
    $this->types = $types;

    $this->client->set($this->key . ':types', serialize($types));
    return $logs;
  }
}
