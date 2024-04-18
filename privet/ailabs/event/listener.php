<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace privet\ailabs\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use privet\ailabs\includes\RequestHelper;

class listener implements EventSubscriberInterface
{

    protected $user;
    protected $auth;
    protected $db;
    protected $helper;
    protected $language;
    protected $request;

    /** @var string phpBB root path */
    protected $root_path;

    /** @var string PHP extension */
    protected $php_ext;

    protected $users_table;
    protected $jobs_table;

    public function __construct(
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\request\request_interface $request,
        string $root_path,
        string $php_ext,
        string $users_table,
        string $jobs_table
    ) {
        $this->user = $user;
        $this->auth = $auth;
        $this->db = $db;
        $this->helper = $helper;
        $this->language = $language;
        $this->request = $request;
        $this->root_path = $root_path;
        $this->php_ext = $php_ext;
        $this->users_table = $users_table;
        $this->jobs_table = $jobs_table;
    }

    static public function getSubscribedEvents()
    {
        return array(
            'core.posting_modify_submit_post_after' => 'post_ailabs_message',
            'core.viewtopic_post_rowset_data'       => 'viewtopic_post_rowset_data',
            'core.viewtopic_modify_post_row'        => 'viewtopic_modify_post_row',
            'core.user_setup'                       => 'load_language_on_setup',
            'core.submit_post_modify_sql_data'      => 'submit_post_modify_sql_data'
        );
    }

    public function submit_post_modify_sql_data($event)
    {
        if (in_array($event['post_mode'], ['post', 'reply', 'quote'])) {

            $sql_data = $event['sql_data'];

            if (empty($sql_data[POSTS_TABLE]['sql']['post_ailabs_data'])) {
                $sql_data[POSTS_TABLE]['sql']['post_ailabs_data'] = '';
                $event['sql_data'] = $sql_data;
            }
        }
    }

    /**
     * https://area51.phpbb.com/docs/dev/3.2.x/extensions/tutorial_events.html
     * Load the Acme Demo language file
     *     acme/demo/language/en/demo.php
     *
     * @param \phpbb\event\data $event The event object
     */
    public function load_language_on_setup($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = array(
            'ext_name' => 'privet/ailabs',
            'lang_set' => 'common',
        );
        $event['lang_set_ext'] = $lang_set_ext;
    }

    /**
     * Post a message
     *
     * @param \phpbb\event\data	$event	Event object
     */
    public function post_ailabs_message($event, $forum_data)
    {
        $mode = $event['mode'];

        // Only for new topics and posts with mention (quote/reply/@mention) to AI Labs user posts        
        // Allow user to edit post and @mention AI bot 
        if (!in_array($mode, ['post', 'reply', 'quote', 'edit'])) {
            return false;
        }

        $forum_id = $event['forum_id'];

        $ailabs_users_forum = $this->ailabs_users_forum($forum_id);

        if (empty($ailabs_users_forum)) {
            return false;
        }

        $post_id = $event['data']['post_id'];

        $configured_ai_users = [];
        $mention_ai_users = [];

        $ailabs_users_notified = $this->ailabs_users_notified($post_id, $configured_ai_users);

        $ailabs_users = array();

        foreach ($ailabs_users_forum as $user) {
            if ($mode == 'post' && $user['post']) {
                array_push($ailabs_users, $user);
                array_push($mention_ai_users, $user);
            } else {
                if ($mode == 'reply' && $user['reply']) {
                    array_push($ailabs_users, $user);
                    array_push($mention_ai_users, $user);
                } else {
                    if ($user['mention'] && in_array($user['user_id'], $ailabs_users_notified))
                        array_push($ailabs_users, $user);
                    if ($user['mention'] && in_array($user['user_id'], $configured_ai_users))
                        array_push($mention_ai_users, $user);
                }
            }
        }

        if (empty($ailabs_users)) {
            return false;
        }

        $message_parser = new \parse_message($event['data']['message']);
        $message_parser->remove_nested_quotes(0);
        $message_parser->decode_message();

        $request = $message_parser->message;

        // Remove all mentioned AI user names from request
        foreach ($mention_ai_users as $user) {
            $userName = $user['username'];
            $count = 0;
            // v 2.x uses smention tag with user id inside: [smeniton u=user_id]user_name[/smention]
            $updated = preg_replace("/\[smention\s*u=\d+\]\s*$userName\s*\[\/smention\]\s*/", '', $request, -1, $count);
            if ($count > 0) {
                $request = $updated;
            }
            // v 1.x uses mention tag
            $updated = preg_replace('/\[mention\][\s\t]?' . $user['username'] . '[\s\t]?\[\/mention\]/', '', $request, -1, $count);
            if ($count > 0) {
                $request = $updated;
            }
        }

        // Replace mention tags
        // v 2.x uses smention tag with user id inside: [smeniton u=user_id]user_name[/smention]
        $request = preg_replace(array('/\[smention\s*u=\d+\]/', '/\[\/smention\]/'), array('', ''), $request);
        // v 1.x uses mention tag
        $request = preg_replace(array('/\[mention\]/', '/\[\/mention\]/'), array('', ''), $request);

        // Replace size tags
        $request = preg_replace(array('/\[size=[0-9]+\]/', '/\[\/size\]/'), array('', ''), $request);

        // Remove leading and trailing spaces as well as all double spaces
        $request = trim(str_replace('  ', ' ', $request));

        // utf8_encode_ucr added in phpBB v3.2.7 
        // See comments at http://area51.phpbb.com/code-changes/3.2.7/side-by-side/3.2.11/phpbb-includes-utf-utf_tools.php.html
        if (function_exists('utf8_encode_ucr')) {
            $request = utf8_encode_ucr($request);
        }

        $requestHelper = new RequestHelper($this->request);
        $context = $requestHelper->streamContextCreate("HEAD");

        // https://area51.phpbb.com/docs/dev/master/db/dbal.html
        foreach ($ailabs_users as $user) {
            $data = [
                'ailabs_user_id'    => $user['user_id'],
                'ailabs_username'   => $user['username'],
                'request_time'      => time(),
                'post_mode'         => $mode,
                'post_id'           => $post_id,
                'forum_id'          => $forum_id,
                'poster_id'         => $this->user->data['user_id'],
                'poster_name'       => $this->user->data['username'],
                'request'           => utf8_encode_ucr($request),
                'ref'               => bin2hex(random_bytes(21))
            ];
            $sql = 'INSERT INTO ' . $this->jobs_table . ' ' . $this->db->sql_build_array('INSERT', $data);
            $result = $this->db->sql_query($sql);
            $this->db->sql_freeresult($result);
            $data['job_id'] = $this->db->sql_nextid();

            $this->update_post($data);

            $url = append_sid(generate_board_url() . $user['controller'], ['job_id' => $data['job_id']], true, $this->user->session_id);

            // Provide same cookies, session id and browser name so phpBB can authenticate user. 
            // Verify that Server Configuration > Security Settings > Session IP validation set to none 
            get_headers($url, false, $context);
            unset($data);
        }
    }

    private function update_post($data)
    {
        $where = [
            'post_id' => $data['post_id']
        ];
        $data = array(
            'job_id' => $data['job_id'],
            'ailabs_user_id' => $data['ailabs_user_id'],
            'ailabs_username' => $data['ailabs_username'],
        );
        $set =  '\'' . json_encode($data) . ',\'';
        $concat = $this->db->sql_concatenate('post_ailabs_data', $set);
        $sql = 'UPDATE ' . POSTS_TABLE . ' SET post_ailabs_data = ' . $concat . ' WHERE ' . $this->db->sql_build_array('SELECT', $where);
        $result = $this->db->sql_query($sql);
        $this->db->sql_freeresult($result);
    }

    /**
     * Check if forum enabled for ailabs users
     * @param int $id
     * @return array of found ailabs user_ids along with allowed actions for each [user_id, post, mention]
     */
    private function ailabs_users_forum($id)
    {
        $return = array();
        $sql = 'SELECT c.user_id, ' .
            'c.forums_post, ' .
            'c.forums_reply, ' .
            'c.forums_mention, ' .
            'c.controller, ' .
            'u.username ' .
            'FROM ' . $this->users_table . ' c ' .
            'JOIN ' . USERS_TABLE . ' u ON c.user_id = u.user_id ' .
            'WHERE c.enabled = 1';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            array_push($return, array(
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'post' => strpos($row['forums_post'], '"' . $id . '"') !== false || strpos($row['forums_post'], '"ALL"') !== false,
                'reply' => strpos($row['forums_reply'], '"' . $id . '"') !== false || strpos($row['forums_reply'], '"ALL"') !== false,
                'mention' => strpos($row['forums_mention'], '"' . $id . '"') !== false || strpos($row['forums_mention'], '"ALL"') !== false,
                'controller' => $row['controller']
            ));
        }
        $this->db->sql_freeresult($result);
        return $return;
    }

    /**
     * Check if any of ailabs user notified in this post.
     * Exclude users which already have job.
     * @param int $post_id
     * @return array of notified ailabs users (without jobs)
     */
    private function ailabs_users_notified($post_id, &$configured_users = [])
    {
        $return = array();
        $sql = 'SELECT c.user_id AS user_id, COUNT(j.job_id) AS cnt FROM ' . $this->users_table . ' c ' .
            ' JOIN ' . NOTIFICATIONS_TABLE . ' n  ON n.user_id = c.user_id AND n.item_id = ' . (int) $post_id .
            ' LEFT JOIN ' . $this->jobs_table . ' j  ON j.ailabs_user_id = c.user_id AND j.post_id = ' . (int) $post_id .
            ' WHERE c.enabled = 1 ' .
            ' GROUP BY c.user_id';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            array_push($configured_users, $row['user_id']);
            if ($row['cnt'] == 0)
                array_push($return, $row['user_id']);
        }
        $this->db->sql_freeresult($result);
        return $return;
    }

    private function get_status($status)
    {
        switch ($status) {
            case null:
                return $this->language->lang('AILABS_THINKING');
            case 'exec':
                return $this->language->lang('AILABS_REPLYING');
            case 'ok':
                return $this->language->lang('AILABS_REPLIED');
            case 'fail':
                return $this->language->lang('AILABS_UNABLE_TO_REPLY');
            case 'query':
                return $this->language->lang('AILABS_QUERY');
        }

        return $status;
    }

    public function viewtopic_post_rowset_data($event)
    {
        $rowset_data = $event['rowset_data'];
        $rowset_data = array_merge($rowset_data, [
            'post_ailabs_data'  => $event['row']['post_ailabs_data'],
        ]);
        $event['rowset_data'] = $rowset_data;
    }

    public function viewtopic_modify_post_row($event)
    {
        $post_ailabs_data = $event['row']['post_ailabs_data'];
        $post_id = $event['row']['post_id'];

        $jobs = array();

        if (!empty($post_ailabs_data)) {
            $json_data = json_decode('[' . rtrim($post_ailabs_data, ',')  . ']');
            if (!empty($json_data)) {
                /* 
                    [
                        job_id: <int>,                         
                        ailabs_user_id: <int>,
                        ailabs_username: <string>, 
                        response_time: <int>,
                        status: <string>, 
                        response_post_id: <int>
                    ]
                */
                foreach ($json_data as $job) {
                    $ailabs_user_id = (string) $job->ailabs_user_id;
                    $response_time = empty($job->response_time) ? 0 : $job->response_time;
                    if (
                        !in_array($ailabs_user_id, $jobs) ||
                        $jobs[$ailabs_user_id]->$response_time < $response_time
                    ) {
                        $jobs[$ailabs_user_id] = $job;
                    }
                }
            }
            unset($json_data);
        }

        $ailabs = array();

        foreach ($jobs as $key => $value) {
            $value->user_url = generate_board_url() . '/' . append_sid("memberlist.$this->php_ext", 'mode=viewprofile&amp;u=' . $value->ailabs_user_id, true, $this->user->session_id);
            if (!empty($value->response_post_id)) {
                $value->response_url = generate_board_url() . '/' . append_sid("viewtopic.$this->php_ext", "p=$value->response_post_id#p$value->response_post_id", true, $this->user->session_id);
            }
            $value->status = $this->get_status(empty($value->status) ? null : $value->status);
            array_push($ailabs, $value);
        }

        if (!empty($ailabs)) {
            $event['post_row'] = array_merge($event['post_row'], [
                'U_AILABS'              => $ailabs,
            ]);
            if ($this->auth->acl_get('a_', 'm_')) {
                $event['post_row'] = array_merge($event['post_row'], [
                    'U_AILABS_VIEW_LOG' => $this->helper->route('privet_ailabs_view_log_controller_page', ['post_id' => $post_id]),
                ]);
            }
        }
    }
}
