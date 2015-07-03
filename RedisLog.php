<?php
/**
 * @file
 * Redis logging.
 */

/**
 * Class RedisLog.
 */
class RedisLog {
  /**
   * @var Redis_Client
   */
  protected $client;
  protected $key;
  protected $types = array();

  /**
   * Keeps the single instance of this class.
   *
   * @var RedisLog
   */
  protected static $instance;

  protected $topLists = array();

  /**
   * Singleton pattern.
   */
  public static function getInstance() {
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
    $this->client->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

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
  public function log(array $log_entry) {
    $wid = $this->getId();
    $log_entry['wid'] = $wid;
    $log_entry['hostname'] = $log_entry['ip'];
    $log_entry['location'] = $log_entry['request_uri'];

    $this->deflateItem($log_entry);

    $key = $this->key . ':wid:' . $wid;
    $this->client->hMSet($key, $log_entry);
    $this->client->lPush($this->key . ':wid_list', $key);

    // Ensure type is collected.
    if (!($types = $this->client->hgetall($this->key . ':types'))) {
      $types = array();
    }
    $types[$log_entry['type']] = $log_entry['type'];
    $this->client->hSet($this->key . ':types', $log_entry['type'], $log_entry['type']);
    // Add type to type list.
    $this->client->lPush($this->key . ':type_list:' . $log_entry['type'], $key);
    $this->types = $types;
  }

  protected function getId() {
    $id = $this->client->incrby($this->key . ':counter', 1);
    $count = $this->count();
    if ($id < $count) {
      $this->client->set($this->key . ':counter', $count);
      $id = $this->client->incrby($this->key . ':counter', 1);
    }
    return $id;
  }

  public function getMessageTypes($reset = TRUE) {
    if (empty($this->types) || $reset) {
      if (!$reset && ($types = $this->client->hgetAll($this->key . ':types'))) {
        $this->types = $types;
      }
      else {
        // Fallback - try to detect types from entries and store them.
        $it = NULL;
        $types = array();
        while (($keys = $this->client->scan($it, $this->key . ':wid:*', 1000)) !== FALSE) {
          foreach ($keys as $key) {
            $item = $this->client->hGetAll($key);
            if (!isset($types[$item['type']])) {
              $this->client->hSet($this->key . ':types', $item['type'], $item['type']);
              $this->client->lPush($this->key . ':type_list:' . $item['type'], $key);
              $types[$item['type']] = $item['type'];
            }
          }
        }
        $this->types = $types;
      }
    }
    return $this->types;
  }

  public function get($wid) {
    $result = $this->client->hGetAll($this->key . ':wid:' . $wid);
    $result['variables'] = unserialize($result['variables']);
    return $result ? $result : FALSE;
  }

  public function getMultiple($limit = 50, $offset = 0, $sort_field = 'wid', $sort_direction = 'desc', $filter_callback = NULL) {
    $filter = function_exists($filter_callback);
    $output = array();

    // Sort the keys in the list by the hash properties.
    $keys = $this->client->sort($this->key . ':wid_list', array(
      'sort' => strtolower($sort_direction),
      'by' => '*->' . $sort_field,
      'alpha' => TRUE,
    ));
    if ($keys) {
      $top = $limit + $offset;
      // Process the ordered items.
      foreach ($keys as $key) {
        $item = $this->client->hGetAll($key);
        if (!$filter || call_user_func($filter_callback, $item)) {
          $this->inflateItem($item);
          $output[] = $item;
          // If we've already reached the maximum amount given paging and offset
          // we can stop further processing.
          if (count($output) >= $top) {
            break;
          }
        }
      }
    }

    // Enforce paging.
    $output = array_slice($output, $offset, $limit);

    return $output;
  }

  public function getTop($type, $limit = 30, $offset = 0, $sort_field = 'count', $sort_direction = 'DESC') {
    $groups = array();
    // Fetch all known entries and group them by message and variables.
    $off = 0;
    $step = 1000;
    while (($keys = $this->client->lrange($this->key . ':type_list:' . $type, $off, $step))) {
      $off += $step;
      foreach ($keys as $key) {
        $item = $this->client->hGetAll($key);
        $group = $item['message'] . ':' . $item['variables'];
        if (!isset($groups[$group])) {
          $this->inflateItem($item);
          $groups[$group] = $item;
          $groups[$group]['count'] = 0;
        }
        $groups[$group]['count']++;
      }
    }

    $output = array();
    foreach ($groups as $group => $item) {
      $sort_key = $group;
      if (isset($item[$sort_field])) {
        $sort_key = $item[$sort_field] . ':' . $group;
      }
      $output[$sort_key] = $item;
    }

    if (strtoupper($sort_direction) == 'DESC') {
      krsort($output);
    }
    else {
      ksort($output);
    }

    $output = array_slice($output, $offset, $limit);
    return $output;
  }

  public function countTopAll($type) {
     return $this->client->llen($this->key . ':type_list:' . $type);
  }

  protected function deflateItem(&$item) {
    // The user object may not exist in all conditions, so 0 is substituted if
    // needed.
    $item['uid'] = isset($item['user']->uid) ? $item['user']->uid : 0;
    // Don't save the whole user object but the user name.
    $item['user'] = !empty($item['user']->name) ? $item['user']->name : variable_get('anonymous', t('Anonymous'));
    $item['variables'] = serialize($item['variables']);
  }

  protected function inflateItem(&$item) {
    // Restore user object.
    $item['user'] = user_load($item['uid']);
    // Restore variables.
    $item['variables'] = unserialize($item['variables']);
  }

  public function count() {
    // If the counter is missing - restore it.
    if (!$this->client->exists($this->key . ':counter')) {
      // Try to fetch it from the key list, we don't trust 0 and rebuild the
      // list bases on a entry scan.
      if (!($count = $this->client->lLen($this->key . ':wid_list'))) {
        $this->rebuildKeyList();
        $count = $this->client->lLen($this->key . ':wid_list');
      }
      $this->client->set($this->key . ':counter', $count);
    }
    return $this->client->get($this->key . ':counter');
  }

  public function rebuildKeyList() {
    $unique = array();
    // Delete list and rebuild it.
    $this->client->del($this->key . ':wid_list');
    $it = NULL;
    while (($items = $this->client->scan($it, $this->key . ':wid:*', 1000)) !== FALSE) {
      foreach ($items as $key) {
        if (!isset($unique[$key])) {
          $this->client->lPush($this->key . ':wid_list', $key);
          $unique[$key] = $key;
        }
      }
    }
  }

  public function clear() {
    // Ensure really nothing is left.
    $it = NULL;
    while (($keys = $this->client->scan($it, $this->key . '*', 100000)) !== FALSE) {
      if ($keys) {
        $this->client->delete($keys);
      }
    }
  }
}
