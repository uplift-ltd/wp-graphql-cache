<?php

declare(strict_types=1);

namespace WPGraphQL\Extensions\Cache;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Server\OperationParams;
use WPGraphQL\Extensions\Cache\Backend\AbstractBackend;

/**
 * Class that takes care of caching of full queries
 */
class QueryCache extends AbstractCache
{

    static protected $hits = 0;
    static protected $misses = 0;
    static protected $headers_sent = false;

    /**
     * GraphQL Query name this cache should match against
     */
    protected $query_name = null;

    /**
     * True when running againts matched query name
     */
    protected $match = false;

    function __construct($config)
    {
        parent::__construct($config);
        $this->query_name = $config['query_name'];
    }

    /**
     * Activate the cache with the given backend if the cache did not have own
     * custom backend.
     */
    function activate(AbstractBackend $backend)
    {
        if (!$this->backend) {
            $this->backend = $backend;
        }

        add_filter('process_graphql_execute_request', [$this, '__filter_do_graphql_request'], 10, 2);

        add_action('graphql_response_set_headers', [
            $this,
            '__action_graphql_response_set_headers',
        ]);
    }

    /**
    * @param ExecutionResult|null $response
    * @param OperationParams $params
    * @return ExecutionResult|array|null
    */
    function __filter_do_graphql_request($response, $params)
    {

        $query = $params->query;

        if (empty($query)) {
            return $response;
        }

        if (!$this->cache_matches_query($params)) {
            return $response;
        }

        $user_id = $this->per_user ? get_current_user_id() : 0;

        $args_hash = empty($params->variables)
            ? 'null'
            : Utils::hash(Utils::stable_string($params->variables));

        $query_hash = Utils::hash($query);

        $this->key = "query-{$this->query_name}-{$user_id}-{$query_hash}-{$args_hash}";

        $this->read_cache();

        if ($this->has_hit()) {
            Utils::log('HIT query cache: ' . $this->key);

            return json_decode($this->get_cached_data() ?? '', true);
        }

        Utils::log('MISS query cache: ' . $this->key);

        $response = do_graphql_request($params->query, $params->operation, $params->variables);

        if (empty($response->errors)) {

            // Save results as pre encoded json
            $this->backend->set(
                $this->zone,
                $this->get_cache_key(),
                new CachedValue(wp_json_encode($response)),
                $this->expire
            );

            Utils::log('Writing QueryCache ' . $this->key);

        }

        return $response;
    }

    function __action_graphql_response_set_headers()
    {

        if (self::$headers_sent) {
            return;
        }

        if (self::$hits && self::$misses) {
            header('x-graphql-query-cache: MIXED');
        }
        elseif (self::$hits) {
            header('x-graphql-query-cache: HIT');
        }
        else {
            header('x-graphql-query-cache: MISS');
        }

        self::$headers_sent = true;

    }

    /**
     * @param OperationParams $params
     * @return bool
    */
    protected function cache_matches_query($params) {

        $current_name = $params->operation ?: Utils::get_query_name($params->query);

        return $this->query_name === $current_name || $this->query_name === '*';

    }
}
