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

/*

// Setup Pika
// https://useapi.net/docs/start-here/setup-pika 

config: 

{
    "api_key": "<API_KEY>",
    "url_picsi": "https://api.useapi.net/v1/faceswap/picsi",
    "channel": "optional|your-discord-channel-id",
    "retryCount": 80,
    "timeoutBeforeRetrySec": 15
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{images}
{info}

*/

class faceswap extends useapi_controller
{
    protected function setup()
    {
        $this->attachments_ext = 'image/png';
        $this->callback_route = 'privet_ailabs_faceswap_callback';
    }

    protected function payload()
    {
        $payload = null;

        $this->log['settings_override'] = '';

        $this->url_post = $this->cfg->url_picsi;

        $request = $this->job['request'];

        $attachments = $this->get_attachments_or_urls($request, $this->messages, $this->attachments_ext);

        $has_attachments = !empty($attachments) && count($attachments) > 1;

        if (!$has_attachments)
            array_push($this->messages, $this->language->lang('AILABS_ERROR_PROVIDE_URL_2x'));

        // https://useapi.net/docs/api-faceswap-v1/post-faceswap-picsi
        $payload = [
            'channel'   => empty($this->cfg->channel) ? null : $this->cfg->channel,
            'replyUrl'  => $this->url_callback,
            'replyRef'  => (string) $this->job_id,
        ];

        if (!empty($request) && preg_match('/[a-zA-Z0-9]/', $request))
            $payload['options'] = $request;

        if ($has_attachments) {
            $payload['source_image'] = $attachments[0];
            $payload['target_image_gif_or_video'] = $attachments[1];
        }

        $this->log['settings_override'] =  trim("[url=https://useapi.net/docs/api-faceswap-v1/post-faceswap-" . basename($this->url_post) . "]faceswap/" . basename($this->url_post)  . "[/url]" . PHP_EOL .  $this->log['settings_override']);

        return $payload;
    }
}
