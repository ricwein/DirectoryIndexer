<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\DirectoryIndex\Network;

use RuntimeException;

/**
 * provides HTTP Header support
 */
trait HeaderTrait
{
    /**
     * most default http status code messages
     * @var array<int, string>
     */
    protected static array $statusCodes = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Mime Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        495 => 'Encryption Error',
        497 => 'HTTP Request Sent to HTTPS Port',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * lowercase list of http headers which can have multiple parameters without being a list
     * => they're semicolon separated
     * @var array<string, bool>
     */
    protected static array $httpMultiHeaders = [
        'content-type' => true,
        'link' => true,
        'content-disposition' => true,
    ];

    /**
     * @param array<string|int, string>|string $headers
     * @param bool $override
     * @throws RuntimeException
     */
    public static function sendHeaders($headers, bool $override = false): void
    {

        // "gracefully" handle 'headers already send in ...' errors
        if (headers_sent($file, $line)) {
            throw new RuntimeException("headers already send in: {$file}:{$line}", 500);
        }

        // parse and send headers
        foreach ((array)$headers as $header => $parameters) {

            // implode multiple parameters into a string
            if (is_array($parameters)) {
                $parameters = static::_buildHeader($parameters, $header);
            }

            // send header
            if (is_int($header)) {
                // header name is (hopefully) already completely set in $parameters
                header($parameters, $override);
            } else {
                header("{$header}: {$parameters}", $override);
            }
        }
    }

    /**
     * @param string $url
     * @param string $type image,font,script,style,document
     * @param array $options
     */
    public static function sendHeaderPush(string $url, string $type, array $options = []): void
    {
        static::sendHeaders([
            'Link' => array_merge([
                "<{$url}>",
                'rel=preload',
                "as={$type}",
            ], $options),
        ], true);
    }

    /**
     * @param array $parameters
     * @param string|null $name
     * @return string
     */
    protected static function _buildHeader(array $parameters, string $name = null): string
    {
        if ($name === null) {
            return implode(', ', $parameters);
        }

        if (isset(static::$httpMultiHeaders[strtolower($name)]) && static::$httpMultiHeaders[strtolower($name)] === true) {
            return implode('; ', $parameters);
        }

        return implode(', ', $parameters);
    }

    /**
     * sets http status code if http_response_code() is not available
     * @param int $code
     * @param string $message
     * @param string $protocol
     * @return int
     */
    public static function sendStatusHeader(int $code = 200, string $message = null, string $protocol = 'HTTP/1.0'): int
    {
        if ($message !== null) {
            static::sendHeaders(trim(sprintf('%s %s %s', $protocol, $code, $message)));
        } elseif (array_key_exists($code, static::$statusCodes)) {
            static::sendHeaders(sprintf('%s %d %s', $protocol, $code, static::$statusCodes[$code]));
        } else {
            http_response_code($code);
            $code = http_response_code();
        }

        return $code;
    }

}
