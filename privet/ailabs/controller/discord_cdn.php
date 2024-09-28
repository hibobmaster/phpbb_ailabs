<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2024, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace privet\ailabs\controller;

use privet\ailabs\includes\GenericController;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/*
  https://privet.fun/viewtopic.php?t=3937
  https://www.epochconverter.io/hex-timestamp-converter
*/

class discord_cdn extends GenericController
{
    protected $cache;

    public const ailabs_discord_cdn_controller = '/ailabs/discord_cdn';
    public const ailabs_discord_config = 'ailabs_discord_config';

    public function __construct(
        \phpbb\auth\auth $auth,
        \phpbb\config\config $config,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\request\request $request,
        \phpbb\template\template $template,
        \phpbb\user $user,
        ContainerInterface $phpbb_container,
        $php_ext,
        $root_path,
        $users_table,
        $jobs_table,
        \phpbb\cache\driver\driver_interface $cache
    ) {
        parent::__construct(
            $auth,
            $config,
            $db,
            $helper,
            $language,
            $request,
            $template,
            $user,
            $phpbb_container,
            $php_ext,
            $root_path,
            $users_table,
            $jobs_table
        );

        $this->cache = $cache;
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
     */
    public function redirect($root, $attachments, $channel, $message, $file_name, Request $request)
    {
        $ext = $request->query->get('ext');
        $ex = $request->query->get('ex');
        $is = $request->query->get('is');
        $hm = $request->query->get('hm');

        // Underscored keys cached to file system.
        // Assuming that Discord file name is unique.
        $key = '_' . $file_name . '.' . $ext;
        $url = 'https://' . $root . '/' . $attachments . '/' . $channel . '/' . $message . '/' . $file_name . '.' . $ext;

        if (!empty($ex) && !empty($is) && !empty($hm)) {
            $secondsTimestamp = hexdec($ex);
            $currentTimestamp = time();
            if ($secondsTimestamp > $currentTimestamp)
                return new RedirectResponse($url . '?ex=' . $ex . '&is=' . $is . '&hm=' . $hm . '&original');
        }

        if ($this->cache->_exists($key)) {
            $refreshed = $this->cache->get($key);
            return new RedirectResponse($refreshed . '&cached');
        }

        $discord_config = $this->cache->get(self::ailabs_discord_config);

        if (empty($discord)) {
            $sql = 'SELECT config  FROM ' . $this->users_table .
                ' WHERE ' . $this->db->sql_build_array(
                    'SELECT',
                    [
                        'enabled' => true,
                        'controller' => self::ailabs_discord_cdn_controller
                    ]
                );
            $result = $this->db->sql_query($sql);
            $row = $this->db->sql_fetchrow($result);

            if (empty($row))
                return new JsonResponse('Unable to locate active discord_cdn configuration.', 400);

            $discord_config = json_decode($row['config']);

            if (empty($discord_config) || empty($discord_config->tokens) || empty($discord_config->channels))
                return new JsonResponse('discord_cdn configuration missing tokens of channels array values.', 400);

            $this->cache->put(self::ailabs_discord_config, $discord_config);
        }

        if (!in_array($channel, $discord_config->channels))
            return new JsonResponse('Channel ' . $channel . ' not configured. Adjust discord_cdn configuration, channels array.', 400);

        // Multiple valid discord tokens can be provided by user.
        // Select randomly token from provided array to avoid potential Discord 429 response.
        $discord = $discord_config->tokens[array_rand($discord_config->tokens)];

        if (empty($discord))
            return new JsonResponse('Unable to select discord token from discord_cdn configuration, tokens array.', 400);

        $data = json_encode(array('attachment_urls' => array($url)));

        $options = [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: " . $discord,
            'content' => $data
        ];

        $context = stream_context_create(['http' => $options]);

        $result = file_get_contents('https://discord.com/api/v9/attachments/refresh-urls', false, $context);

        if ($result === FALSE)
            return new JsonResponse('Unable to refresh ' . $url, 400);

        $json = json_decode($result, true);

        if (isset($json['refreshed_urls'][0]['refreshed'])) {
            $refreshed = $json['refreshed_urls'][0]['refreshed'];

            $query_str = parse_url($refreshed, PHP_URL_QUERY);
            parse_str($query_str, $query_params);

            $ttl = NULL;

            $secondsTimestamp = hexdec($query_params['ex']);
            $currentTimestamp = time();
            $ttl = $secondsTimestamp - $currentTimestamp;

            $this->cache->put($key, $refreshed, $ttl);

            return new RedirectResponse($refreshed . '&ttl=' . $ttl);
        } else {
            return new JsonResponse($result, 400);
        }
    }

    public static function cdn($url_discord)
    {
        $root = generate_board_url(true) . self::ailabs_discord_cdn_controller . '/';
        $url = str_replace('https://', $root, $url_discord);
        $pos1 = strpos($url, '?');
        if ($pos1 !== false) {
            $pos2 = strrpos(substr($url, 0, $pos1), '.');
            if ($pos2 !== false) {
                $ext = substr($url, $pos2 + 1, $pos1 - $pos2 - 1);
                $url = substr($url, 0, $pos2) . "?ext=" . $ext . "&" . substr($url, $pos1 + 1);
            }
        }
        return trim(rtrim($url, '&'));
    }
}
