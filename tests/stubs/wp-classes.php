<?php
declare(strict_types=1);

/**
 * The WordPress classes the framework type-hints against.
 *
 * These are real, usable classes rather than records: WP_REST_Request genuinely
 * stores parameters and WP_Error genuinely carries a code, a message and data,
 * because the framework's behaviour depends on those semantics. Stubbing them as
 * empty shells would let a broken implementation pass.
 *
 * Defined after the autoloader so a real WordPress install — should one ever be
 * loaded into this process — wins.
 */

// $wpdb's output-format constants. wpdb is the one WordPress class the framework
// *extends* rather than merely type-hints (Tests\Support\FakeWpdb), so the parent
// has to exist as a real class here. Its own constants have to exist too, since
// FakeWpdb reads ARRAY_A and the real values must not be re-invented.
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!class_exists('wpdb')) {
    /**
     * The surface of wpdb the framework touches, as no-op defaults.
     *
     * Deliberately declares no behaviour: every method is here so a subclass can
     * override it and so a stray call on a bare `wpdb` returns something typed
     * rather than fataling. The real semantics the migrator depends on live in
     * Tests\Support\FakeWpdb, where they are asserted against.
     */
    class wpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = '';

        public int $insert_id = 0;

        public int $rows_affected = 0;

        public int $num_rows = 0;

        public string $last_query = '';

        /**
         * @param string $query
         * @param mixed  ...$args
         */
        public function prepare($query, ...$args)
        {
            return (string) $query;
        }

        /**
         * @param string $query
         * @return int|false
         */
        public function query($query)
        {
            return 0;
        }

        /**
         * @param string $table
         * @param array<string, mixed> $data
         * @param array<int, string>|string|null $format
         * @return int|false
         */
        public function insert($table, $data, $format = null)
        {
            return 0;
        }

        /**
         * @param string $table
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         * @return int|false
         */
        public function update($table, $data, $where, $format = null, $whereFormat = null)
        {
            return 0;
        }

        /**
         * @param string|null $query
         * @param int         $x
         * @param int         $y
         * @return string|null
         */
        public function get_var($query = null, $x = 0, $y = 0)
        {
            return null;
        }

        /**
         * @param string|null $query
         * @param int         $x
         * @return list<string>
         */
        public function get_col($query = null, $x = 0)
        {
            return [];
        }

        /**
         * @param string|null $query
         * @param string      $output
         * @return array<int, mixed>|null
         */
        public function get_results($query = null, $output = OBJECT)
        {
            return [];
        }

        /**
         * @param string|null $query
         * @param string      $output
         * @param int         $y
         * @return array<string, mixed>|null
         */
        public function get_row($query = null, $output = OBJECT, $y = 0)
        {
            return null;
        }
    }
}


if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private array $data = []
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return mixed
         */
        public function get_error_data(string $code = '')
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return (int) ($this->data['status'] ?? 500);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /**
         * @param array<string, string> $headers
         */
        public function __construct(
            private mixed $data = null,
            private int $status = 200,
            private array $headers = []
        ) {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        /**
         * @return array<string, string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Request')) {    /**
     * A stand-in carrying only the surface the framework uses: parameters set
     * from any source, and a header bag.
     */
    class WP_REST_Request
    {
        /** @var array<string, mixed> */
        private array $params = [];

        /** @var array<string, mixed> */
        private array $urlParams = [];

        /** @var array<string, string> */
        private array $headers = [];

        private string $method = 'GET';

        /**
         * @param array<string, mixed> $params
         */
        public function set_params(array $params): void
        {
            foreach ($params as $key => $value) {
                $this->params[(string) $key] = $value;
            }
        }

        /**
         * Set the parameters WordPress matched out of the URL, which the real
         * class keeps separate from body and query parameters.
         *
         * @param array<string, mixed> $params
         */
        public function set_url_params(array $params): void
        {
            $this->urlParams = $params;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_url_params(): array
        {
            return $this->urlParams;
        }

        /**
         * @param mixed $value
         */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function has_param(string $key): bool
        {
            return array_key_exists($key, $this->params);
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_params(): array
        {
            return $this->params;
        }

        public function set_method(string $method): void
        {
            $this->method = strtoupper($method);
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }
    }
}
