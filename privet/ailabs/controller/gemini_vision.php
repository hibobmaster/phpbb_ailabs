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
    protected $tmpfile = null;
    protected $settings;

    protected function prepare($opts)
    {
        // Remove leading new lines and empty spaces 
        $request = preg_replace('/^[\r\n\s]+/', '', $this->job['request']);
        // Adjust quotes 
        $request = str_replace(['&quot;', '&amp;'], ['"', '&'], $request);
        // Remove all BBCodes
        $request = preg_replace('/\[(.*?)=?.*?\](.*?)\[\/\\1\]/i', '$2', $request);

        // Check for attachments first
        $fileContent = $this->load_first_attachment($this->job['post_id']);

        // If none found attempt to find URLs in the post body
        if ($fileContent == false) {
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
                    $fileContent = file_get_contents($temp_filename);
                    $this->log['url_temp_filename'] = $temp_filename;
                    $this->log['url_temp_filename_size'] = filesize($temp_filename);
                } else {
                    $this->log['url_error'] = $url_result;
                    $opts = [
                        'error_message' => $this->language->lang('AILABS_ERROR_UNABLE_DOWNLOAD_URL') . $url .
                            (is_numeric($url_result) && ($url_result != 0) ? ' ( HTTP ' . $url_result . ' )' : '')
                    ];
                }
            } else {
                $opts = ['error_message' => $this->language->lang('AILABS_ERROR_PROVIDE_URL')];
            }
        }

        if ($fileContent !== false) {
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

            $opts = [
                'contents' =>
                [[
                    'parts' => [
                        ['text' => $request],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => base64_encode($fileContent)
                            ]
                        ]
                    ]
                ]],
                'safety_settings'   => $this->cfg->safety_settings,
                'generation_config' => $configuration
            ];

            // Prevent from saving contents to logs
            $this->redactOpts = ['contents'];
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
            }
        }

        $result = new resultParse();
        $result->json = $json;
        $result->message = $message;
        $result->settings = empty($this->settings) ? $this->settings : $this->language->lang('AILABS_SETTINGS_OVERRIDE', $this->settings);

        return $result;
    }
}
