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
    "api_key":                  "<useapi.net api token>",
    "url_create":               "https://api.useapi.net/v1/pika/create",
    "discord":                  "<Discord token, required>",
    "channel":                  "<Discord channel id, required>",
    "maxJobs":                  "<Pika subscription plan Maximum Concurrent Jobs, optional, default 10>",
    "retryCount":               "<Maximum attempts to submit request, optional, default 80>",
    "timeoutBeforeRetrySec":    "<Time to wait before next retry, optional, default 15>",
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{mp4}
{info}

*/

class pika extends useapi_controller
{
    protected function setup()
    {
        $this->attachments_ext = 'image/png';
        $this->info_buttons = $this->language->lang('AILABS_QUOTE_BUTTONS');
        $this->callback_route = 'privet_ailabs_pika_callback';
    }

    protected function payload()
    {
        $payload = null;

        $this->log['settings_override'] = '';

        $maxJobs = empty($this->cfg->maxJobs) ? 10 : $this->cfg->maxJobs;

        if (!empty($this->button) && !empty($this->parent_job_id)) {
            $this->url_post = $this->cfg->url_button;

            $payload = [
                'jobid'     => $this->parent_job_id,
                'button'    => $this->button,
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];
        } else {
            $request = $this->job['request'];

            // See if there attached image or URL reference to one
            $attachments = $this->get_attachments_or_urls($request, $this->messages, $this->attachments_ext);

            // We expect to have prompt with at least one alpha-numeric character or emoji
            if (empty($attachments) && empty($request))
                array_push($this->messages, $this->language->lang('AILABS_NO_PROMPT'));

            // https://useapi.net/docs/api-pika-v1/post-pika-create
            $payload = [
                'discord'   => empty($this->cfg->discord) ? null : $this->cfg->discord,
                'channel'   => empty($this->cfg->channel) ? null : $this->cfg->channel,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $this->url_callback,
                'replyRef'  => (string) $this->job_id,
            ];

            if (!empty($request))
                $payload['prompt'] = $request;

            if (!empty($attachments)) {
                $this->url_post = $this->cfg->url_animate;
                $payload['image'] = $attachments[0];                
            } else
                $this->url_post = $this->cfg->url_create;
        }

        $this->log['settings_override'] =  trim("[url=https://useapi.net/docs/api-pika-v1/post-pika-" . basename($this->url_post) . "]pika/" . basename($this->url_post)  . "[/url]" . PHP_EOL .  $this->log['settings_override']);

        return $payload;
    }
}
