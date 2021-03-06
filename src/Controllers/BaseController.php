<?php

namespace Controllers;

class BaseController
{
    /**
     * Base Controller
     *
     * @param \Base $f3
     * @param array $params
     */
    public function __construct(\Base $f3, array $params = [])
    {
        $this->f3                 = $f3;
        $this->params             = $params;
        $this->tokenData          = [];
        $this->session            = new \Session();
        $this->view               = \View::instance();
        $this->isDebug            = $this->getOrDefault('DEBUG', '0') > '1';

        $twig = new \Twig_Environment(new \Twig_Loader_Filesystem(trim($this->getOrDefault('UI', 'public/app'), '/') . '/'), [
            'debug'       => $this->isDebug,
            'cache'       => ($this->getTempDir() . 'twig'),
            'auto_reload' => true
        ]);
        $twig->addFilter(new \Twig_SimpleFilter('f3', array($this, 'getOrDefault')));
        $lexer = new \Twig_Lexer($twig, array(
            'tag_comment'   => array('[#', '#]'),
            'tag_block'     => array('[%', '%]'),
            'tag_variable'  => array('[[', ']]'),
            'interpolation' => array('#[', ']'),
        ));
        $twig->setLexer($lexer);
        
        $this->twig = $twig;
    }

    /**
     * Decode and validate the token
     *
     * @param  string         $$token
     * @return object|boolean The JWT's payload as a PHP object or false in case of error
     */
    public function decodeToken($token)
    {
        $rst = [
            "token" => false,
            "expired" => false,
            "message" => ""
        ];
        try {
            \Firebase\JWT\JWT::$leeway = 8;
            $content     = file_get_contents("https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com");
            $kids        = json_decode($content, true);
            $jwt         = \Firebase\JWT\JWT::decode($token, $kids, array('RS256'));
            $fbpid       = $this->getOrDefault('firebase.projectid', 'dummy');
            $issuer      = 'https://securetoken.google.com/' . $fbpid;
            $rst["token"] = $token;
            $rst["decoded"] = $jwt;

            if ($jwt->aud != $fbpid) {
                $rst["message"] = 'invalid audience ' . $jwt->aud;
                $rst["token"] = null;
            } elseif ($jwt->iss != $issuer) {
                $rst["message"] = 'invalid issuer ' . $jwt->iss;
                $rst["token"] = null;
            } elseif (empty($jwt->sub)) {
                $rst["message"] = 'invalid sub ' . $jwt->sub;
                $rst["token"] = null;
            };

        } catch (\Firebase\JWT\ExpiredException $ee) {
            $rst["expired"] = true;
            $rst["message"] = 'token has expired';
            // we want to keep the token for use later
        } catch (\Exception $e) {
            $rst["message"] = $e->getMessage();
            $rst["token"] = null;
        }

        return $rst;
    }

    /**
     * Shortcut method for rendering a view.
     * @param  string $name        view name
     * @param  array  $args        view params
     * @return the    controller
     */
    public function render($name, array $args = [])
    {
        echo $this->twig->render($name . '.twig', $args);
    }

    /**
     * get temp dir
     */
    public function getTempDir()
    {
        return trim($this->getOrDefault('TEMP', '../tmp/'), '/') . '/';
    }

    /**
     * $f3 get value or default
     * @param  string   $key
     * @param  string   $default
     * @return object
     */
    public function getOrDefault($key, $default = null)
    {
        $rst = $this->f3->get($key);
        if (!isset($rst)) {
            return $default;
        }
        return $rst;
    }

    /**
     * get authorization header
     * @return string
     */
    public function getAuthorizationHeader(){
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        }
        else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            //print_r($requestHeaders);
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    /**
     * get bearer token
     * @return string
     */
    public function getBearerToken() {
        $headers = $this->getAuthorizationHeader();

        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * echo json
     * @param object $data
     * @param array  $params
     */
    public function json($data, array $params = [])
    {
        $f3      = $this->f3;
        $body    = json_encode($data, JSON_PRETTY_PRINT);
        $headers = array_key_exists('headers', $params) ? $params['headers'] : [];

        // set ttl
        $ttl = (int) array_key_exists('ttl', $params) ? $params['ttl'] : 0; // cache for $ttl seconds
        if (empty($ttl)) {
            $ttl = 0;
        }

        $headers = array_merge($headers, [
            'Content-type'                     => 'application/json; charset=utf-8',
            'Expires'                          => '-1',
            'Access-Control-Max-Age'           => $ttl,
            'Access-Control-Expose-Headers'    =>
            array_key_exists('acl_expose_headers', $params) ? $params['acl_expose_headers'] : null,
            'Access-Control-Allow-Methods'     =>
            array_key_exists('acl_http_methods', $params) ? $params['acl_http_methods'] : null,
            'Access-Control-Allow-Origin'      => array_key_exists('acl_origin', $params) ? $params['acl_origin'] : '*',
            'Access-Control-Allow-Credentials' =>
            array_key_exists('acl_credentials', $params) && !empty($params['acl_credentials']) ? 'true' : 'false',
            'ETag'                             => array_key_exists('etag', $params) ? $params['etag'] : md5($body),
            'Content-Length'                   => \UTF::instance()->strlen($body),
        ]);

        // send the headers + data
        $f3->expire($ttl);

        // default status is 200 - OK
        $f3->status(array_key_exists('http_status', $params) ? $params['http_status'] : 200);

        // do not send session cookie
        if (!array_key_exists('cookie', $params)) {
            header_remove('Set-Cookie'); // prevent php session
        }

        ksort($headers);
        foreach ($headers as $header => $value) {
            if (!isset($value)) {
                continue;
            }
            header($header . ': ' . $value);
        }

        // HEAD request should be identical headers to GET request but no body
        if ('HEAD' !== $f3->get('VERB')) {
            echo $body;
        }
    }

    /**
     * perform get request
     * @param  string $url     
     * @param  array  $headers 
     * @param  array  $query   
     * @return object          response
     */
    public function doGetJson($url, $inHeaders, $query) 
    {
        $client = new \GuzzleHttp\Client(['headers' => ['Authorization' => $inHeaders['Authorization']]]);
        $response = null;

        try {
            $response = $client->request('GET', $url, ['query' => $query, 'headers' => $inHeaders]);
            $rawBody = $response->getBody()->getContents();
            $result = json_decode($rawBody, true);
        } catch(\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            }
        }

        if (is_null($response)) {
            return [
                'raw_body' => null,
                'code' => 503,
                'body' => null,
                'headers' => array()
            ];
        }

        return [
            'raw_body' => $rawBody,
            'code' => $response->getStatusCode(),
            'body' => $result,
            'headers' => $response->getHeaders()
        ];
    }
}
