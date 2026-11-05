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

if (!class_exists('WP_REST_Request')) {
    /**
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
