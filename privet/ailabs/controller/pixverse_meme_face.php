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
    "api_key":                  "<useapi.net api token>",
    "url_meme_face":            "https://api.useapi.net/v1/pixverse/meme_face",
    "url_button":               "https://api.useapi.net/v1/pixverse/button",
    "discord":                  "<Discord token, optional>",
    "channel":                  "<Discord channel id, optional>",
    "maxJobs":                  "<Maximum Concurrent Jobs, optional, default 3>",
    "retryCount":               "<Maximum attempts to submit request, optional, default 80>",
    "timeoutBeforeRetrySec":    "<Time to wait before next retry, optional, default 15>",
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{mp4}
{info}

*/

class pixverse_meme_face extends useapi_controller
{
    protected function setup()
    {
        $this->attachments_ext = 'image/png';
        $this->info_buttons = $this->language->lang('AILABS_QUOTE_BUTTONS');
        $this->callback_route = 'privet_ailabs_pixverse_meme_face_callback';
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
            $this->url_post = $this->cfg->url_meme_face;

            // See if there attached image or URL reference to one
            $attachments = $this->get_attachments_or_urls($request, $this->messages, $this->attachments_ext);

            // https://useapi.net/docs/api-pixverse-v1/post-pixverse-meme_face
            //  - aspect_ratio
            //  - seed_value
            if ($this->extract_settings(
                $request,
                [
                    'aspect_ratio' => 'aspect_ratio',
                    'seed_value' => 'seed_value'
                ],
                $params,
                $settings_override
            ))
                $this->log['settings_override'] = $settings_override;

            if (empty($request))
                array_push($this->messages, $this->language->lang('AILABS_NO_PROMPT'));

            if (empty($attachments))
                array_push($this->messages, $this->language->lang('AILABS_ERROR_PROVIDE_URL'));

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

            if (!empty($attachments)) 
                    $payload['face'] = $attachments[0];

            $payload = array_merge($payload, $params);
        }

        $this->log['settings_override'] =  trim("[url=https://useapi.net/docs/api-pixverse-v1/post-pixverse-" . basename($this->url_post) . "]pixverse/" . basename($this->url_post)  . "[/url]" . PHP_EOL .  $this->log['settings_override']);

        return $payload;
    }
}
