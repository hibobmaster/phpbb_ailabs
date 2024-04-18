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
use Symfony\Component\HttpFoundation\JsonResponse;
use privet\ailabs\includes\AIController;
use privet\ailabs\includes\resultParse;

/*

https://ai.google.dev/tutorials/rest_quickstart

config (example)

{
    "url_generateContent": "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro-latest:generateContent?key=<API_KEY>",
    "url_countTokens": "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro-latest:countTokens?key=<API_KEY>",
 	"max_tokens": 30720,
	"max_quote_length": 10,
    "prefix": "Answer all my questions pretending you are a pirate.",
    "safety_settings": [
        {
            "category": "HARM_CATEGORY_SEXUALLY_EXPLICIT",
            "threshold": "BLOCK_NONE"
        },
        {
            "category": "HARM_CATEGORY_HATE_SPEECH",
            "threshold": "BLOCK_NONE"
        },
        {
            "category": "HARM_CATEGORY_HARASSMENT",
            "threshold": "BLOCK_NONE"
        },
        {
            "category": "HARM_CATEGORY_DANGEROUS_CONTENT",
            "threshold": "BLOCK_NONE"
        }
    ],
    "generation_config": {
        "temperature": 0.3,
        "topK": 40,
        "topP": 0.95,
        "candidateCount": 1,
        "maxOutputTokens": 30720
    }
}

template

{info}[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]{response}
{settings}

*/

class gemini extends AIController
{
    /* 
    https://generativelanguage.googleapis.com/v1beta/models?key={{api_key}}
    {
        "name": "models/gemini-1.0-pro-latest",
        "version": "001",
        "displayName": "Gemini 1.0 Pro Latest",
        "description": "The best model for scaling across a wide range of tasks. This is the latest model.",
        "inputTokenLimit": 30720,
        "outputTokenLimit": 2048,
        "supportedGenerationMethods": [
            "generateContent",
            "countTokens"
        ],
        "temperature": 0.9,
        "topP": 1,
        "topK": 1
    }
    */
    protected $max_tokens = 30720;
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

        $api = new GenericCurl();
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
                    ['role' => 'user', 'parts' => [['text' => $value['request']]]],
                    ['role' => 'model', 'parts' => [['text' => $value['response']]]]
                );
            }
        }

        $request_text = trim($this->job['request']);

        // Extract settings provided by user
        $configuration = (array) json_decode(json_encode($this->cfg->generation_config));

        if ($this->extract_numeric_settings($request_text, ['temperature' => 'temperature', "topk" => "topK", "topp" => "topP"], $configuration, $this->settings_override))
            $this->log['settings_override'] = $this->settings_override;

        $content[] =  ['role' => 'user', 'parts' => [['text' => $request_text]]];

        if (!empty($this->cfg->prefix))
            array_unshift(
                $content[0]['parts'],
                ['text' => $this->cfg->prefix]
            );

        /*
            https://ai.google.dev/tutorials/rest_quickstart
            {
                "contents": [
                    {
                        "role": "user",
                        "parts": [
                            {
                                "text": "You are a pirate. Talk like one. Answer all my questions."
                            },
                            {
                                "text": "Tell me a joke."
                            }
                        ]
                    },
                    {
                        "role": "model",
                        "parts": [
                            {
                                "text": "Pirate joke goes here."
                            }
                        ]
                    },
                    {
                        "role": "user",
                        "parts": [
                            {
                                "text": "Tell me more jokes"
                            }
                        ]
                    }
                ],
                "safety_settings": [
                    {
                        "category": "HARM_CATEGORY_SEXUALLY_EXPLICIT",
                        "threshold": "BLOCK_NONE"
                    },
                    {
                        "category": "HARM_CATEGORY_HATE_SPEECH",
                        "threshold": "BLOCK_NONE"
                    },
                    {
                        "category": "HARM_CATEGORY_HARASSMENT",
                        "threshold": "BLOCK_NONE"
                    },
                    {
                        "category": "HARM_CATEGORY_DANGEROUS_CONTENT",
                        "threshold": "BLOCK_NONE"
                    }
                ],
                "generation_config": {
                    "temperature": 0.5,
                    "topK": 32,
                    "topP": 0.8,
                    "candidateCount": 1,
                    "maxOutputTokens": 2048
                }
            }
        */

        $request_json =  [
            'contents'          => $content,
            'safety_settings'   => $this->cfg->safety_settings,
            'generation_config' => $configuration
        ];

        $this->log['request.json'] = $request_json;
        $this->log_flush();

        $request_tokens = $this->countTokens($request_text, 'request.request_tokens');
        $response_tokens = 0;

        try {
            // https://ai.google.dev/tutorials/rest_quickstart
            $api_result = $api->sendRequest($this->cfg->url_generateContent, 'POST', $request_json);

            /*
                Response example:
                {
                    "candidates": [
                        {
                            "content": {
                                "parts": [
                                    {
                                        "text": "What do you call a boomerang that doesn't come back?\n\nA stick."
                                    }
                                ],
                                "role": "model"
                            },
                            "finishReason": "STOP",
                            "index": 0,
                            "safetyRatings": [
                                {
                                    "category": "HARM_CATEGORY_SEXUALLY_EXPLICIT",
                                    "probability": "NEGLIGIBLE"
                                },
                                {
                                    "category": "HARM_CATEGORY_HATE_SPEECH",
                                    "probability": "NEGLIGIBLE"
                                },
                                {
                                    "category": "HARM_CATEGORY_HARASSMENT",
                                    "probability": "NEGLIGIBLE"
                                },
                                {
                                    "category": "HARM_CATEGORY_DANGEROUS_CONTENT",
                                    "probability": "NEGLIGIBLE"
                                }
                            ]
                        }
                    ],
                    "promptFeedback": {
                        "safetyRatings": [
                            {
                                "category": "HARM_CATEGORY_SEXUALLY_EXPLICIT",
                                "probability": "NEGLIGIBLE"
                            },
                            {
                                "category": "HARM_CATEGORY_HATE_SPEECH",
                                "probability": "NEGLIGIBLE"
                            },
                            {
                                "category": "HARM_CATEGORY_HARASSMENT",
                                "probability": "NEGLIGIBLE"
                            },
                            {
                                "category": "HARM_CATEGORY_DANGEROUS_CONTENT",
                                "probability": "NEGLIGIBLE"
                            }
                        ]
                    }
                }
            */

            $json = json_decode($api_result);
            $this->log['response'] = $json;
            $this->log['response.codes'] = $api->responseCodes;

            $this->log_flush();

            if (
                !in_array(200, $api->responseCodes) ||
                empty($json->candidates) ||
                empty($json->candidates[0]->content) ||
                empty($json->candidates[0]->content->parts) ||
                empty($json->candidates[0]->content->parts[0]->text)
            ) {
                if (!empty($json->candidates) && !empty($json->candidates[0]->finishReason))
                    $response = '[color=#FF0000]' . $json->candidates[0]->finishReason . '[/color]';
            } else {
                $this->job['status'] = 'ok';
                $api_response = $json->candidates[0]->content->parts[0]->text;
                $response = $api_response;
                $response_tokens = $this->countTokens($response, 'response.response_tokens');
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

    protected function countTokens($text, $info)
    {
        $curl = new GenericCurl();
        $curl->debug = $this->debug;
        try {
            /*
                https://ai.google.dev/tutorials/rest_quickstart
                {
                    "contents": [
                        {
                            "parts": [
                                {
                                    "text": "Write a story about a magic backpack."
                                }
                            ]
                        }
                    ]
                }
            */
            $result = $curl->sendRequest($this->cfg->url_countTokens, 'POST', [
                'contents' => ['parts' => ['text' => $text]]
            ]);

            /*
                Response example:
                {
                    "totalTokens": 8
                }
            */
            $json = json_decode($result);
            $this->log[$info] = $json;
            $this->log[$info . '.codes'] = $curl->responseCodes;

            $this->log_flush();

            if (!empty($json->totalTokens) && in_array(200, $curl->responseCodes))
                return $json->totalTokens;
        } catch (\Exception $e) {
            $this->log['exception'] = $e->getMessage();
            $this->log_flush();
        }

        return 0;
    }
}
