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

use privet\ailabs\includes\GenericCurl;
use privet\ailabs\includes\GenericController;
use privet\ailabs\includes\resultSubmit;
use privet\ailabs\includes\resultParse;
use privet\ailabs\includes\RequestHelper;

use Symfony\Component\HttpFoundation\JsonResponse;

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

class pika extends GenericController
{
    protected $tmpfile = null;
    protected $opts_errors = [];

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

                $this->process_response_message_id($json);

                break;
            case 'reply':
                // Raw response from useapi.net API endpoints:
                // - https://useapi.net/docs/api-pika-v1/post-pika-create 
                // - https://useapi.net/docs/api-pika-v1/post-pika-button
                $json = $data;

                $this->process_response_message_id($json);

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

            $error = empty($json['error']) ? null : $json['error'] . (empty($json['errorDetails']) ? '' : "\n" . $json['errorDetails']);
            $content = empty($json['content']) ? null : preg_replace('/<@(\d+)>/', '', $json['content']);

            // HTTP 200
            if (!empty($json['code']) && ($json['code'] == 200))
                $this->job['response'] = $content;
            else
                $this->job['response'] = sprintf($this->language->lang('AILABS_ERROR'), !empty($error) ? $error : $content);
        }

        if (!empty($json) && in_array($this->job['status'], ['ok', 'failed'])) {
            $resultParse = new resultParse();
            $resultParse->message = $this->job['response'];

            // Only attach successfully generated images, seems like all other images will be deleted from Discord CDN
            if (($this->job['status'] == 'ok') && !empty($json['attachments'])) {
                $url_adjusted = (string) $json['attachments'][0]['url'];
                // Do not remove Discord params
                // $url_adjusted = preg_replace('/\?.*$/', '', $url_adjusted);
                $resultParse->mp4 = array($url_adjusted);
            }

            if (!empty($json['buttons']))
                $resultParse->info =  $this->language->lang('AILABS_PIKA_BUTTONS') . implode(" • ", $json['buttons']);

            $response = $this->replace_vars($this->job, $resultParse);

            $data = $this->post_response($this->job, $response);

            $this->job['response_post_id'] = $data['post_id'];
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

        $maxJobs = empty($this->cfg->maxJobs) ? 10 : $this->cfg->maxJobs;

        $url_callback = generate_board_url(true) .
            $this->helper->route(
                'privet_ailabs_pika_callback',
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'reply'
                ]
            );

        // Remove leading new lines and empty spaces 
        $request = preg_replace('/^[\r\n\s]+/', '', $this->job['request']);
        // Adjust quotes 
        $request = str_replace(['&quot;', '&amp;'], ['"', '&'], $request);
        $payload = null;
        $image = null;

        // Check for button 
        if (!empty($parent_job)) {
            $log = json_decode($parent_job['log'], true);
            $button = trim($request);

            // https://useapi.net/docs/api-pika-v1/post-pika-button
            if (
                !empty($log) &&
                !empty($log['response.json']) &&
                !empty($log['response.json']['jobid']) &&
                !empty($log['response.json']['buttons']) &&
                (in_array($button, $log['response.json']['buttons'], true))
            ) {
                $payload = [
                    'jobid'     => $log['response.json']['jobid'],
                    'discord'   => $this->cfg->discord,
                    'maxJobs'   => $maxJobs,
                    'replyUrl'  => $url_callback,
                    'replyRef'  => (string) $this->job_id,
                ];

                $payload += ['button' => $button];
            }
        }

        if (empty($payload)) {
            // Remove all BBCodes
            $request = preg_replace('/\[(.*?)=?.*?\](.*?)\[\/\\1\]/i', '$2', $request);

            // Check for attachments first
            $fileContent = $this->load_first_attachment($this->job['post_id']);

            if (!empty($fileContent)) {
                $this->tmpfile = tmpfile();
                $temp_filename = stream_get_meta_data($this->tmpfile)['uri'];

                fwrite($this->tmpfile, $fileContent);

                $image = curl_file_create($temp_filename, 'image/png');
                $this->log['attachment_temp_filename'] = $temp_filename;
                $this->log['attachment_temp_filename_size'] = filesize($temp_filename);
            } else {
                // If none found attempt to find URLs in the post body
                $url_pattern = '/\bhttps?:\/\/[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|\/))/i';

                preg_match($url_pattern, $request, $urls);

                if (isset($urls[0])) {
                    $url = $urls[0];

                    // Remove URL from the post (request) text
                    $request = str_replace($url, '', $request);

                    $this->log['url'] = $url;
                    $this->log['url_board'] = generate_board_url();

                    $headers = null;

                    // If link is pointing to board download URL attempt to pass user's session cookie 
                    if (stripos($url, generate_board_url()) === 0) {
                        $requestHelper = new RequestHelper($this->request);
                        $requestHelper->streamContextCreate("GET", $headers);
                        if (!empty($headers))
                            $this->log['url_headers'] = $headers;
                    }

                    $this->tmpfile = tmpfile();
                    $temp_filename = stream_get_meta_data($this->tmpfile)['uri'];
                    $url_result = $this->urlToFile($url, $temp_filename, $headers, $this->debug);

                    // HTTP OK
                    if ($url_result == 200) {
                        $image = curl_file_create($temp_filename, 'image/png');
                        $this->log['url_temp_filename'] = $temp_filename;
                        $this->log['url_temp_filename_size'] = filesize($temp_filename);
                    } else {
                        $this->log['url_error'] = $url_result;
                        array_push($this->opts_errors, $this->language->lang('AILABS_ERROR_UNABLE_DOWNLOAD_URL') . $url .
                            (is_numeric($url_result) && ($url_result != 0) ? ' ( HTTP ' . $url_result . ' )' : ''));
                    }
                }
            }

            // We expect to have prompt with at least one alpha-numeric character or emoji
            $has_prompt = !empty(trim($request));

            if (empty($image) && empty($has_prompt))
                array_push($this->opts_errors, $this->language->lang('AILABS_NO_PROMPT'));

            // https://useapi.net/docs/api-pika-v1/post-pika-create
            $payload = [
                'discord'   => $this->cfg->discord,
                'channel'   => $this->cfg->channel,
                'maxJobs'   => $maxJobs,
                'replyUrl'  => $url_callback,
                'replyRef'  => (string) $this->job_id,
            ];

            if (!empty($has_prompt))
                $payload['prompt'] = $request;

            if (!empty($image))
                $payload['image'] = $image;
        }

        array_push($this->redactOpts, 'discord');

        return $payload;
    }

    protected function submit($opts): resultSubmit
    {
        $this->job['status'] = 'query';
        $this->job_update(['status' => $this->job['status']]);
        $this->post_update($this->job);

        $data = null;

        if (empty($this->opts_errors)) {
            $api = new GenericCurl($this->cfg->api_key);
            $api->debug = $this->debug;
            $this->cfg->api_key = null;

            // https://useapi.net/docs/api-pika-v1/post-pika-animate
            // Content-Type: multipart/form-data
            $api->forceMultipart = true;

            $api->retryCount = empty($this->cfg->retryCount) ? 80 : $this->cfg->retryCount;
            $api->timeoutBeforeRetrySec = empty($this->cfg->timeoutBeforeRetrySec) ? 15 : $this->cfg->timeoutBeforeRetrySec;
            $api->retryCodes = [429];

            $response = null;
            // https://useapi.net/docs/api-pika-v1/post-pika-create
            $url = $this->cfg->url_create;
            // https://useapi.net/docs/api-pika-v1/post-pika-button
            if (!empty($opts['jobid']))
                $url = str_replace('/create', '/button', $url);
            else 
                if (!empty($opts['image']))
                $url = str_replace('/create', '/animate', $url);

            $response = $api->sendRequest($url, 'POST', $opts);

            $data = [
                'request.url'                           => $url,
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
                    'error' => implode("\n\r", $this->opts_errors)
                ]
            ];
        }

        $url_callback = generate_board_url(true) .
            $this->helper->route(
                'privet_ailabs_pika_callback',
                [
                    'job_id'    => $this->job_id,
                    'ref'       => $this->job['ref'],
                    'action'    => 'posted'
                ]
            );

        $api = new GenericCurl();
        $api->debug = $this->debug;

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
