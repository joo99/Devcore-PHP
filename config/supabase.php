<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

class Supabase {
    private $client;
    private $url;
    private $key;

    public function __construct() {
        $this->url = $_ENV['SUPABASE_URL'] ?? '';
        $this->key = $_ENV['SUPABASE_SERVICE_ROLE_KEY'] ?? '';

        if (empty($this->url) || empty($this->key)) {
            // Handle error appropriately
        }

        $this->client = new Client([
            'base_uri' => $this->url . '/rest/v1/',
            'headers' => [
                'apikey' => $this->key,
                'Authorization' => 'Bearer ' . $this->key,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ]
        ]);
    }

    public function from($table) {
        return new SupabaseQuery($this->client, $table);
    }

    public function rpc($function, $params = []) {
        try {
            $response = $this->client->post("../rpc/{$function}", [
                'json' => $params
            ]);
            return (object) [
                'data' => json_decode($response->getBody()->getContents(), true),
                'error' => null
            ];
        } catch (\Exception $e) {
            return (object) [
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }
}

class SupabaseQuery {
    private $client;
    private $table;
    private $query = [];

    public function __construct($client, $table) {
        $this->client = $client;
        $this->table = $table;
    }

    public function select($columns = '*') {
        $this->query['select'] = $columns;
        return $this;
    }

    public function insert($data) {
        try {
            $response = $this->client->post($this->table, [
                'json' => $data
            ]);
            return (object) [
                'data' => json_decode($response->getBody()->getContents(), true),
                'error' => null
            ];
        } catch (\Exception $e) {
            return (object) [
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }

    public function update($data) {
        $this->query['data'] = $data;
        return $this;
    }

    public function delete() {
        $this->query['method'] = 'DELETE';
        return $this;
    }

    public function eq($column, $value) {
        $this->query['where'][] = "{$column}=eq.{$value}";
        return $this;
    }

    public function order($column, $options = ['ascending' => true]) {
        $dir = $options['ascending'] ? 'asc' : 'desc';
        $this->query['order'] = "{$column}.{$dir}";
        return $this;
    }

    public function single() {
        $this->query['single'] = true;
        return $this->execute();
    }

    public function execute() {
        try {
            $method = $this->query['method'] ?? 'GET';
            $options = [];
            $url = $this->table;

            $params = [];
            if (isset($this->query['select'])) $params['select'] = $this->query['select'];
            if (isset($this->query['where'])) {
                foreach ($this->query['where'] as $where) {
                    $parts = explode('=', $where);
                    $params[$parts[0]] = $parts[1];
                }
            }
            if (isset($this->query['order'])) $params['order'] = $this->query['order'];

            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }

            $headers = [];
            if (isset($this->query['single'])) {
                $headers['Accept'] = 'application/vnd.pgrst.object+json';
            }

            if ($method === 'PATCH' || $method === 'DELETE') {
                $response = $this->client->request($method, $url, [
                    'json' => $this->query['data'] ?? null,
                    'headers' => $headers
                ]);
            } else {
                $response = $this->client->get($url, [
                    'headers' => $headers
                ]);
            }

            return (object) [
                'data' => json_decode($response->getBody()->getContents(), true),
                'error' => null
            ];
        } catch (\Exception $e) {
            return (object) [
                'data' => null,
                'error' => $e->getMessage()
            ];
        }
    }
}

$supabase = new Supabase();
