<?php
namespace OrdinalsBot;

class OrdinalsBot
{
    const VERSION           = '0.0.1';
    const USER_AGENT_ORIGIN = 'OrdinalsBot PHP Library';

    public static $auth_token  = '';
    public static $environment = 'live';
    public static $user_agent  = '';
    public static $curlopt_ssl_verifypeer = FALSE;

    public static function config($authentication)
    {
        if (isset($authentication['auth_token']))
            self::$auth_token = $authentication['auth_token'];

        if (isset($authentication['environment']))
            self::$environment = $authentication['environment'];

        if (isset($authentication['user_agent']))
            self::$user_agent = $authentication['user_agent'];
    }

    public static function testConnection($authentication = array())
    {
        try {
            self::request('/auth/test', 'GET', array(), $authentication);

            return true;
        } catch (\Exception $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }
    }

    public static function request($url, $method = 'POST', $params = array(), $authentication = array())
    {
        $auth_token  = isset($authentication['auth_token']) ? $authentication['auth_token'] : self::$auth_token;
        $environment = isset($authentication['environment']) ? $authentication['environment'] : self::$environment;
        $user_agent  = isset($authentication['user_agent']) ? $authentication['user_agent'] : (isset(self::$user_agent) ? self::$user_agent : (self::USER_AGENT_ORIGIN . ' v' . self::VERSION));
        $curlopt_ssl_verifypeer = isset($authentication['curlopt_ssl_verifypeer']) ? $authentication['curlopt_ssl_verifypeer'] : self::$curlopt_ssl_verifypeer;

        # Check if credentials was passed
        if (empty($auth_token))
            \OrdinalsBot\Exception::throwException(400, array('reason' => 'CredentialsMissing', 'message' => 'Set up your credentials on plugin\'s settings'));

        # Check if right environment passed
        $environments = array('live', 'sandbox');

        if (!in_array($environment, $environments)) {
            $availableEnvironments = join(', ', $environments);
            \OrdinalsBot\Exception::throwException(400, array('reason' => 'BadEnvironment', 'message' => "Environment does not exist. Available environments: $availableEnvironments"));
        }

        $url       = ($environment === 'sandbox' ? 'https://signet-api.ordinalsbot.com' : 'https://api.ordinalsbot.com') . $url;
        $headers   = array();
        $headers[] = 'x-api-key: ' . $auth_token;
        $curl      = curl_init();

        $curl_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $url
        );

        if ($method == 'POST') {
            $headers[] = 'Content-Type: application/json';
            array_merge($curl_options, array(CURLOPT_POST => 1));
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        }

        curl_setopt_array($curl, $curl_options);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $curlopt_ssl_verifypeer);

        // error_log('OrdinalsBot Request' . $url . ' ' . $method . ' ' . json_encode($params));
        $response    = json_decode(curl_exec($curl), TRUE);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        // error_log('Response: ' . $http_status . ' ' . json_encode($response));

        if (array_key_exists('id', $response)){
            return $response;
        }
        else {
            \OrdinalsBot\Exception::throwException($http_status, $response);
        }
    }
}
