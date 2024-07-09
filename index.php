<?php
error_reporting(0);

class CloakupClient
{
    const BASE_URL = 'https://check.cloakup.app';
    const FILTER_URL = '/v{version}/filter';
    const VERSION = 1;
    const SESSION_KEY = 'cloakup_session';
    const SESSION_TTL = 24;
    const ACTION_LOCAL = 'local';
    const ACTION_CONTENT = 'content';
    const ACTION_REDIRECT = 'redirect';

    private $token;
    private $slug;
    private $httpClient;
    private $debug = false;
    private $params = [];
    private $reservedParams = ['token', 'slug', 'ip', 'user_agent', 'referer', 'language', 'domain', 'query', 'force_redirect'];
    private $response;

    public function __construct($token, $slug)
    {
        $this->token = $token;
        $this->slug = $slug;
        $this->httpClient = new HttpClient();
        $this->fill();
    }

    private function fill(): void
    {
        $ip = $this->getIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $language = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $query = $_GET ?? [];

        $this
            ->token($this->token)
            ->slug($this->slug)
            ->ip($ip)
            ->userAgent($ua)
            ->referer($referer)
            ->domain($this->getCurrentUrl())
            ->query($query)
            ->language($language);
    }

    public function debug()
    {
        $this->debug = true;

        $GLOBALS['cloakup_debug'] = true;

        return $this;
    }

    private function param($key, $value): self
    {
        if (empty($this->params[$key]) && !in_array($key, $this->reservedParams)) {
            $this->params[$key] = $value;
        }

        return $this;
    }

    private function getIp(): string
    {
        $ip = null;

        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
            'HTTP_X_REAL_IP',
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                break;
            }
        }

        if ($ip === null) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }

    private function getCurrentUrl(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = explode('?', $_SERVER['REQUEST_URI'])[0] ?? '';
        return "$scheme" . "://$host" . "$uri";
    }

    private function slug($slug)
    {
        $this->params['slug'] = $slug;

        return $this;
    }

    private function token($token)
    {
        $this->params['token'] = $token;

        return $this;
    }

    private function ip($ip): self
    {
        $this->params['ip'] = $ip;

        return $this;
    }

    private function userAgent($ua): self
    {
        $this->params['user_agent'] = $ua;

        return $this;
    }

    private function referer($referer): self
    {
        $this->params['referer'] = $referer;

        return $this;
    }

    private function domain($domain): self
    {
        $this->params['domain'] = $domain;

        return $this;
    }

    private function language($language): self
    {
        $this->params['language'] = $language;

        return $this;
    }

    private function query($query): self
    {
        $this->params['query'] = $query;

        return $this;
    }

    private function getCookies(): string
    {
        $cookies = preg_replace('/' . self::SESSION_KEY . '=[^;]+;?/', '', $_SERVER['HTTP_COOKIE'] ?? '');

        return preg_replace('/s+/', '', $cookies);
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => $this->getSessionTtl() * 60 * 60,
                'name' => self::SESSION_KEY,
            ]);
        }
    }

    private function saveState($params): void
    {
        $this->startSession();

        $_SESSION[self::SESSION_KEY] = json_encode($params);
    }

    private function storeCookies(): void
    {
        $cookies = $this->response->cookies ?? [];

        if (empty($cookies)) {
            return;
        }

        foreach ($cookies as $cookie) {
            $this->setCookie($cookie->name, $cookie->value, $cookie->ttl);
        }
    }

    private function setCookie($key, $value, $ttl): void
    {
        if (isset($_COOKIE[$key]) && $_COOKIE[$key] == $value) {
            return;
        }

        setcookie($key, $value, time() + ($ttl * 60 * 60), '/');

        $_COOKIE[$key] = $value;
    }

    private function setHeaders(): void
    {
        $headers = $this->response->headers ?? [];

        if (empty($headers)) {
            return;
        }

        foreach ($headers as $header) {
            header($header->name . ': ' . $header->value);
        }
    }

    private function getSessionTtl(): int
    {
        return $this->response->ttl ?? self::SESSION_TTL;
    }

    private function getRequestUrl(): string
    {
        $endpoint = self::BASE_URL . $this->getEndpoint();

        return str_replace(
            ['{version}'],
            [self::VERSION],
            $endpoint
        );
    }

    private function getEndpoint(): string
    {
        return self::FILTER_URL;
    }

    private function getRequestParams(): array
    {
        return $this->params;
    }

    private function getRequestOptions(): array
    {
        return [
            'cookie' => $this->getCookies(),
        ];
    }

    private function buildUrl($url, $params): string
    {
        if (empty($params)) {
            return $url;
        }

        $query = http_build_query($params);

        return $url . (strpos($url, '?') === false ? '?' : '&') . $query;
    }

    private function getContextOptions()
    {
        return stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            'http' => ['header' => 'User-Agent: ' . $this->params['user_agent']],
        ]);
    }

    public function execute(): self
    {
        $url = $this->getRequestUrl();
        $params = $this->getRequestParams();
        $options = $this->getRequestOptions();

        try {
            $response = $this->httpClient->request(
                $url,
                $params,
                $options
            );
        } catch (Exception $e) {
            throw new CloakupClientException($e->getMessage(), $e->getCode());
            if ($this->debug) {
                throw $e;
            } else {
                echo $e->getMessage();
                die;
            }
        }

        $this->response = json_decode($response);

        $this->saveState($this->response);
        $this->storeCookies();
        $this->setHeaders();

        return $this;
    }

    private function getHtml(): string
    {
        if ($this->debug) {
            return json_encode($this->response);
        }

        if (empty($this->response)) {
            return '';
        }

        $content = $this->response->next->content ?? '';
        $type = $this->response->next->type ?? '';

        $html = '';

        if ($type === self::ACTION_REDIRECT) {
            $url = $this->buildUrl($content, $_GET);
            header('Location: ' . $url, true, 303);
            die;
        }

        if ($type === self::ACTION_CONTENT) {
            if (filter_var($content, FILTER_VALIDATE_URL)) {
                $html = file_get_contents($this->buildUrl($content, $_GET), false, $this->getContextOptions());

                if (substr($content, -1) !== '/') {
                    $content .= '/';
                }

                $html = str_replace('<head>', '<head><base href="' . $content . '">', $html);
            }

            if (file_exists($content)) {
                $ext = pathinfo($content, PATHINFO_EXTENSION);

                if ($ext === 'php') {
                    ob_start();
                    include $content;
                    $html = ob_get_clean();
                } elseif ($ext === 'html') {
                    $html = file_get_contents($content);
                } else {
                    include $content;
                }
                $html = str_replace('<head>', '<head><base href="' . $content . '">', $html);
            }
        }

        return $html;
    }

    public function render(): void
    {
        echo $this->getHtml();
        die;
    }
}

class HttpClient
{
    const USER_AGENT = 'Cloakup PHP Client';

    public function request(string $url, array $params, array $options = [])
    {
        if (!in_array('curl', get_loaded_extensions())) {
            throw new CloakupClientException('Curl extension is not loaded', 500);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_COOKIE, $options['cookie'] ?? '');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        $response = curl_exec($ch);

        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($statusCode !== 200) {
            if(isset($GLOBALS['cloakup_debug']) && ($GLOBALS['cloakup_debug'] === true)) {
                echo $response;
                die;
            }
            $message = json_decode($response)->message ?? 'Unknown error';
            throw new CloakupClientException($message, $statusCode);
        }

        curl_close($ch);

        return $response;
    }
}

class CloakupClientException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->render($code, 'Error', $message);
    }

    public function render($code)
    {
        $title = $this->getStatusMessage($code);
        echo '<!DOCTYPE html>
        <html style="height:100%">
            <head>
                <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
                <title>' . $code . ' ' . $title . '</title>
            </head>
            <body style="color: #444; margin:0;font: normal 14px/20px Arial, Helvetica, sans-serif; height:100%; background-color: #fff;">
                <div style="height:auto; min-height:100%; ">
                    <div style="text-align: center; width:800px; margin-left: -400px; position:absolute; top: 30%; left:50%;">
                        <h1 style="margin:0; font-size:150px; line-height:150px; font-weight:bold;">' . $code . '</h1>
                        <h2 style="margin-top:20px;font-size: 30px;">' . $title . '</h2>
                        <div style="margin-bottom:20px;font-size: 16px;">' . $this->message . '</div>
                    </div>
                </div>
            </body>
        </html>';
        die;
    }

    private function getStatusMessage($code)
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
        ];

        return $messages[$code] ?? 'Unknown error';
    }
}

$cloakup = new CloakupClient('ad99c19c3b2f70102e120d59997212b6', 'e48873dcdc2c2fea');
$cloakup->execute()->render();