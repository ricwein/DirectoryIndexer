<?php

namespace ricwein\Indexer\Network;

use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;

/**
 * class-namespace for direct http manipulation methods
 */
class Http
{
    use HeaderTrait;

    public const SERVER = 'server';
    public const QUERY = 'query';
    public const REQUEST = 'request';
    public const COOKIES = 'cookies';
    public const FILES = 'files';

    /**
     * @var array
     */
    protected array $core = [];

    /**
     * @param array|null &$core
     */
    public function __construct(array &$core = null)
    {
        if ($core !== null) {
            $this->core = &$core;
            return;
        }

        $this->core = [
            static::SERVER => &$_SERVER,
            static::QUERY => &$_GET,
            static::REQUEST => &$_POST,
            static::COOKIES => &$_COOKIE,
            static::FILES => &$_FILES,
        ];
    }

    /**
     * provides public access to private and protected methods as non-static calls
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        return call_user_func_array([$this, $method], $args);
    }

    /**
     * provides public static access to private and protected methods implicitly calling self::instance()
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public static function __callStatic(string $method, array $args)
    {
        return call_user_func_array([(new static()), $method], $args);
    }

    /**
     * @return bool
     */
    protected function isCLI(): bool
    {
        if (in_array(strtolower(PHP_SAPI), ['cli', 'cli-server'], true)) {
            return true;
        }

        if (!$this->has('HTTP_HOST', static::SERVER)) {
            return true;
        }

        return false;
    }

    /**
     * @param string|string[]|null $parameters
     * @param string|string[]|null $searchSources
     * @param mixed $default
     * @param array<string, bool> $options
     * @return mixed
     */
    protected function get($parameters = null, $searchSources = null, $default = null, array $options = [])
    {
        $options = array_merge([
            'sanitize' => true,
            'remove' => false,
        ], $options);

        if ($searchSources === null) {
            $searchSources = array_keys($this->core);
        } else {
            $searchSources = (array)$searchSources;
        }

        // return core-arrays
        if ($parameters === null) {
            $result = [];
            foreach ($searchSources as $source) {
                if (isset($this->core[$source])) {
                    $result = array_merge($result, $this->core[$source]);
                }
            }
            return $result;
        }

        // search and return parameters from core-arrays
        $parameters = (array)$parameters;
        foreach ($parameters as $parameter) {
            foreach ($searchSources as $source) {
                if (isset($this->core[$source][$parameter]) && !empty($this->core[$source][$parameter])) {
                    $result = trim($this->core[$source][$parameter]);

                    if ($options['remove'] === true) {
                        unset($this->core[$source][$parameter]);
                    }

                    $result = ($options['sanitize'] === true ? filter_var($result, FILTER_SANITIZE_STRING) : $result);
                    return empty($result) ? $default : $result;
                }
            }
        }

        return $default;
    }

    protected function getQueryParameters(): ?array
    {
        if (null === $query = $this->get('QUERY_STRING', static::SERVER, null)) {
            return null;
        }

        parse_str($query, $queries);
        return $queries;
    }

    /**
     * @param string $name
     * @return string|null
     */
    protected function getQueryParameter(string $name): ?string
    {
        if (null === $parameters = $this->getQueryParameters()) {
            return null;
        }

        if (!isset($parameters[$name])) {
            return null;
        }

        return $parameters[$name];
    }

    /**
     * @param string|array $parameters
     * @param string|array $searchSources
     * @param array $options
     * @return bool
     */
    protected function has($parameters, $searchSources = null, array $options = []): bool
    {
        $options = array_merge([
            'remove' => false,
        ], $options);

        if ($searchSources === null) {
            $searchSources = array_keys($this->core);
        } else {
            $searchSources = (array)$searchSources;
        }

        // search and return parameters from core-arrays
        $parameters = (array)$parameters;
        foreach ($parameters as $parameter) {
            foreach ($searchSources as $source) {
                if (isset($this->core[$source][$parameter]) && !empty($this->core[$source][$parameter])) {
                    if ($options['remove'] === true) {
                        unset($this->core[$source][$parameter]);
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    protected function isSecure(): bool
    {
        if (strtolower($this->get('X_FORWARDED_PROTO', static::SERVER)) === 'https') {
            return true;
        }

        if (strtolower($this->get('HTTP_X_FORWARDED_PROTO', static::SERVER)) === 'https') {
            return true;
        }

        $secureState = strtolower($this->get('HTTPS', static::SERVER));
        return $secureState !== 'off' && !empty($secureState);
    }

    /**
     * @return string
     */
    protected function getScheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * @param string|null $relativeTo
     * @return string
     */
    protected function getRelativePath(string $relativeTo = null): string
    {
        if (null === $url = $this->get('PHP_SELF', static::SERVER)) {
            return '/';
        }

        if ($relativeTo !== null) {
            $url = substr($url, 0, strpos($url, $relativeTo));
        }

        return '/' . trim($url, '/') . '/';
    }

    /**
     * return server or client ip
     *
     * @param bool $remote
     * @param string $default default-ip
     *
     * @return string
     */
    protected function getIPAddr(bool $remote = true, string $default = '127.0.0.1'): string
    {
        if (!$remote) {
            return $this->get('SERVER_ADDR', static::SERVER, $default); // server address
        }

        return $this->get([
            'REMOTE_ADDR',
            'HTTP_CLIENT_IP', 'HTTP_X_CLIENT_IP',
            'HTTP_FORWARDED_FOR', 'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED', 'HTTP_X_FORWARDED',
        ], self::SERVER, $default);
    }

    /**
     * @param string $default
     * @return string
     */
    protected function getProtocol(string $default): string
    {
        return $this->get('SERVER_PROTOCOL', static::SERVER, $default);
    }

    /**
     * @param string|null $domain
     * @param string|null $locationPath
     * @return string
     */
    protected function getBaseURL(string $domain = null, ?string $locationPath = null): string
    {
        $scheme = $this->getScheme();
        $domain = $domain ?? $this->getDomain();
        $path = dirname($this->get('SCRIPT_NAME', static::SERVER));

        if ($locationPath === null) {
            return "{$scheme}://{$domain}{$path}";
        }

        $locationPath = trim($locationPath, '/');
        if (empty($locationPath)) {
            return "{$scheme}://{$domain}{$path}";
        }

        $path = rtrim($path, '/');
        return "{$scheme}://{$domain}{$path}/{$locationPath}";
    }

    protected function getLocation(?string $locationPath = null): string
    {
        $path = $this->get('REQUEST_URI', static::SERVER);

        if ($locationPath === null) {
            return $path;
        }

        $locationPath = trim($locationPath, '/');

        if (empty($locationPath)) {
            return $path;
        }

        $locationPath = "{$locationPath}/";

        if (false !== $pos = strpos($path, $locationPath)) {
            return substr_replace($path, '', $pos, strlen($locationPath));

        }

        return $path;
    }

    /**
     * @param string|null $domain
     * @param string|null $locationPath
     * @return string
     */
    protected function getPathURL(string $domain = null, ?string $locationPath = null): string
    {
        $scheme = $this->getScheme();
        $domain = $domain ?? $this->getDomain();
        $path = $this->getLocation($locationPath);

        return "{$scheme}://{$domain}{$path}";
    }

    /**
     * @return string
     */
    protected function getDomain(): string
    {
        return $this->get(['SERVER_NAME', 'HTTP_X_ORIGINAL_HOST', 'HTTP_HOST'], ['server']);
    }

    /**
     * @param int $totalSize
     * @return int[] [start, end, length]
     * @throws RuntimeException
     */
    private function parseRange(int $totalSize): array
    {
        $byteRange = $this->get('HTTP_RANGE', static::SERVER, null);

        if ($byteRange === null) {
            return [0, $totalSize - 1, $totalSize];
        }

        if (preg_match('/^bytes=\d*-\d*(,\d*-\d*)*$/', $byteRange) !== 1) {
            throw new RuntimeException('Invalid range defined.', 416);
        }

        $byteRange = substr($byteRange, strpos($byteRange, '=') + 1);

        // TODO: add multipart support
        if (strpos($byteRange, ',') !== false) {
            throw new RuntimeException('Unsupported multi range request.', 416);
        }

        $limits = explode('-', $byteRange);
        if (count($limits) !== 2) {
            throw new RuntimeException('Invalid range defined.', 416);
        }

        $limits = array_map(static function (string $limit): ?int {
            return ($limit !== '' && is_numeric($limit)) ? ((int)$limit) : null;
        }, $limits);

        // parse range options:

        // end-bound: '-500'
        if ($limits[0] === null && $limits[1] !== null) {
            // [524, 1023, 500]
            $length = min($limits[1], $totalSize - 1);
            return [$totalSize - $length, $totalSize - 1, $length];
        }

        // start-bound: '500-'
        if ($limits[0] !== null && $limits[1] === null) {
            // [500, 1023, 524]
            $start = min($limits[0], $totalSize - 1);
            return [$start, $totalSize - 1, $totalSize - $start];
        }

        // full-range: '200-500'
        if ($limits[0] !== null && $limits[1] !== null) {
            $start = $limits[0];
            $end = min($limits[1], $totalSize - 1);

            return [$start, $end, $end - $start];
        }

        throw new RuntimeException('Invalid range defined.', 416);
    }

    /**
     * @param int $totalSize
     * @return array
     * @throws RuntimeException
     */
    protected function getRange(int $totalSize): array
    {
        [$rangeStart, $rangeEnd, $length] = $this->parseRange($totalSize);

        if ($rangeEnd > $totalSize || $rangeStart > $rangeEnd || $rangeStart < 0 || $length > $totalSize) {
            throw new RuntimeException('Invalid Range bounds.', 416);
        }

        if ($rangeEnd === $rangeStart) {
            throw new RuntimeException('Invalid Range bounds: zero length.', 416);
        }

        return [$rangeStart, $rangeEnd, $length];
    }

    /**
     * @param File $file
     * @param bool $forceDownload
     * @param string|null $asName
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileNotFoundException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    protected function streamFile(File $file, bool $forceDownload, ?string $asName = null): void
    {
        $size = $file->getSize();
        [$rangeStart, $rangeEnd, $rangeLength] = $this->getRange($size);
        $isPartialRequest = ($rangeStart > 0 || $rangeEnd < ($size - 1));

        $headers = [
            'Content-Type' => $file->getType(true),
            'Accept-Ranges' => 'bytes',
            'Last-Modified' => gmdate('D, d M Y H:i:s T', $file->getTime()),
            'Cache-Control' => ['public', 'must-revalidate', 'max-age=0'],
            'Pragma' => 'no-cache',
        ];

        if ($isPartialRequest) {
            static::sendStatusHeader(206);
            $headers['Content-Length'] = $rangeLength;
            $headers['Content-Disposition'] = 'inline';
            $headers['Content-Range'] = sprintf('%s-%s/%s', $rangeStart, $rangeEnd, $size);
            $headers['Content-Transfer-Encoding'] = 'binary';
            $headers['Connection'] = 'close';
        } else {
            static::sendStatusHeader(200);
            $headers['Content-Length'] = $size;
        }

        if ($forceDownload) {
            $filename = $asName ?? $file->path()->filename;
            $headers['Content-Disposition'] = ['attachment', "filename=\"{$filename}\""];
        }

        static::sendHeaders($headers);

        if ($isPartialRequest) {
            $file->stream($rangeStart, $rangeLength);
        } else {
            $file->stream();
        }
    }
}
