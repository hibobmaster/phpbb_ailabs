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

/*

https://ai.google.dev/tutorials/rest_quickstart

config (example)

{
    "url_generateContent": "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro-vision:generateContent?key=<API_KEY>",
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
    }
}

template

{info}[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]{response}

*/

class gemini_vision extends GenericController
{
    protected $messages = [];
    protected $settings;

    protected function prepare($opts)
    {
        $request = $this->job['request'];

        // This will populate $this->tmp_files
        $this->get_attachments_or_urls($request, $this->messages, 'image/png');

        if (empty($this->tmp_files)) {
            if (empty($this->messages))
                array_push($this->messages, $this->language->lang('AILABS_ERROR_PROVIDE_URL'));
            $opts = ['error_message' => implode(PHP_EOL, $this->messages)];
        } else {
            /*
                {
                    "contents":[
                        {
                            "parts":[
                                {"text": "What is this picture?"},
                                {
                                "inline_data": {
                                    "mime_type":"image/jpeg",
                                    "data": "base_64 encoded image"
                                }
                            ]
                        }
                    ]
                }
                */

            // Extract settings provided by user
            $configuration = (array) json_decode(json_encode($this->cfg->generation_config));

            if ($this->extract_numeric_settings($request, ['temperature' => 'temperature', "topk" => "topK", "topp" => "topP"], $configuration, $info)) {
                $this->log['generation_config_override'] = $info;
                $this->settings = $info;
            }

            $this->log['text'] = $request;

            $temp_filename = stream_get_meta_data($this->tmp_files[0])['uri'];

            $opts = [
                'contents' =>
                [[
                    'parts' => [
                        ['text' => empty($this->cfg->prefix) ? '' : $this->cfg->prefix],
                        ['text' => $request],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => base64_encode(file_get_contents($temp_filename))
                            ]
                        ]
                    ]
                ]],
                'safety_settings'   => $this->cfg->safety_settings,
                'generation_config' => $configuration
            ];

            // Prevent from saving contents to logs
            $this->redactOpts = ['data'];
        }

        return $opts;
    }

    protected function submit($opts): resultSubmit
    {
        $result = new resultSubmit();

        if (!empty($opts['contents'])) {
            $api = new GenericCurl();
            $api->debug = $this->debug;

            // https://ai.google.dev/tutorials/rest_quickstart
            $result->response =  $api->sendRequest($this->cfg->url_generateContent, 'POST', $opts);

            $result->responseCodes = $api->responseCodes;
        } else {
            $result->response = json_encode($opts);
        }

        return $result;
    }

    protected function parse(resultSubmit $resultSubmit): resultParse
    {
        /*
        HTTP 200
        {
            "candidates": [
                {
                "content": {
                    "parts": [
                        {
                            "text": "Gemini Vision reply goes here"
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
                    "probability": "LOW"
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

        HTTP 400
        {
            "error": {
            "code": 400,
            "message": "Request payload size exceeds the limit: 4194304 bytes.",
            "status": "INVALID_ARGUMENT"
            }
        }
        */

        $this->job['status'] = 'fail';
        $message = $this->language->lang('AILABS_ERROR_CHECK_LOGS');

        $json = json_decode($resultSubmit->response);

        if (
            empty($json) ||
            !empty($json->error_message) ||
            !in_array(200, $resultSubmit->responseCodes)
        ) {
            $responseCode = '';
            if (isset($resultSubmit->responseCodes) && !empty($resultSubmit->responseCodes))
                $responseCode = ' (' . reset($resultSubmit->responseCodes) . ')';

            if (!empty($json->error_message))
                $message = '[color=#FF0000]' . $json->error_message . $responseCode . '[/color]';

            if (!empty($json->error) && !empty($json->error->message))
                $message = '[color=#FF0000]' . $json->error->message . $responseCode . '[/color]';
        } else {
            if (
                empty($json->candidates) ||
                empty($json->candidates[0]->content) ||
                empty($json->candidates[0]->content->parts) ||
                empty($json->candidates[0]->content->parts[0]->text)
            ) {
                if (!empty($json->candidates) && !empty($json->candidates[0]->finishReason))
                    $message = '[color=#FF0000]Gemini Finish Reason: ' . $json->candidates[0]->finishReason . '[/color]';
                if (!empty($json->promptFeedback) && !empty($json->promptFeedback->blockReason))
                    $message = '[color=#FF0000]Gemini Block Reason: ' . $json->promptFeedback->blockReason . '[/color]';
            } else {
                $this->job['status'] = 'ok';
                $message = $json->candidates[0]->content->parts[0]->text;

                // Attempt to extract citation https://ai.google.dev/api/rest/v1beta/CitationMetadata
                if (!empty($json->candidates[0]->citationMetadata) && !empty($json->candidates[0]->citationMetadata->citationSources)) {
                    $links = '';
                    foreach ($json->candidates[0]->citationMetadata->citationSources as $index => $item)
                        if (!empty($item->uri))
                            $links .= '[url=' .  $item->uri . ']' . ($index + 1) . '[/url] ';
                    if (!empty($links))
                        $message .= PHP_EOL . "[size=85]" . trim($links) . "[/size]";
                }
            }
        }

        $result = new resultParse();
        $result->json = $json;
        $result->message = $message;
        $result->settings = empty($this->settings) ? $this->settings : $this->language->lang('AILABS_SETTINGS_OVERRIDE', $this->settings);

        return $result;
    }
}
