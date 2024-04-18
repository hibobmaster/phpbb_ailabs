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
use Symfony\Component\HttpFoundation\JsonResponse;
use privet\ailabs\includes\AIController;
use privet\ailabs\includes\resultParse;

/*

config (example)

{
  "url_messages": "https://api.anthropic.com/v1/messages",
  "url_headers": {
    "x-api-key": "<API_KEY>",
    "anthropic-version": "2023-06-01"
  },
  "model": "claude-3-sonnet-20240229",
  "max_tokens": 2048,
  "system": "",
  "system_tokens": 0,
  "temperature": 1.0,
  "max_quote_length": 10,
}

template

{info}[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]{response}
{settings}

*/

class claude extends AIController
{
    // https://docs.anthropic.com/claude/reference/messages_post
    // By default, the number of tokens the model can return will be (2048 - prompt tokens).
    protected $max_tokens = 2048;
    protected $settings_override;

    protected function process()
    {
        $this->job['status'] = 'exec';

        $set = [
            'status'            => $this->job['status'],
            'log'               => json_encode($this->log)
        ];

        $this->job_update($set);
        $this->post_update($this->job);

        if (!empty($this->cfg->max_tokens)) {
            $this->max_tokens = (int)$this->cfg->max_tokens;
        }

        $system_tokens = empty($this->cfg->system_tokens) ? 0 : $this->cfg->system_tokens;

        $api = new GenericCurl($this->cfg->url_headers);
        $api->debug = $this->debug;

        $this->job['status'] = 'fail';
        $response = $this->language->lang('AILABS_ERROR_CHECK_LOGS');

        $api_response = null;

        $info = null;
        $content = [];
        $post_first_taken = null;
        $post_first_discarded = null;

        $history = $this->retrieve_history($this->max_tokens);
        $history_count = 0;
        $history_tokens = 0;

        $this->log['history'] = $history;
        $this->log_flush();

        if (!empty($history)) {
            foreach ($history as $key => $value) {

                if ($value['discard']) {
                    $post_first_discarded = $value['postid'];
                    break;
                }

                $history_count++;
                $post_first_taken = $value['postid'];
                $history_tokens += ($value['request_tokens'] + $value['response_tokens']);

                array_unshift(
                    $content,
                    ['role' => 'user', 'content' => $value['request']],
                    ['role' => 'assistant', 'content' => $value['response']]
                );
            }
        }

        $request_text = trim($this->job['request']);

        // Extract settings provided by user
        $configuration = ['temperature' => (float) $this->cfg->temperature];

        if ($this->extract_numeric_settings($request_text, ['temperature' => 'temperature'], $configuration, $this->settings_override))
            $this->log['settings_override'] = $this->settings_override;

        $content[] =  ['role' => 'user', 'content' => $request_text];

        $request_json = [
            'model'             => (string) $this->cfg->model,
            'messages'          => $content,
            'temperature'       => (float) $configuration["temperature"],
            'max_tokens'        => (int) $this->cfg->max_tokens
        ];

        if (!empty($this->cfg->system))
            $request_json["system"] = $this->cfg->system;

        $this->log['request.json'] = $request_json;
        $this->log_flush();

        $request_tokens = 0;
        $response_tokens = 0;

        try {
            // https://docs.anthropic.com/claude/reference/messages_post
            $api_result = $api->sendRequest($this->cfg->url_messages, 'POST', $request_json);

            /*
                Response example 200:
                {
                    "id": "msg_018gCsTGsXkYJVqYPxTgDHBU",
                    "type": "message",
                    "role": "assistant",
                    "content": [
                        {
                            "type": "text",
                            "text": "Sure, I'd be happy to provide..."
                        }
                    ],
                    "stop_reason": "end_turn",
                    "stop_sequence": null,
                    "usage": {
                    "input_tokens": 30,
                    "output_tokens": 309
                    }
                }

                Response example 4xx, 5xx:
                {
                "type": "error",
                "error": {
                    "type": "not_found_error",
                    "message": "The requested resource could not be found."
                }
                }

            */

            $json = json_decode($api_result);
            $this->log['response'] = $json;
            $this->log['response.codes'] = $api->responseCodes;

            $this->log_flush();

            if (
                empty($json->content) ||
                empty($json->content[0]->text) ||
                !in_array(200, $api->responseCodes)
            ) {
                if (!empty($json->error) && !empty($json->error->message))
                    $response = '[color=#FF0000]' . $json->error->message . '[/color]';
            } else {
                $this->job['status'] = 'ok';
                $api_response = $json->content[0]->text;
                $response = $api_response;
                $request_tokens = $json->usage->input_tokens;
                $response_tokens = $json->usage->output_tokens;
                if ($history_tokens > 0 || $system_tokens > 0) {
                    $this->log['request.tokens.raw'] = $request_tokens;
                    $request_tokens = $request_tokens - $history_tokens - $system_tokens;
                    $this->log['request.tokens.adjusted'] = $request_tokens;
                }
            }
        } catch (\Exception $e) {
            $this->log['exception'] = $e->getMessage();
            $this->log_flush();
        }

        $this->log['finish'] = date('Y-m-d H:i:s');

        if ($history_count > 0) {
            $viewtopic = "{$this->root_path}viewtopic.{$this->php_ext}";
            $discarded = '';
            if ($post_first_discarded != null) {
                $discarded = $this->language->lang('AILABS_POSTS_DISCARDED', $viewtopic, $post_first_discarded);
            }
            $total_posts_count = $history_count * 2 + 2;
            $total_tokens_used_count = $history_tokens + $request_tokens + $response_tokens;
            $info = $this->language->lang(
                'AILABS_DISCARDED_INFO',
                $viewtopic,
                $post_first_taken,
                $total_posts_count,
                $discarded,
                $total_tokens_used_count,
                $this->max_tokens
            );
        }

        $resultParse = new resultParse();
        $resultParse->message = $response;
        $resultParse->info = $info;
        $resultParse->settings = empty($this->settings_override) ? $this->settings_override : $this->language->lang('AILABS_SETTINGS_OVERRIDE', $this->settings_override);

        $response = $this->replace_vars($this->job, $resultParse);

        $data = $this->post_response($this->job, $response);

        $this->job['response_time'] = time();
        $this->job['response_post_id'] = $data['post_id'];

        $set = [
            'status'                        => $this->job['status'],
            'attempts'                      => $this->job['attempts'] + 1,
            'response_time'                 => $this->job['response_time'],
            'response'                      => utf8_encode_ucr($api_response),
            'request_tokens'                => $request_tokens,
            'response_post_id'              => $this->job['response_post_id'],
            'response_tokens'               => $response_tokens,
            'log'                           => json_encode($this->log)
        ];

        $this->job_update($set);
        $this->post_update($this->job);

        return new JsonResponse($this->log);
    }
}
