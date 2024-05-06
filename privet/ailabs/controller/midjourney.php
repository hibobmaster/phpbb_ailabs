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

use privet\ailabs\includes\GenericCurl;
use privet\ailabs\includes\GenericController;
use privet\ailabs\includes\resultSubmit;
use privet\ailabs\includes\resultParse;

use Symfony\Component\HttpFoundation\JsonResponse;

/*

// How to get api token and configure Discord 
// https://useapi.net/docs/start-here 

config: 

{
    "api_key":                  "<useapi.net api token>",
    "url_imagine":              "https://api.useapi.net/v2/jobs/imagine",
    "discord":                  "<Discord token, required>",
    "server":                   "<Discord server id, required>",
    "channel":                  "<Discord channel id, required>",
    "maxJobs":                  "<Midjourney subscription plan Maximum Concurrent Jobs, optional, default 3>",
    "retryCount":               "<Maximum attempts to submit request, optional, default 80>",
    "timeoutBeforeRetrySec":    "<Time to wait before next retry, optional, default 15>",
}

template:

[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}
{images}
{info}

*/

class midjourney extends GenericController
{
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

                $response_message_id = $this->process_response_message_id($json);

                // https://useapi.net/docs/api-v2/post-jobs-button
                // HTTP 409 Conflict
                // Button <U1 | U2 | U3 | U4> already executed by job <jobid>
                if (!empty($response_message_id) && !empty($response_codes) && in_array(409, $response_codes)) {
                    $sql = 'SELECT j.response_post_id  FROM ' . $this->jobs_table . ' j  WHERE ' .
                        $this->db->sql_build_array('SELECT', ['response_message_id' => $response_message_id]);
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
                // Raw response from useapi.net /imagine, /button or /seed API endpoints
                $json = $data;

                // Upscale buttons U1..U4 may create race condition, let's rely on .../posted to process response
                if (!empty($json) && !empty($json['code']) && $json['code'] === 409)
                    return new JsonResponse('Skipping 409');

                $this->process_response_message_id($json);

                $this->log['response.json'] = $json;
                $this->log['response.time'] = date('Y-m-d H:i:s');

                break;
        }

        // Assume the worst
        $this->job['status'] = 'failed';
        $this->job['response'] = !empty($json) && !empty($json['error']) ? '[color=#FF0000]' . $json['error'] . '[/color]' : $this->language->lang('AILABS_ERROR_CHECK_LOGS');

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


            if (!empty($json['code']))
                switch ($json['code']) {
                    case 200: // HTTP OK
                        $this->job['response'] = preg_replace('/<@(\d+)>/', '', $json['content']);
                        break;
                    case 422: // HTTP 422 Unprocessable Content - Moderated                    
                        $this->job['response'] =
                            '[color=#FF0000]' . $json['error'] . '
' . $json['errorDetails'] . '[/color]';
                        break;
                }
        }

        if (!empty($json) && in_array($this->job['status'], ['ok', 'failed'])) {
            $resultParse = new resultParse();
            $resultParse->message = $this->job['response'];

            // Only attach successfully generated images, seems like all other images will be deleted from Discord CDN
            if (($this->job['status'] == 'ok') && !empty($json['attachments'])) {
                $url_adjusted = (string) $json['attachments'][0]['url'];
                $url_adjusted = discord_cdn::cdn($url_adjusted);
                $resultParse->images = array($url_adjusted);
            }

            if (!empty($json['buttons']))
                $resultParse->info =  $this->language->lang('AILABS_MJ_BUTTONS') . implode(" • ", $json['buttons']) . " • Seed";

            $response = $this->replace_vars($this->job, $resultParse);

            $data = $this->post_response($this->job, $response);

            $this->job['response_post_id'] = $data['post_id'];
        }

        $set = [
            'status'            => $this->job['status'],
            'response'          => utf8_encode_ucr($this->job['response']),
            'response_time'     => time(),
            'response_post_id'  => $this->job['response_post_id'],
            'log'               => json_encode($this->log)
        ];

        $this->job_update($set);
        $this->post_update($this->job);

        return new JsonResponse($this->log);
    }

    protected function prepare($opts)
    {
        $pattern = '/<QUOTE\sauthor="' . $this->job['ailabs_username'] . '"\spost_id="(.*)"\stime="(.*)"\suser_id="' . $this->job['ailabs_user_id'] . '">/';

        $parent_job = null;
        $matches = null;

        preg_match_all(
            $pattern,
            $this->job['post_text'],
            $matches
        );

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

        $maxJobs = empty($this->cfg->maxJobs) ? 3 : $this->cfg->maxJobs;

        $url_callback = generate_board_url(true) .
            $this->helper->route(
                'privet_ailabs_midjourney_callback',
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'reply'
                ]
            );

        // Remove leading new lines and empty spaces 
        $request = preg_replace('/^[\r\n\s]+/', '', $this->job['request']);
        $request = str_replace('&quot;', '"', $request);
        $payload = null;
        $seed = false;

        if (!empty($parent_job)) {
            $log = json_decode($parent_job['log'], true);
            $prompt = null;
            $button = null;

            // Split the string by new line characters
            $lines = preg_split('/\r\n|\r|\n/', $request);

            if (count($lines) >= 2) {
                $button = trim($lines[0]);
                $prompt = trim(implode("\n", array_slice($lines, 1)));
            } else {
                $button =  trim($request);
            }

            // Seed is a special case, can be only applied to completed job (the one which has buttons)
            $seed = strcasecmp($button, "SEED") == 0;

            // https://useapi.net/docs/api-v2/post-jobs-button
            // https://useapi.net/docs/api-v2/post-jobs-seed
            if (
                !empty($log) &&
                !empty($log['response.json']) &&
                !empty($log['response.json']['jobid']) &&
                !empty($log['response.json']['buttons']) &&
                (in_array($button, $log['response.json']['buttons'], true) || $seed)
            ) {
                $payload = [
                    'jobid'     => $log['response.json']['jobid'],
                    'discord'   => $this->cfg->discord,
                    'replyUrl'  => $url_callback,
                    'replyRef'  => (string) $this->job_id,
                ];

                if ($seed == false) {
                    $payload += ['button' => $button];
                    $payload += ['maxJobs' => $maxJobs];
                }

                if (!empty($prompt))
                    $payload += ['prompt' => $prompt];
            }
        }

        // https://useapi.net/docs/api-v2/post-jobs-imagine
        if (empty($payload)) {
            $payload = [
                'prompt'                => str_replace('&quot;', '"', $request),
                'discord'               => $this->cfg->discord,
                'server'                => $this->cfg->server,
                'channel'               => $this->cfg->channel,
                'maxJobs'               => $maxJobs,
                'replyUrl'              => $url_callback,
                'replyRef'              => (string) $this->job_id,
            ];
        }

        array_push($this->redactOpts, 'discord');

        return $payload;
    }

    protected function submit($opts): resultSubmit
    {
        $this->job['status'] = 'query';
        $this->job_update(['status' => $this->job['status']]);
        $this->post_update($this->job);

        $api = new GenericCurl($this->cfg->api_key, 0);
        $api->debug = $this->debug;
        $this->cfg->api_key = null;

        $retryCount = empty($this->cfg->retryCount) ? 80 : $this->cfg->retryCount;
        $timeoutBeforeRetrySec = empty($this->cfg->timeoutBeforeRetrySec) ? 15 : $this->cfg->timeoutBeforeRetrySec;

        $count  = 0;
        $response = null;
        // https://useapi.net/docs/api-v2/post-jobs-imagine
        $url = $this->cfg->url_imagine;
        // https://useapi.net/docs/api-v2/post-jobs-seed
        // https://useapi.net/docs/api-v2/post-jobs-button
        if (!empty($opts['jobid']))
            $url = empty($opts['button']) ? str_replace('/imagine', '/seed', $url) : str_replace('/imagine', '/button', $url);

        // Attempt to submit request for (retryCount * timeoutBeforeRetrySec) seconds.
        // Required for cases where multiple users simultaneously submitting requests or Midjourney query is full.
        do {
            $count++;
            $response = $api->sendRequest($url, 'POST', $opts);
        } while (
            // 429: Maximum of xx jobs executing in parallel supported
            in_array(429, $api->responseCodes) &&
            $count < $retryCount &&
            sleep($timeoutBeforeRetrySec) !== false
        );

        $data = [
            'request.time'                          => date('Y-m-d H:i:s'),
            'request.config.retryCount'             => $retryCount,
            'request.config.timeoutBeforeRetrySec'  => $timeoutBeforeRetrySec,
            'request.attempts'                      => $count,
            'response.codes'                        => $api->responseCodes,
            'response.length'                       => strlen($response),
            'response.json'                         => json_decode($response)
        ];

        $url_callback = generate_board_url(true) .
            $this->helper->route(
                'privet_ailabs_midjourney_callback',
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'posted'
                ]
            );

        $api->sendRequest($url_callback, 'POST', $data);

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
