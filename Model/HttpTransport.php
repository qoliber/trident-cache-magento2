<?php

/**
 * Created by qoliber
 *
 * @category    Qoliber
 * @package     Qoliber_TridentCache
 * @author      Jakub Winkler <jwinkler@qoliber.com>
 */

declare(strict_types=1);

namespace Qoliber\TridentCache\Model;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use Qoliber\Trident\Delivery\Psr18Transport;
use Qoliber\Trident\Delivery\Transport;

/**
 * Every request to a Trident admin API, over the Guzzle client Magento ships,
 * behind qoliber/trident-php's PSR-18 transport.
 *
 * Guzzle's PSR-18 `sendRequest()` never follows a redirect and never throws on
 * an HTTP error status, so the status the engine answered is what the caller
 * judges. Short timeouts: a purge is delivered at the end of a shop request
 * (or an admin click), and a wedged admin port must not hold either for long.
 */
class HttpTransport implements Transport
{
    /** Total request timeout, seconds. */
    public const REQUEST_TIMEOUT = 10;

    /** Connection-establishment timeout, seconds. */
    public const CONNECT_TIMEOUT = 5;

    private ?Transport $transport = null;

    /**
     * @param float $timeout Total request timeout, seconds.
     * @param float $connectTimeout Connection timeout, seconds.
     */
    public function __construct(
        private readonly float $timeout = self::REQUEST_TIMEOUT,
        private readonly float $connectTimeout = self::CONNECT_TIMEOUT
    ) {
    }

    /**
     * Never throws: anything that prevents a response — including what is not
     * a PSR-18 exception (a malformed URL typed in the admin) — is reported as
     * no response (status 0), which the delivery code retries and the admin
     * screens show as unreachable. A purge delivered from a commit callback or
     * the cron job must not end in an exception.
     *
     * @inheritDoc
     */
    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        try {
            return $this->transport()->request($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * The Guzzle options of this transport — also the base of the Live Events
     * poll ({@see EventPoller}).
     *
     * Always libcurl ({@see CurlHandler}; Magento requires ext-curl), whatever
     * `allow_url_fopen` says, so the module behaves one way everywhere.
     *
     * Proxy: libcurl's own rules, exactly what the module had through
     * Magento's Curl client — lowercase `http_proxy`, `https_proxy` or
     * `HTTPS_PROXY`, `all_proxy`/`ALL_PROXY`, and `no_proxy` with its
     * domain and CIDR matching; never uppercase `HTTP_PROXY`, which under CGI
     * a request's `Proxy:` header can set ("httpoxy"). `proxy` is set to an
     * empty list because leaving it out is NOT neutral: Guzzle's Client then
     * adds its own from `HTTP_PROXY` (in the CLI — cron, the drain command),
     * `HTTPS_PROXY` and `NO_PROXY`, and passes it to libcurl as an explicit
     * proxy. With an empty list Guzzle sets no proxy and libcurl decides.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return [
            'handler' => HandlerStack::create(new CurlHandler()),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'proxy' => [],
        ];
    }

    /**
     * @return Transport
     */
    private function transport(): Transport
    {
        if ($this->transport === null) {
            $factory = new HttpFactory();
            $this->transport = new Psr18Transport(new Client($this->options()), $factory, $factory);
        }
        return $this->transport;
    }
}
