<?php

declare(strict_types=1);

namespace WPGraphQL\Extensions\Cache;

use WPGraphQL\Request;

class CacheManager
{
    static $fields = [];

    static $query_caches = [];

    static $backend = null;

    static $initialized = false;

    static $is_active = true;

    static function init()
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_action('graphql_response_set_headers', [
            self::class,
            '__action_graphql_response_set_headers',
        ]);

        add_filter('pre_graphql_execute_request', [self::class, 'execute'], 10, 2);

        add_action('graphql_init', [self::class, '__action_graphql_init']);
    }

    static function assert_initialization()
    {
        if (self::$initialized) {
            return;
        }
        error_log(
            'wp-graphql-cache: Error: registering caches without initializing the plugin. ' .
                'Activate to plugin from wp-admin or call \WPGraphQL\Extensions\Cache\CacheManager::init() ' .
                'before registering the caches.'
        );
    }

    static function __action_graphql_init()
    {
        MeasurePerformance::init();

        /***
         * Initialize the default backend
         */
        self::$backend = apply_filters(
            'graphql_cache_backend',
            new Backend\FileSystem()
        );

        self::$is_active = apply_filters('graphql_cache_active', true);

        if (!self::$is_active) {
            return;
        }

        foreach (self::$fields as $field) {
            $field->activate(self::$backend);
        }

        foreach (self::$query_caches as $query_cache) {
            $query_cache->activate(self::$backend);
        }
    }

    /**
     * Returns true when the graphql has been already initialized and the caching
     * is active
     */
    static function should_activate_now()
    {
        return self::$is_active && did_action('graphql_init');
    }

    static function register_graphql_field_cache($config)
    {
        self::assert_initialization();
        $field = new FieldCache($config);
        self::$fields[] = $field;

        if (self::should_activate_now()) {
            $field->activate(self::$backend);
        }

        return $field;
    }

    static function register_graphql_query_cache($config)
    {
        self::assert_initialization();
        $query_cache = new QueryCache($config);
        self::$query_caches[] = $query_cache;

        if (self::should_activate_now()) {
            $query_cache->activate(self::$backend);
        }

        return $query_cache;
    }


    /**
    * @param null $response
    * @param Request $request
    * @return array mixed
    */
    static function execute($response, $request)
    {

        $user_id = get_current_user_id();
        $params_list = $request->get_params();
        $is_batch = is_array($params_list);

        if (!is_array($params_list)) {
            $params_list = [$params_list];
        }

        // We used this filter to hook into the initial run, but it'll be called again for each
        // individual query in the batch, so we need to remove it here.
        remove_filter('pre_graphql_execute_request', [self::class, 'execute']);

        Utils::log("PROCESSING: " . implode(", ", array_map(function($params) { return $params->operation; }, $params_list)));
        Utils::log("AGENT:      " . $_SERVER['HTTP_USER_AGENT']);

        $responses = [];

        foreach ($params_list as $params) {

            $response = apply_filters( 'process_graphql_execute_request', null, $params );

            if ( null === $response) {
                $response = do_graphql_request($params->query, $params->operation, $params->variables);
            }

            // At this point, odd logic in WPGraphQL will have effectively logged out the user...
            // wp-graphql/src/Request.php::has_authentication_errors() sets the user to 0.
            // To ensure the next query is run with access to the current user, re-add the user here.
            // Note: this bug is only present in batched queries after the first, because WPGraphQL
            // checks for authentication AFTER the query has been resolved
            wp_set_current_user($user_id);

            $responses[] = $response;

        }

        return $is_batch ? $responses : $responses[0];

    }

    /**
     * Set cache status headers for the field caches
     */
    static function __action_graphql_response_set_headers()
    {
        $value = [];

        foreach (self::$fields as $field) {
            if (!$field->has_match()) {
                continue;
            }

            if ($field->has_hit()) {
                $value[] = 'HIT:' . $field->get_field_name();
            } else {
                $value[] = 'MISS:' . $field->get_field_name();
            }
        }

        if (empty($value)) {
            return;
        }

        $value = implode(', ', $value);

        header("x-graphql-field-cache: $value");
    }

    static function clear_zone(string $zone): bool
    {
        return self::$backend->clear_zone($zone);
    }

    static function clear(): bool
    {
        return self::$backend->clear();
    }
}

function register_graphql_field_cache($config)
{
    CacheManager::register_graphql_field_cache($config);
}

if (class_exists('\WP_CLI')) {
    WPCLICommand::init();
}
