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
    "api_key": "<API_KEY>",
    "url_chat": "https://api.openai.com/v1/chat/completions",
    "model": "gpt-3.5-turbo",
    "temperature": 0.9,
    "max_tokens": 4096,
    "top_p": 1,
    "frequency_penalty": 0,
    "presence_penalty": 0.6,
    "prefix": "This is optional field you can remove it or populate with something like this -> Pretend your are Bender from Futurma",
    "prefix_tokens": 16
    "max_quote_length": 10
}

template

{info}[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]{response}
{settings}

*/

class chatgpt extends AIController
{
    // https://platform.openai.com/docs/api-reference/chat/create#chat/create-max_tokens
    // By default, the number of tokens the model can return will be (4096 - prompt tokens).
    protected $max_tokens = 4096;
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

        $prefix_tokens = empty($this->cfg->prefix_tokens) ? 0 : $this->cfg->prefix_tokens;

        $api_key = $this->cfg->api_key;
        $this->cfg->api_key = null;

        $api = new GenericCurl($api_key);
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

        $request_text = trim($this->job['request']);

        // Extract settings provided by user
        $configuration = ['temperature' => (float) $this->cfg->temperature];

        if ($this->extract_numeric_settings($request_text, ['temperature' => 'temperature'], $configuration, $this->settings_override))
            $this->log['settings_override'] = $this->settings_override;        

        $content[] =  ['role' => 'user', 'content' => $request_text];

        if (!empty($this->cfg->prefix))
            array_unshift(
                $content,
                ['role' => 'system', 'content' => $this->cfg->prefix]
            );

        $request_json = [
            'model'             => $this->cfg->model,
            'messages'          => $content,
            'temperature'       => (float) $configuration["temperature"],
            'frequency_penalty' => (float) $this->cfg->frequency_penalty,
            'presence_penalty'  => (float)$this->cfg->presence_penalty,
        ];

        $this->log['request.json'] = $request_json;
        $this->log_flush();

        $request_tokens = 0;
        $response_tokens = 0;

        try {
            // https://api.openai.com/v1/chat/completions
            $api_result = $api->sendRequest($this->cfg->url_chat, 'POST', $request_json);

            /*
                Response example:
                {
                    'id': 'chatcmpl-1p2RTPYSDSRi0xRviKjjilqrWU5Vr',
                    'object': 'chat.completion',
                    'created': 1677649420,
                    'model': 'gpt-3.5-turbo',
                    'usage': {'prompt_tokens': 56, 'completion_tokens': 31, 'total_tokens': 87},
                    'choices': [
                        {
                            'message': {
                                'role': 'assistant',
                                'content': 'The 2020 World Series was played in Arlington, Texas at the Globe Life Field, which was the new home stadium for the Texas Rangers.'
                            },
                            'finish_reason': 'stop',
                            'index': 0
                        }
                    ]
                }
            */

            $json = json_decode($api_result);
            $this->log['response'] = $json;
            $this->log['response.codes'] = $api->responseCodes;

            $this->log_flush();

            if (
                empty($json->object) ||
                empty($json->choices) ||
                strpos($json->object, 'chat.completion') === false ||
                !in_array(200, $api->responseCodes)
            ) {
            } else {
                $this->job['status'] = 'ok';
                $api_response = $json->choices[0]->message->content;
                $response = $api_response;
                $request_tokens = $json->usage->prompt_tokens;
                $response_tokens = $json->usage->completion_tokens;
                if ($history_tokens > 0 || $prefix_tokens > 0) {
                    $this->log['request.tokens.raw'] = $request_tokens;
                    $request_tokens = $request_tokens - $history_tokens - $prefix_tokens;
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
