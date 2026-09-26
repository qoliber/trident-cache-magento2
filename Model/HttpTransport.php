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
     * @return Transport
     */
    private function transport(): Transport
    {
        if ($this->transport === null) {
            $factory = new HttpFactory();
            $this->transport = new Psr18Transport(
                new Client([
                    'timeout' => self::REQUEST_TIMEOUT,
                    'connect_timeout' => self::CONNECT_TIMEOUT,
                ]),
                $factory,
                $factory
            );
        }
        return $this->transport;
    }
}
