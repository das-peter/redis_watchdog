# Redis Watchdog

This module provides a logging backend for the Redis key-value store, as well as 
a dblog-like user interface to view watchdog entries.

## Why

The problem with the standard dblog module is that logging:

 * Is slower - below a test run to generate 100'000 log entries:
    * dblog: 83.3512 seconds 
    * redis_watchdog: 44.8564 seconds 
 * It doesn't block the DB! If you've multiple requests in paralell the Redis 
Watchdog wont lock your db.

# Requirements

**Requires** the [PhpRedis](https://github.com/phpredis/phpredis) php extension.

# Installation

**It is strongly recommended you run redis_watchdog on a dedicated redis instance
with special persistency / memory limit settings.**

## Create dedicated redis instance

1. Create a new config file `/etc/redis/redis-redis_watchdog.conf` (copied from `/etc/redis/redis.conf`) and change these fields in the new config
    * pidfile
    * port
    * logfile
    * dir (for the default db)
2. Create a new file `/etc/init.d/redis-server-redis_watchdog` (copied from the file `/etc/init.d/redis-server`) and change these fields in the new file
    * name
    * pidfile (same as in the config file in step 1)
    * deamon_args (the path to the config file in step 1).
3. Create the needed directory `mkdir /var/lib/redis-redis_watchdog`
4. Set the proper rights `chown redis:redis /var/lib/redis-redis_watchdog`
5. Make the new file executable: `chmod +x /etc/init.d/redis-server-redis_watchdog`
6. Register the new deamon: `update-rc.d redis-server-redis_watchdog defaults`

## Redis Watchdog configuration options

## Redis 2.x

* Redis host: `$conf['redis_watchdog_host']`
* Redis port: `$conf['redis_watchdog_port']`
* Redis database: `$conf['redis_watchdog_base']`
* Redis password: `$conf['redis_watchdog_password']`
* Redis host: `$conf['redis_watchdog_socket']`
* Site key prefix: `$conf['redis_watchdog_prefix']` - fallback `$conf['cache_prefix']`

## Redis 3.x

* Redis host: `$conf['redis_servers']['watchdog']['host']`
* Redis port: `$conf['redis_servers']['watchdog']['port']`
* Redis database: `$conf['redis_servers']['watchdog']['base']`
* Redis password: `$conf['redis_servers']['watchdog']['password']`
* Redis host: `$conf['redis_servers']['watchdog']['socket']`
* Site key prefix: `$conf['redis_watchdog_prefix']` - fallback `$conf['cache_prefix']`

If those settings aren't given the configuration falls back to use the 
respective `redis_client_` configuration options. See the readme of the redis
module for further information.  
If no configuration options are given the defaults from the class `Redis_Client`
will be used.
