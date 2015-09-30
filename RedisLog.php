<?php
/**
 * @file
 * Redis logging.
 */

/**
 * Class RedisLog.
 */
class RedisLog implements Countable {
  /**
   * @var Redis_Client
   */
  protected $client;
  protected $key;
  protected $types = array();

  protected $exception;

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

    try {
      switch (TRUE) {
        case class_exists('Redis_Client_Manager'):
          $redis_client_settings_class = 'Redis_Client_Manager';
          break;

        case class_exists('Redis_Client'):
          $redis_client_settings_class = 'Redis_Client';
          break;

        default:
          throw new Exception('No compatible redis client class found.');
      }

      // Build the appropriate config.
      if (empty($conf['redis_watchdog_host'])) {
        $conf['redis_watchdog_host'] = isset($conf['redis_client_host']) ? $conf['redis_client_host'] : $redis_client_settings_class::REDIS_DEFAULT_HOST;
      }
      if (empty($conf['redis_watchdog_port'])) {
        $conf['redis_watchdog_port'] = isset($conf['redis_client_port']) ? $conf['redis_client_port'] : $redis_client_settings_class::REDIS_DEFAULT_PORT;
      }
      if (!isset($conf['redis_watchdog_base'])) {
        $conf['redis_watchdog_base'] = isset($conf['redis_client_base']) ? $conf['redis_client_base'] : $redis_client_settings_class::REDIS_DEFAULT_BASE;
      }
      if (!isset($conf['redis_watchdog_password'])) {
        $conf['redis_watchdog_password'] = isset($conf['redis_client_password']) ? $conf['redis_client_password'] : $redis_client_settings_class::REDIS_DEFAULT_PASSWORD;
      }
      if (!isset($conf['redis_watchdog_socket'])) {
        $conf['redis_watchdog_socket'] = isset($conf['redis_client_socket']) ? $conf['redis_client_socket'] : $redis_client_settings_class::REDIS_DEFAULT_SOCKET;
      }

      if ($redis_client_settings_class == 'Redis_Client') {
        $this->client = Redis_Client::getClientInterface()->getClient(
          $conf['redis_watchdog_host'],
          $conf['redis_watchdog_port'],
          $conf['redis_watchdog_base'],
          $conf['redis_watchdog_password'],
          $conf['redis_watchdog_socket']
        );
      }
      else {
        $this->client = Redis_Client::getManager()->getClient('watchdog');
      }
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
    catch (Exception $e) {
      $this->exception = $e;
      // Try to log it into the php error log. But don't die!
      error_log((string) $e);
    }
  }

  /**
   * Checks if the logger can be used.
   *
   * @return bool
   *   TRUE if logging is possible.
   */
  public function isReady() {
    return !empty($this->client);
  }

  public function getException() {
    return $this->exception;
  }

  /**
   * Implements hook_watchdog().
   */
  public function log(array $log_entry) {
    // We use a random id which doesn't rely solely on microseconds or similar.
    $wid = uniqid();
    $log_entry['wid'] = $wid;
    $log_entry['hostname'] = $log_entry['ip'];
    $log_entry['location'] = $log_entry['request_uri'];

    $this->deflateItem($log_entry);

    $key = $this->key . ':wid:' . $wid;
    $this->client->hMSet($key, $log_entry);
    $this->client->lPush($this->key . ':wid_list', $key);

    // Log type.
    $this->client->hSet($this->key . ':types', $log_entry['type'], $log_entry['type']);
    // Add type to type list.
    $this->client->lPush($this->key . ':type_list:' . $log_entry['type'], $key);
  }

  public function getMessageTypes($reset = FALSE) {
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
            if ($type = $this->client->hget($key, 'type')) {
              $this->client->hSet($this->key . ':types', $type, $type);
              $this->client->lPush($this->key . ':type_list:' . $type, $key);
              $types[$type] = $type;
            }
          }
        }
        $this->types = $types;
      }
    }
    return $this->types;
  }

  /**
   * Return a single log entry.
   *
   * @param string $wid
   *   The id of the log entry.
   *
   * @return bool|array
   *   Array with the log entry. FALSE if the log entry wasn't found.
   */
  public function get($wid) {
    $result = $this->client->hGetAll($this->key . ':wid:' . $wid);
    if (isset($result['variables'])) {
      $result['variables'] = unserialize($result['variables']);
    }
    return $result ? $result : FALSE;
  }

  /**
   * Returns a list of watchdog entries.
   *
   * Time complexity: O(N) if filter are set O(N log N)
   *
   * @param int $limit
   *   Number of items in the top list.
   * @param int $offset
   *   Offset to start from.
   * @param string $sort_field
   *   Field to sort by - usually timestamp.
   * @param string $sort_direction
   *   The sort direction. ASC / DESC.
   *
   * @return array
   *   List of watchdog entries.
   */
  public function getMultiple($limit = 50, $offset = 0, $sort_field = 'timestamp', $sort_direction = 'desc') {
    $filter = !empty($_SESSION['redis_watchdog_overview_filter']);
    $output = array();

    // Sort the keys in the list by the hash properties.
    $sort_options = array(
      'sort' => strtolower($sort_direction),
      'by' => '*->' . $sort_field,
      'alpha' => TRUE,
    );
    // With no filters we can limit directly.
    if (!$filter) {
      $sort_options['limit'] = array($offset, $limit);
    }
    $keys = $this->client->sort($this->key . ':wid_list', $sort_options);
    // Sometimes sort seems to fail, fallback to php code.
    if (empty($keys) && $this->count()) {
      $it = NULL;
      $keys = array();
      // Time complexity: O(N).
      while (($items = $this->client->scan($it, $this->key . ':wid:*', 1000)) !== FALSE) {
        foreach ($items as $key) {
          if (!isset($keys[$key])) {
            $keys[$key] = $key;
          }
          if (!$filter && count($keys) > ($offset + $limit)) {
            break;
          }
        }
      }
      sort($keys);
    }
    if ($keys) {
      $top = $limit + $offset;
      // Process the ordered items.
      foreach ($keys as $key) {
        if (!$filter || $this->matchFilter($key)) {
          $item = $this->client->hGetAll($key);
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
    if ($filter) {
      $output = array_slice($output, $offset, $limit);
    }

    // Now inflate the items - this saves a ton of time to do this just here.
    foreach ($output as &$item) {
      $this->inflateItem($item);
    }

    return $output;
  }

  /**
   * Checks if an log entry matches the filter criteria in the session.
   *
   * Time complexity: O(1)
   *
   * @param string $key
   *   Key of the log entry to check.
   *
   * @return bool
   *   TRUE if the entry matches.
   */
  protected function matchFilter($key) {
    foreach ($_SESSION['redis_watchdog_overview_filter'] as $property => $values) {
      $values = is_array($values) ? $values : array($values);
      if (!empty($values) && ($prop_val = $this->client->hget($key, $property)) && !in_array($prop_val, $values)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Returns a list of the most occuring log items of a type.
   *
   * @param string $type
   *   The log item type.
   * @param int $limit
   *   Number of items in the top list.
   * @param int $offset
   *   Offset to start from.
   * @param string $sort_field
   *   Field to sort by - usually count.
   * @param string $sort_direction
   *   The sort direction. ASC / DESC.
   *
   * @return array
   *   List with the groupend log entries.
   */
  public function getTop($type, $limit = 30, $offset = 0, $sort_field = 'count', $sort_direction = 'DESC') {
    // Fills $this->topLists[$type];
    $this->countTopAll($type);
    $groups = $this->topLists[$type];
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

  /**
   * Count the number of entries of a specific type.
   *
   * Stores the found watchdog entries in the class variable self::topLists
   * that way this evaluation can be re-used by self::getTop()
   *
   * @param string $type
   *   The type to count.
   *
   * @return int
   *   Number of entries of the given type.
   */
  public function countTopAll($type) {
    if (!isset($this->topLists[$type])) {
      $groups = array();
      // Fetch all known entries and group them by message and variables.
      $off = 0;
      $step = 1000;
      $pos = $step;
      while (($keys = $this->client->lrange($this->key . ':type_list:' . $type, $off, $pos))) {
        $off += $step;
        $pos += $step;
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
      $this->topLists[$type] = $groups;
    }
    return count($this->topLists[$type]);
  }

  /**
   * Removes storage heavy data and takes care of serialize.
   *
   * Removes especially the user object which can be restored later on.
   *
   * @param object $item
   *   The log entry to deflate.
   */
  protected function deflateItem(&$item) {
    // The user object may not exist in all conditions, so 0 is substituted if
    // needed.
    $item['uid'] = isset($item['user']->uid) ? $item['user']->uid : 0;
    // Don't save the whole user object but the user name.
    $item['user'] = !empty($item['user']->name) ? $item['user']->name : variable_get('anonymous', t('Anonymous'));
    $item['variables'] = serialize($item['variables']);

    // Since some redis libraries have issues with data typing ensure we don't
    // have NULL values.
    array_walk($item, function(&$val) {
      if (!is_scalar($val) || is_null($val)) {
        $val = (string) $val;
      }
    });
  }

  /**
   * Restores storage heavy data and takes care of unserialize.
   *
   * Restores especially the user object.
   *
   * @param object $item
   *   The log entry to inflate.
   */
  protected function inflateItem(&$item) {
    // Restore user object.
    // @TODO What do if the user was removed can't be load?
    $item['user'] = user_load($item['uid']);
    // Restore variables.
    $item['variables'] = unserialize($item['variables']);
  }

  /**
   * Returns the number if watchdog entries.
   *
   * Time complexity: O(1) except if count = 0 then O(N) - which still is fast
   * if N = 0.
   *
   * @return int
   *   Number of watchdog entries.
   */
  public function count() {
    // We use the list to count. This is still very fast since:
    // Time complexity: O(1)
    // However, we don't trust 0 items, so check if there's really no items.
    if (!($count = $this->client->lLen($this->key . ':wid_list'))) {
      $this->rebuildKeyList();
      $count = $this->client->lLen($this->key . ':wid_list');
    }
    return $count;
  }

  /**
   * Rebuild the key list of all watchdog entries.
   *
   * Time complexity: O(N)
   */
  public function rebuildKeyList() {
    $unique = array();
    // Delete list and rebuild it.
    $this->client->del($this->key . ':wid_list');
    $it = NULL;
    // Time complexity: O(N).
    while (($items = $this->client->scan($it, $this->key . ':wid:*', 1000)) !== FALSE) {
      foreach ($items as $key) {
        if (!isset($unique[$key])) {
          // Time complexity: O(1).
          $this->client->lPush($this->key . ':wid_list', $key);
          $unique[$key] = $key;
        }
      }
    }
  }

  /**
   * Clears the log.
   *
   * Time complexity: O(N)
   */
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
