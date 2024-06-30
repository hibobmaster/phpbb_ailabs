<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace privet\ailabs\controller;

/*

// How to get api token and configure Discord 
// https://useapi.net/docs/start-here 

config: 

{
    "url_imagine": "https://api.useapi.net/v2/jobs/imagine",
    "url_button": "https://api.useapi.net/v2/jobs/button",
    "url_seed": "https://api.useapi.net/v2/jobs/seed",
    "api_key": "<API_KEY>",
    "discord": "optional|your-discord-token",
    "server": "optional|your-discord-server-id",
    "channel": "optional|your-discord-channel-id",
    "maxJobs": 3,
    "retryCount": 80,
    "timeoutBeforeRetrySec": 15
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{images}
{info}

*/

class midjourney extends useapi_controller
{
    protected function setup()
    {
        $this->attachments_ext = 'image/png';
        $this->info_buttons = $this->language->lang('AILABS_MJ_BUTTONS');
        $this->callback_route = 'privet_ailabs_midjourney_callback';
        $this->extra_buttons = ['Seed'];
    }

    protected function payload()
    {
        $payload = null;

        $this->log['settings_override'] = '';

        $maxJobs = empty($this->cfg->maxJobs) ? 10 : $this->cfg->maxJobs;

        if (!empty($this->button) && !empty($this->parent_job_id)) {
            $payload = [
                'jobid'     => $this->parent_job_id,
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];

            if (!empty($this->button) && (strcasecmp($this->button, "SEED") == 0))
                $this->url_post = $this->cfg->url_seed;
            else {
                $this->url_post = $this->cfg->url_button;

                $payload = array_merge(
                    $payload,
                    [
                        'button'    => $this->button,
                        'prompt'    => empty($this->button_prompt) ? null : $this->button_prompt,
                        'maxJobs'   => $maxJobs,
                    ]
                );
            }
        } else {
            $this->url_post = $this->cfg->url_imagine;

            $request = $this->job['request'];

            // We expect to have prompt with at least one alpha-numeric character or emoji
            if (empty($request))
                array_push($this->messages, $this->language->lang('AILABS_NO_PROMPT'));

            // https://useapi.net/docs/api-pika-v1/post-pika-create
            $payload = [
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'server'   => empty($this->cfg->server) ? null : $this->cfg->server,
                'channel'   => empty($this->cfg->channel) ? null : $this->cfg->channel,
                'prompt'    => empty($request) ? null : $request,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];
        }

        $this->log['settings_override'] =  trim("[url=https://useapi.net/docs/api-v2/post-jobs-" . basename($this->url_post) . "]midjourney/" . basename($this->url_post)  . "[/url]" . PHP_EOL .  $this->log['settings_override']);

        return $payload;
    }
}
