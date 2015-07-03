# Configuration options

* Redis host: `$conf['redis_watchdog_host']`
* Redis port: `$conf['redis_watchdog_port']`
* Redis database: `$conf['redis_watchdog_base']`
* Redis password: `$conf['redis_watchdog_password']`
* Redis host: `$conf['redis_watchdog_socket']`
* Site key prefix: `$conf['redis_watchdog_prefix']` - fallback `$conf['cache_prefix']`

If those settings aren't given the configuration falls back to use the 
respective `redis_client_` configuration options. See the readme of the redis
module for further information.
If no configuration options are given the defaults from the class `Redis_Client`
will be used.

**It is strongly recommended you run redis_watchdog on a dedicated redis instance
with special persistency / memory limit settings.**
