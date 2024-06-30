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

/*

config
{
    "url_generate": "https://api.stability.ai/v2beta/stable-image/generate/sd3",
    "url_headers": {
        "authorization": "Bearer <API_KEY>",
        "accept": "application/json"
    },				
    "model": "sd3-large-turbo",
    "output_format": "jpeg",
    "strength": 0.7
}

template
[quote={poster_name} post_id={post_id} user_id={poster_id}]{request}[/quote]
{response}{attachments}

*/

class stablediffusion_v3 extends GenericController
{
    protected $messages = [];
    protected $settings_override = null;

    protected function prepare($opts)
    {
        $request = $this->job['request'];

        $images = $this->get_attachments_or_urls($request, $this->messages, 'image/png');

        // Extract settings provided by user
        $payload = [
            'model' => $this->cfg->model
        ];

        if ($this->extract_settings(
            $request,
            ['strength' => 'strength', 'aspect_ratio' => 'aspect_ratio', 'seed' => 'seed', 'model' => 'model'],
            $payload,
            $this->settings_override
        ))
            $this->log['settings_override'] = $this->settings_override;

        $payload = array_merge(
            $payload,
            [
                'output_format' => $this->cfg->output_format,
                'mode' => empty($images) ?  'text-to-image' : 'image-to-image'
            ]
        );

        if (!empty($request))
            $payload['prompt'] = $request;

        if (!empty($images)) {
            $payload['image'] = $images[0];

            if (!isset($payload['strength']))
                $payload['strength'] = $this->cfg->strength;
        }

        return $payload;
    }

    protected function submit($opts): resultSubmit
    {
        $api = new GenericCurl($this->cfg->url_headers);
        $api->debug = $this->debug;
        $api->forceMultipart = true;

        $result = new resultSubmit();
        // https://platform.stability.ai/docs/api-reference#tag/Generate/paths/~1v2beta~1stable-image~1generate~1sd3/post
        // https://api.stability.ai/v2beta/stable-image/generate/sd3
        $result->response = $api->sendRequest($this->cfg->url_generate, 'POST', $opts);
        $result->responseCodes = $api->responseCodes;
        return $result;
    }

    protected function parse(resultSubmit $resultSubmit): resultParse
    {
        /*
        Response headers:
            x-request-id: string
                A unique identifier for this request.

            content-type: string
                Examples:
                    image/png - raw bytes
                    application/json; type=image/png - base64 encoded
                    image/jpeg - raw bytes
                    application/json; type=image/jpeg - base64 encoded
                    The format of the generated image.
                    To receive the bytes of the image directly, specify image/* in the accept header. 
                    To receive the bytes base64 encoded inside of a JSON payload, specify application/json.

            finish-reason: string
                Enum: CONTENT_FILTERED SUCCESS
                Indicates the reason the generation finished.

                SUCCESS = successful generation.
                CONTENT_FILTERED = successful generation, however the output violated our content moderation policy and has been blurred as a result.
                NOTE: This header is absent on JSON encoded responses because it is present in the body as finish_reason.

            seed: string
                Example: "343940597"
                The seed used as random noise for this generation.

        Response HTTP 200:
            {
                "image": "<base64>",
                "finish_reason": "SUCCESS",
                "seed": 343940597
            }        

        Response HTTP 400, 401, 404, 500:
            {
                "id": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4",
                "name": "bad_request",
                "errors": ["some-field: is required"]
            }
        */

        $json = json_decode($resultSubmit->response);
        $images = null;

        if (
            empty($json->image) ||
            !in_array(200, $resultSubmit->responseCodes)
        ) {
            if (!empty($json->errors))
                $this->messages = array_merge($this->messages, $json->errors);
        } else {
            $this->job['status'] = 'ok';

            $images = [];

            if ($json->finish_reason !== 'SUCCESS')
                array_push($this->messages, $json->finish_reason);
            else
                array_push($this->messages, 'seed ' . $json->seed);

            $filename = $this->save_base64_to_temp_file($json->image, 0, '.' . $this->cfg->output_format);
            array_push($images, $filename);
            $json->image = '<redacted>';
        }

        $result = new resultParse();
        $result->json = $json;
        $result->images = $images;
        $result->message = empty($this->messages) ? null : implode(PHP_EOL, $this->messages);
        $result->settings = empty($this->settings_override) ? $this->settings_override : $this->language->lang('AILABS_SETTINGS_OVERRIDE', $this->settings_override);

        return $result;
    }
}
