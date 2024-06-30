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

// Setup PixVerse
// https://useapi.net/docs/start-here/setup-pixverse

config: 

{
  "api_key": "<API_KEY>",
  "url_create": "https://api.useapi.net/v1/pixverse/create",
  "url_animate": "https://api.useapi.net/v1/pixverse/animate",
  "url_button": "https://api.useapi.net/v1/pixverse/button",
  "discord": "optional|your-discord-token",
  "channel": "optional|your-discord-channel-id",
  "maxJobs": 3,
  "retryCount": 80,
  "timeoutBeforeRetrySec": 15
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{mp4}
{info}

*/

class pixverse extends useapi_controller
{
    protected function setup()
    {
        $this->attachments_ext = 'image/png';
        $this->info_buttons = $this->language->lang('AILABS_QUOTE_BUTTONS');
        $this->callback_route = 'privet_ailabs_pixverse_callback';
    }

    protected function payload()
    {
        $payload = null;

        $maxJobs = empty($this->cfg->maxJobs) ? 3 : $this->cfg->maxJobs;

        $this->log['settings_override'] = '';

        if (!empty($this->button) && !empty($this->parent_job_id)) {
            $this->url_post = $this->cfg->url_button;

            $payload = [
                'jobid'     => $this->parent_job_id,
                'button'    => $this->button,
                'prompt'    => empty($this->button_prompt) ? null : $this->button_prompt,
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];
        } else {
            $params = [];
            $request = $this->job['request'];

            // See if there attached image or URL reference to one
            $attachments = $this->get_attachments_or_urls($request, $this->messages, $this->attachments_ext);

            if ($this->extract_settings(
                $request,
                [
                    'style' => 'style',
                    'aspect_ratio' => 'aspect_ratio',
                    'seed_value' => 'seed_value',
                    'motion' => 'motion',
                    'character' => 'character',
                    'hd_quality' => 'hd_quality'
                ],
                $params,
                $settings_override
            ))
                $this->log['settings_override'] = $settings_override;

            if (!empty($params['style']) && $params['style'] == '3D-Animation')
                $params['style'] = '3D Animation';

            if (empty($attachments) && empty($request))
                array_push($this->messages, $this->language->lang('AILABS_NO_PROMPT'));

            $payload = [
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'server'   => empty($this->cfg->server) ? null : $this->cfg->server,
                'channel'   => empty($this->cfg->channel) ? null : $this->cfg->channel,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];

            if (!empty($request))
                $payload['prompt'] = $request;

            if (!empty($attachments)) {
                // https://useapi.net/docs/api-pixverse-v1/post-pixverse-animate
                //  - motion
                //  - seed_value
                //  - hd_quality
                $this->url_post = $this->cfg->url_animate;
                $payload['image'] = $attachments[0];
                $payload['hd_quality'] = 'Yes';
            } else {
                // https://useapi.net/docs/api-pika-v1/post-pika-create
                // - style
                // - aspect_ratio
                // - character
                $this->url_post = $this->cfg->url_create;
                $payload['style'] = 'Realistic';
            }

            $payload = array_merge($payload, $params);
        }

        $this->log['settings_override'] =  trim("[url=https://useapi.net/docs/api-pixverse-v1/post-pixverse-" . basename($this->url_post) . "]pixverse/" . basename($this->url_post)  . "[/url]" . PHP_EOL .  $this->log['settings_override']);

        return $payload;
    }
}
