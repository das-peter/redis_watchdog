<?php

/**
 * Menu callback; displays a listing of log messages.
 *
 * Messages are truncated at 56 chars. Full-length message could be viewed at
 * the message details page.
 */
function redis_watchdog_overview() {
  $rows = array();
  $classes = array(
    WATCHDOG_DEBUG     => 'dblog-debug',
    WATCHDOG_INFO      => 'dblog-info',
    WATCHDOG_NOTICE    => 'dblog-notice',
    WATCHDOG_WARNING   => 'dblog-warning',
    WATCHDOG_ERROR     => 'dblog-error',
    WATCHDOG_CRITICAL  => 'dblog-critical',
    WATCHDOG_ALERT     => 'dblog-alert',
    WATCHDOG_EMERGENCY => 'dblog-emerg',
  );

  drupal_add_css(drupal_get_path('module', 'dblog') . '/dblog.css');
  drupal_add_css(drupal_get_path('module', 'redis_watchdog') . '/redis_watchdog.css');

  $header = array(
    '', // Icon column.
    array('data' => t('Type'), 'field' => 'type'),
    array('data' => t('Date'), 'field' => 'timestamp', 'sort' => 'desc'), t('Message'),
    array('data' => t('User'), 'field' => 'user'),
    array('data' => t('Operations')),
  );

  // Use tablesort.
  $order = tablesort_get_order($header);
  $direction = tablesort_get_sort($header);

  $log = RedisLog::getInstance();
  if (!$log->isReady()) {
    return t('Logger is not ready. Please check the configuration. %exception', array('%exception' => (string) $log->getException()));
  }
  $limit = 50;
  $page = pager_default_initialize($log->count(), $limit);
  foreach ($log->getMultiple($limit, $page * $limit, $order['sql'], $direction) as $log) {
    $rows[] = array(
      'data' => array(
        // Cells.
        array('class' => 'icon'),
        l(t($log['type']), 'admin/reports/redislog/event/' . $log['wid']),
        format_date($log['timestamp'], 'short'),
        theme('redis_watchdog_message', array('event' => $log, 'link' => TRUE)),
        theme('username', array('account' => $log['user'])),
        filter_xss($log['link']),
      ),
      // Attributes for tr.
      'class' => array(drupal_html_class('dblog-' . $log['type']), $classes[$log['severity']]),
    );
  }

  $build['redis_watchdog_filter_form'] = drupal_get_form('redis_watchdog_filter_form');
  $build['redis_watchdog_clear_log_form'] = drupal_get_form('redis_watchdog_clear_log_form');

  $build['redis_watchdog_table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#attributes' => array('id' => 'admin-dblog'),
    '#empty' => t('No log messages available.'),
  );
  $build['redis_watchdog_pager'] = array('#theme' => 'pager');

  return $build;
}

/**
 * Menu callback; displays details about a log message.
 */
function redis_watchdog_event($id) {
  $log = RedisLog::getInstance();

  drupal_add_css(drupal_get_path('module', 'dblog') . '/dblog.css');
  drupal_add_css(drupal_get_path('module', 'redis_watchdog') . '/redis_watchdog.css');

  if ($result = $log->get($id)) {
    $severity = watchdog_severity_levels();

    $rows = array(
      array(
        array('data' => t('Type'), 'header' => TRUE),
        t($result['type']),
      ),
      array(
        array('data' => t('Date'), 'header' => TRUE),
        format_date($result['timestamp'], 'long'),
      ),
      array(
        array('data' => t('User'), 'header' => TRUE),
        theme('username', array('account' => $result['user'])),
      ),
      array(
        array('data' => t('Location'), 'header' => TRUE),
        l($result['location'], $result['location']),
      ),
      array(
        array('data' => t('Referrer'), 'header' => TRUE),
        l($result['referer'], $result['referer']),
      ),
      array(
        array('data' => t('Message'), 'header' => TRUE),
        theme('redis_watchdog_message', array('event' => $result)),
      ),
      array(
        array('data' => t('Severity'), 'header' => TRUE),
        $severity[$result['severity']],
      ),
      array(
        array('data' => t('Hostname'), 'header' => TRUE),
        check_plain($result['hostname']),
      ),
      array(
        array('data' => t('Operations'), 'header' => TRUE),
        $result['link'],
      ),
    );

    // Add stack / backtrace if available.
    $backtrace_formatter = (is_callable('format_stacktrace')) ? 'format_stacktrace' : 'format_backtrace';
    if ((!empty($result['variables']['!stacktrace'])) && is_callable($backtrace_formatter)) {
      $rows[] = array(
        array('data' => t('Stacktrace'), 'header' => TRUE),
        $backtrace_formatter($result['variables']['!stacktrace']),
      );
    }
    if ((!empty($result['variables']['!backtrace'])) && is_callable($backtrace_formatter)) {
      $rows[] = array(
        array('data' => t('Backtrace'), 'header' => TRUE),
        $backtrace_formatter($result['variables']['!backtrace']),
      );
    }


    return theme('table', array(
      'rows' => $rows,
      'attributes' => array('class' => array('redis_watchdog-event')),
    ));
  }
  else {
    return '';
  }
}

/**
 * List redis_watchdog administration filters that can be applied.
 */
function redis_watchdog_filters() {
  $filters = array();

  foreach (_redis_watchdog_get_message_types() as $type) {
    $types[$type] = t($type);
  }

  if (!empty($types)) {
    $filters['type'] = array(
      'title' => t('Type'),
      'where' => "w.type = ?",
      'options' => $types,
    );
  }

  $filters['severity'] = array(
    'title' => t('Severity'),
    'where' => 'w.severity = ?',
    'options' => watchdog_severity_levels(),
  );

  return $filters;
}

/**
 * Returns HTML for a log message.
 *
 * @param $variables
 *   An associative array containing:
 *   - event: An object with at least the message and variables properties.
 *   - link: (optional) Format message as link, event->wid is required.
 *
 * @ingroup themeable
 */
function theme_redis_watchdog_message($variables) {
  $event = $variables['event'];
  $message = isset($event['message']) ? $event['message'] : '';
  $vars = (isset($event['variables']) && is_array($event['variables'])) ? $event['variables'] : array();
  // Remove array variables.
  unset($vars['!backtrace'], $vars['!stacktrace']);

  $output = t($message, $vars);

  if (!empty($variables['link']) && isset($event['wid'])) {
    // Truncate message to 56 chars.
    $output = truncate_utf8(filter_xss($output, array()), 56, TRUE, TRUE);
    $output = l($output, 'admin/reports/redislog/event/' . $event['wid'], array('html' => TRUE));
  }

  return $output;
}

/**
 * Return form for redis_watchdog administration filters.
 *
 * @ingroup forms
 * @see redis_watchdog_filter_form_submit()
 * @see redis_watchdog_filter_form_validate()
 */
function redis_watchdog_filter_form($form) {
  $filters = redis_watchdog_filters();

  $form['filters'] = array(
    '#type' => 'fieldset',
    '#title' => t('Filter log messages'),
    '#collapsible' => TRUE,
    '#collapsed' => empty($_SESSION['redis_watchdog_overview_filter']),
  );
  foreach ($filters as $key => $filter) {
    $form['filters']['status'][$key] = array(
      '#title' => $filter['title'],
      '#type' => 'select',
      '#multiple' => TRUE,
      '#size' => 8,
      '#options' => $filter['options'],
    );
    if (!empty($_SESSION['redis_watchdog_overview_filter'][$key])) {
      $form['filters']['status'][$key]['#default_value'] = $_SESSION['redis_watchdog_overview_filter'][$key];
    }
  }

  $form['filters']['actions'] = array(
    '#type' => 'actions',
    '#attributes' => array('class' => array('container-inline')),
  );
  $form['filters']['actions']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Filter'),
  );
  if (!empty($_SESSION['redis_watchdog_overview_filter'])) {
    $form['filters']['actions']['reset'] = array(
      '#type' => 'submit',
      '#value' => t('Reset'),
    );
  }

  return $form;
}

/**
 * Validate result from redis_watchdog administration filter form.
 */
function redis_watchdog_filter_form_validate($form, &$form_state) {
  if ($form_state['values']['op'] == t('Filter') && empty($form_state['values']['type']) && empty($form_state['values']['severity'])) {
    form_set_error('type', t('You must select something to filter by.'));
  }
}

/**
 * Process result from redis_watchdog administration filter form.
 */
function redis_watchdog_filter_form_submit($form, &$form_state) {
  $op = $form_state['values']['op'];
  $filters = redis_watchdog_filters();
  switch ($op) {
    case t('Filter'):
      foreach ($filters as $name => $filter) {
        if (isset($form_state['values'][$name])) {
          $_SESSION['redis_watchdog_overview_filter'][$name] = $form_state['values'][$name];
        }
      }
      break;

    case t('Reset'):
      $_SESSION['redis_watchdog_overview_filter'] = array();
      break;
  }
  return 'admin/reports/redis_watchdog';
}

/**
 * Return form for redis_watchdog clear button.
 *
 * @ingroup forms
 * @see redis_watchdog_clear_log_submit()
 */
function redis_watchdog_clear_log_form($form) {
  $form['redis_watchdog_clear'] = array(
    '#type' => 'fieldset',
    '#title' => t('Clear log messages'),
    '#description' => t('This will permanently remove the log messages from the database.'),
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  );
  $form['redis_watchdog_clear']['clear'] = array(
    '#type' => 'submit',
    '#value' => t('Clear log messages'),
    '#submit' => array('redis_watchdog_clear_log_submit'),
  );

  return $form;
}

/**
 * Submit callback: clear database with log messages.
 */
function redis_watchdog_clear_log_submit() {
  $_SESSION['redis_watchdog_overview_filter'] = array();
  $log = RedisLog::getInstance();
  $log->clear();

  drupal_set_message(t('Redis log cleared.'));
}

/**
 * Page callback: Shows the most frequent log messages of a given event type.
 *
 * Messages are not truncated on this page because events detailed herein do not
 * have links to a detailed view.
 *
 * @param string $type
 *   Type of database log events to display (e.g., 'search').
 *
 * @return array
 *   A build array in the format expected by drupal_render().
 *
 * @see dblog_menu()
 */
function redis_watchdog_top($type) {

  $header = array(
    array('data' => t('Count'), 'field' => 'count', 'sort' => 'desc'),
    array('data' => t('Message'), 'field' => 'message'),
  );

  // Use tablesort.
  $order = tablesort_get_order($header);
  $direction = tablesort_get_sort($header);

  $log = RedisLog::getInstance();
  $limit = 30;
  $page = pager_default_initialize(count($log->countTopAll($type)), $limit);
  $rows = array();
  foreach ($log->getTop($type, $limit, $page * $limit, $order['sql'], $direction) as $log) {
    $rows[] = array(
      $log['count'],
      theme('redis_watchdog_message', array('event' => $log, 'link' => TRUE)),
    );
  }

  $build['redis_watchdog_top_table']  = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No log messages available.'),
  );
  $build['redis_watchdog_top_pager'] = array('#theme' => 'pager');

  return $build;
}
