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

use Error;
use privet\ailabs\includes\GenericCurl;
use privet\ailabs\includes\GenericController;
use privet\ailabs\includes\resultSubmit;
use privet\ailabs\includes\resultParse;

use Symfony\Component\HttpFoundation\JsonResponse;

/*

// Generic controller for https://useapi.net API

config (example):  

{
    "api_key":                  "<useapi.net api token>",
    "url":                      "https://api.useapi.net/v1/discord_bot_name",
    "discord":                  "optional|your-discord-token",
    "server":                   "optional|your-discord-server-id",
    "channel":                  "optional|your-discord-channel-id",
    "maxJobs":                  "<Maximum Concurrent Jobs, optional, default 10>",
    "retryCount":               "<Maximum attempts to submit request, optional, default 80>",
    "timeoutBeforeRetrySec":    "<Time to wait before next retry, optional, default 15>",
}

*/

class useapi_controller extends GenericController
{
    // Properties calculated withing the class
    protected $messages = [];
    protected $button = null;
    protected $response_message_id = null;
    protected $button_prompt = null;
    protected $parent_job_id = null;
    protected $url_callback = null;

    // Override setup() to specify below properties
    protected $attachments_ext = 'image/png';
    protected $info_buttons = '';
    protected $callback_route = null;
    protected $extra_buttons  = [];

    // Override payload() to specify below properties
    protected $url_post = null;

    /**
     * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
     */
    public function callback($job_id, $ref, $action)
    {
        $this->job_id = $job_id;

        $this->load_job();

        if (empty($this->job))
            return new JsonResponse('job_id ' . $job_id . ' not found in the database');

        if ($this->job['ref'] !== $ref)
            return new JsonResponse('wrong reference ' . $ref);

        if (in_array($this->job['status'], ['ok', 'failed']))
            return new JsonResponse('job_id ' . $job_id . ' already has final status ' . $this->job['status']);

        $this->log = json_decode($this->job['log'], true);

        // POST body as json
        $data = json_decode(file_get_contents('php://input'), true);

        $json = null;

        switch ($action) {
            case 'posted':
                $response_codes = null;

                // Store entire posted response into log
                foreach ($data as $key => $value) {
                    $this->log[$key] = $value;
                    if ($key === 'response.json')
                        $json = $value;
                    if ($key === 'response.codes') {
                        $response_codes = $value;
                        // We may get no response body at all in some cases
                        if (!in_array(200, $response_codes))
                            $this->job['status'] = 'failed';
                    }
                }

                $this->response_message_id = $this->process_response_message_id($json);

                // Midjourney special case https://useapi.net/docs/api-v2/post-jobs-button
                // HTTP 409 Conflict
                // Button <U1 | U2 | U3 | U4> already executed by job <jobid>
                if (!empty($this->response_message_id) && !empty($response_codes) && in_array(409, $response_codes)) {
                    $sql = 'SELECT j.response_post_id  FROM ' . $this->jobs_table . ' j  WHERE ' .
                        $this->db->sql_build_array('SELECT', ['response_message_id' => $this->response_message_id]);
                    $result = $this->db->sql_query($sql);
                    $row = $this->db->sql_fetchrow($result);
                    $this->db->sql_freeresult($result);

                    if (!empty($row)) {
                        $viewtopic = "{$this->root_path}viewtopic.{$this->php_ext}";
                        $json['response'] = $this->language->lang('AILABS_MJ_BUTTON_ALREADY_USED', $json['button'], $viewtopic, $row['response_post_id']);
                    }
                }

                break;
            case 'reply':
                // Raw response from useapi.net API endpoints:
                $json = $data;

                // Midjourney special case https://useapi.net/docs/api-v2/post-jobs-button
                // Upscale buttons U1..U4 may create race condition, let's rely on .../posted to process response
                if (!empty($json) && !empty($json['code']) && $json['code'] === 409)
                    return new JsonResponse('Skipping 409');

                $this->response_message_id = $this->process_response_message_id($json);

                $this->log['response.json'] = $json;
                $this->log['response.time'] = date('Y-m-d H:i:s');

                break;
        }

        // Assume the worst
        $this->job['status'] = 'failed';
        $this->job['response'] = $this->language->lang('AILABS_ERROR_CHECK_LOGS');

        if (!empty($json)) {
            if (!empty($json['status']))
                switch ($json['status']) {
                    case 'created':
                    case 'started':
                    case 'progress':
                        $this->job['status'] = 'exec';
                        break;
                    case 'completed':
                        $this->job['status'] = 'ok';
                        break;
                }

            $error = empty($json['error']) ? null : $json['error'] . (empty($json['errorDetails']) ? '' : PHP_EOL . $json['errorDetails']);
            $content = empty($json['content']) ? null : preg_replace('/<@(\d+)>/', '', $json['content']);

            // HTTP 200 without any errors
            if (!empty($json['code']) && ($json['code'] == 200) && empty($error))
                $this->job['response'] = $content;
            else
                $this->job['response'] = sprintf($this->language->lang('AILABS_ERROR'), trim($error . PHP_EOL .  $content . ""));

            if (in_array($this->job['status'], ['ok', 'failed'])) {
                array_push($this->messages, $this->job['response']);

                $resultParse = new resultParse();

                // Only attach successfully generated attachments, all other attachments will be deleted from Discord CDN
                if (($this->job['status'] == 'ok') && !empty($json['attachments'])  && is_array($json['attachments'])) {
                    $images = [];
                    $mp4 = [];
                    foreach ($json['attachments'] as $attachment) {
                        $content_type = (string) $attachment['content_type'];
                        $url_adjusted = discord_cdn::cdn((string) $attachment['url']);
                        if (stripos($content_type, 'video') !== false)
                            array_push($mp4, $url_adjusted);
                        else if (stripos($content_type, 'image') !== false)
                            array_push($images, $url_adjusted);
                        else
                            array_push($this->messages, "Unknown content_type " . $content_type . " for " . $attachment['filename']);
                    }
                    if (!empty($images))
                        $resultParse->images = $images;
                    if (!empty($mp4))
                        $resultParse->mp4 = $mp4;
                }

                if (!empty($this->messages))
                    $resultParse->message = implode(PHP_EOL, $this->messages);

                if (!empty($this->log['settings_override']))
                    $resultParse->info = empty($resultParse->info) ? $this->log['settings_override'] : $resultParse->info . PHP_EOL . $this->log['settings_override'];

                if (!empty($json['buttons'])) {
                    $all_buttons = array_merge($json['buttons'], $this->extra_buttons);
                    $buttons_info = $this->info_buttons . implode(" • ", $all_buttons);
                    $resultParse->info =  empty($resultParse->info) ? $buttons_info : $resultParse->info . PHP_EOL . $buttons_info;
                }

                $response = $this->replace_vars($this->job, $resultParse);

                $data = $this->post_response($this->job, $response);

                $this->job['response_post_id'] = $data['post_id'];
            }
        }

        $set = [
            'status'            => $this->job['status'],
            'response'          => utf8_encode_ucr($this->job['response']),
            'response_time'     => time(),
            'response_post_id'  => array_key_exists('response_post_id', $this->job) ? $this->job['response_post_id'] : null,
            'log'               => json_encode($this->log)
        ];

        $this->job_update($set);
        $this->post_update($this->job);

        return new JsonResponse($this->log);
    }

    protected function payload()
    {
        throw new \RuntimeException('payload not provided');
    }

    protected function prepare($opts)
    {
        $pattern = '/<QUOTE\sauthor="' . $this->job['ailabs_username'] . '"\spost_id="(.*)"\stime="(.*)"\suser_id="' . $this->job['ailabs_user_id'] . '">/';

        preg_match_all(
            $pattern,
            $this->job['post_text'],
            $matches
        );

        $parent_job = null;

        if (!empty($matches) && !empty($matches[1][0])) {
            $response_post_id = (int) $matches[1][0];

            $sql = 'SELECT j.job_id, j.response_post_id, j.log, j.response ' .
                'FROM ' . $this->jobs_table . ' j ' .
                'WHERE ' . $this->db->sql_build_array('SELECT', ['response_post_id' => $response_post_id]);
            $result = $this->db->sql_query($sql);
            $parent_job = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);

            // Remove quoted content from the quoted post
            $post_text = sprintf(
                '<r><QUOTE author="%1$s" post_id="%2$s" time="%3$s" user_id="%4$s"><s>[quote=%1$s post_id=%2$s time=%3$s user_id=%4$s]</s>%6$s<e>[/quote]</e></QUOTE>%5$s</r>',
                $this->job['ailabs_username'],
                (string) $response_post_id,
                (string) $this->job['post_time'],
                (string) $this->job['ailabs_user_id'],
                $this->job['request'],
                $parent_job ? utf8_decode_ncr($parent_job['response']) : '...'
            );

            $sql = 'UPDATE ' . POSTS_TABLE .
                ' SET ' . $this->db->sql_build_array('UPDATE', ['post_text' => utf8_encode_ucr($post_text)]) .
                ' WHERE post_id = ' . (int) $this->job['post_id'];
            $result = $this->db->sql_query($sql);
            $this->db->sql_freeresult($result);
        }

        // Check for button 
        if (!empty($parent_job)) {
            $log = json_decode($parent_job['log'], true);
            $request = trim($this->job['request']);

            $button_prompt = null;
            $button = null;

            // Split the string by new line characters
            $lines = preg_split('/\r\n|\r|\n/', $request);

            if (count($lines) >= 2) {
                $button = trim($lines[0]);
                $button_prompt = trim(implode("\n", array_slice($lines, 1)));
            } else {
                $button =  trim($request);
            }

            if (
                !empty($log) &&
                !empty($log['response.json']) &&
                !empty($log['response.json']['jobid']) &&
                !empty($log['response.json']['buttons']) &&
                (
                    in_array($button, $log['response.json']['buttons'], true) ||
                    in_array($button, $this->extra_buttons, true)
                )
            ) {
                $this->button = $button;
                $this->button_prompt = $button_prompt;
                $this->parent_job_id = $log['response.json']['jobid'];
            }
        }

        $this->url_callback = generate_board_url(true) .
            $this->helper->route(
                $this->callback_route,
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'reply'
                ]
            );

        array_push($this->redactOpts, 'discord');

        return $this->payload();
    }

    protected function submit($opts): resultSubmit
    {
        $this->job['status'] = 'query';
        $this->job_update(['status' => $this->job['status']]);
        $this->post_update($this->job);

        $data = null;

        if (empty($this->messages)) {
            $api = new GenericCurl($this->cfg->api_key);
            $api->debug = $this->debug;
            $this->cfg->api_key = null;

            // Content-Type: multipart/form-data
            $api->forceMultipart = true;

            $api->retryCount = empty($this->cfg->retryCount) ? 80 : $this->cfg->retryCount;
            $api->timeoutBeforeRetrySec = empty($this->cfg->timeoutBeforeRetrySec) ? 15 : $this->cfg->timeoutBeforeRetrySec;
            $api->retryCodes = [429];

            $response = $api->sendRequest($this->url_post, 'POST', $opts);

            $data = [
                'request.url'                           => $this->url_post,
                'request.time'                          => date('Y-m-d H:i:s'),
                'request.config.retryCount'             => $api->retryCount,
                'request.config.timeoutBeforeRetrySec'  => $api->timeoutBeforeRetrySec,
                'request.attempts'                      => sizeof($api->responseCodes),
                'response.codes'                        => $api->responseCodes,
                'response.length'                       => strlen($response),
                'response.json'                         => json_decode($response)
            ];
        } else {
            $data = [
                'response.json' =>
                [
                    'error' => implode(PHP_EOL, $this->messages)
                ]
            ];
        }

        $this->url_callback = generate_board_url(true) .
            $this->helper->route(
                $this->callback_route,
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'posted'
                ]
            );

        $api = new GenericCurl();
        $api->debug = $this->debug;

        $api->sendRequest($this->url_callback, 'POST', $data);

        $result = new resultSubmit();
        $result->ignore = true;

        return $result;
    }

    protected function process_response_message_id($json)
    {
        $response_message_id = null;

        if (!empty($json) && !empty($json['jobid']))
            $response_message_id = $json['jobid'];

        if (!empty($response_message_id) && empty($this->job['response_message_id']))
            $this->job_update(['response_message_id' => $response_message_id]);

        return $response_message_id;
    }
}
