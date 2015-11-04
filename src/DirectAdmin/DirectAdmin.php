<?php
/**
 * DirectAdmin
 * (c) Omines Internetbureau B.V.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Omines\DirectAdmin;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Omines\DirectAdmin\Context\AdminContext;
use Omines\DirectAdmin\Context\ResellerContext;
use Omines\DirectAdmin\Context\UserContext;

/**
 * DirectAdmin API wrapper class.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class DirectAdmin
{
    const ACCOUNT_TYPE_ADMIN            = 'admin';
    const ACCOUNT_TYPE_RESELLER         = 'reseller';
    const ACCOUNT_TYPE_USER             = 'user';

    /** @var string */
    private $authenticatedUser;

    /** @var string */
    private $username;

    /** @var string */
    private $password;

    /** @var string */
    private $baseUrl;

    /** @var Client */
    private $connection;

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     * @param bool|false $validate
     * @return AdminContext
     */
    public static function connectAdmin($url, $username, $password, $validate = false)
    {
        return new AdminContext(new self($url, $username, $password), $validate);
    }

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     * @param bool|false $validate
     * @return ResellerContext
     */
    public static function connectReseller($url, $username, $password, $validate = false)
    {
        return new ResellerContext(new self($url, $username, $password), $validate);
    }

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     * @param string bool|false $validate
     * @return UserContext
     */
    public static function connectUser($url, $username, $password, $validate = false)
    {
        return new UserContext(new self($url, $username, $password), $validate);
    }

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     */
    protected function __construct($url, $username, $password)
    {
        $accounts = explode('|', $username);
        $this->authenticatedUser = current($accounts);
        $this->username = end($accounts);
        $this->password = $password;
        $this->baseUrl = rtrim($url, '/') . '/';
        $this->connection = new Client([
            'base_uri' => $this->baseUrl,
            'auth' => [$username, $password],
            'http_errors' => true,
            'verify' => false
        ]);
    }

    /**
     * @return string Currently logged in user's username.
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Invokes the DirectAdmin API with specific options.
     *
     * @param string $method HTTP method to use (ie. GET or POST)
     * @param string $command DirectAdmin API command to invoke.
     * @param array $options Guzzle options to use for the call.
     * @return array The unvalidated response.
     * @throws DirectAdminException If anything went wrong on the network level.
     */
    public function invoke($method, $command, $options = [])
    {
        $result = $this->rawRequest($method, '/CMD_API_' . $command, $options);
        if(!empty($result['error']))
            throw new DirectAdminException("$method to $command failed: $result[details] ($result[text])");
        elseif(count($result) == 1 && isset($result['list[]']))
            $result = $result['list[]'];
        return is_array($result) ? $result : [$result];
    }

    /**
     * Returns a clone of the connection logged in as a managed user or reseller.
     *
     * @param string $username
     * @return DirectAdmin
     */
    public function loginAs($username)
    {
        // DirectAdmin format is to just pipe the accounts together under the master password
        return new self($this->baseUrl, $this->authenticatedUser . "|{$username}", $this->password);
    }

    /**
     * Sends a raw request to DirectAdmin.
     *
     * @param string $method
     * @param string $uri
     * @param array $options
     * @return array
     */
    private function rawRequest($method, $uri, $options)
    {
        try
        {
            $response = $this->connection->request($method, $uri, $options);
            if($response->getHeader('Content-Type')[0] == 'text/html')
                throw new DirectAdminException('DirectAdmin API returned an error: ' . strip_tags($response->getBody()->getContents()));
            return self::parseResponse($response->getBody()->getContents());
        }
        catch(TransferException $exception)
        {
            // Rethrow anything that causes a network issue
            throw new DirectAdminException("Request to $uri using $method failed", 0, $exception);
        }
    }

    /**
     * Processes DirectAdmin style responses.
     *
     * @param string $data
     * @return array
     */
    private static function parseResponse($data)
    {
        $unescaped = preg_replace_callback('/&#([0-9]{2})/', function($val) {
            return chr($val[1]); }, $data);
        return \GuzzleHttp\Psr7\parse_query($unescaped);
    }
}
