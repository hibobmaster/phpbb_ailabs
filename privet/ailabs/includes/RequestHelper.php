<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace privet\ailabs\includes;

class RequestHelper
{
    protected $request;

    public function __construct(\phpbb\request\request_interface $request)
    {
        $this->request = $request;
    }

    public function streamContextCreate($method = "GET", &$headers = [])
    {
        $headers = [];

        foreach (['X-Forwarded-For', 'User-Agent'] as $header_name)
            if (!empty($this->request->header($header_name)))
                array_push($headers, "$header_name: " . $this->request->header($header_name));

        // Pass all Cookies from the original request
        $cookies_array = $this->request->get_super_global(\phpbb\request\request_interface::COOKIE);

        $cookies = [];

        foreach ($cookies_array as $name => $value)
            array_push($cookies, $name . '=' . $value);

        if (!empty($cookies))
            array_push($headers, "Cookie: " . trim(implode(';', $cookies)));

        $context = null;

        $options = ['method' => $method];

        if (!empty($headers))
            $options['header'] = implode("\r\n", $headers);

        $context = stream_context_create(['http' => $options]);

        return $context;
    }
}
