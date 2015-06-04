<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

define('AJAX_SCRIPT', true);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');

$action       = optional_param('action', '', PARAM_ALPHANUM);
$beep_id      = optional_param('beep', '', PARAM_RAW);
$chat_sid     = required_param('chat_sid', PARAM_ALPHANUM);
$theme        = required_param('theme', PARAM_ALPHANUMEXT);
$chat_message = optional_param('chat_message', '', PARAM_RAW);
$chat_lasttime = optional_param('chat_lasttime', 0, PARAM_INT);
$chat_lastrow  = optional_param('chat_lastrow', 1, PARAM_INT);

if (!confirm_sesskey()) {
    throw new moodle_exception('invalidsesskey', 'error');
}

if (!$chatuser = $DB->get_record('chat_users', array('sid'=>$chat_sid))) {
    throw new moodle_exception('notlogged', 'chat');
}
if (!$chat = $DB->get_record('chat', array('id'=>$chatuser->chatid))) {
    throw new moodle_exception('invaliduserid', 'error');
}
if (!$course = $DB->get_record('course', array('id'=>$chat->course))) {
    throw new moodle_exception('invalidcourseid', 'error');
}
if (!$cm = get_coursemodule_from_instance('chat', $chat->id, $course->id)) {
    throw new moodle_exception('invalidcoursemodule', 'error');
}

if (!isloggedin()) {
    throw new moodle_exception('notlogged', 'chat');
}

// setup $PAGE so that format_text will work properly
$PAGE->set_cm($cm, $course, $chat);
$PAGE->set_url('/mod/chat/chat_ajax.php', array('chat_sid'=>$chat_sid));

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/chat:chat', $context);

ob_start();
header('Expires: Sun, 28 Dec 1997 09:32:45 GMT');
header('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');

switch ($action) {
case 'isTyping':
    $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'core', 'chatdata');
    
    $response['chat'] = $chatuser;
    $response['response'] = chat_set_isTyping($chatuser);
    

case 'getTyping':
    
    //Determining if the teacher/lecturer has allowed permission of allow typing for students
    $chatUid = 'chat' . $course->id . '_' . $chat->id;
    $allowTyping = optional_param('allowTyping', '', PARAM_INT);
    $cache = cache::make_from_params(cache_store::MODE_APPLICATION, 'core', 'chatdata');
    if($allowTyping > -1){
        $result = $cache->set($chatUid . '_allowTyping', $allowTyping);
        $cache->set( $component );
        $response['allowTyping'] = 1;   //THis is to ensure user with the ability to set typing is not blocked
    }else{
        $response['allowTyping'] = $cache->get($chatUid . '_allowTyping');
    }
    
    //Determining who is chatting
    $cache_chatters = chat_get_isTyping($chatuser);
    $response['chattingCount'] = count($cache_chatters);
    $chatters = '';
    

    
    if(count($cache_chatters)){
        $chattingUsers = $DB->get_records_sql('SELECT firstname, lastname FROM mdl_user WHERE id IN ('.implode(',', $cache_chatters).')');
        
        
        if(count($cache_chatters) == 1){
            $chatters = $chattingUsers[key($chattingUsers)]->firstname . ' ' . $chattingUsers[key($chattingUsers)]->lastname . ' is typing';
        }else{
            
            $i = 1; //Used as counter to break loop 
            $chattersArr = array();
            foreach($chattingUsers as $cUser){
                $chattersArr[] = $cUser->firstname . ' ' . $cUser->lastname[0] . ' ';
                if ($i++ == 3){break;}
            }
            $chatters = implode(',', $chattersArr);
            if (count($cache_chatters) > 3){$chatters .= ' and ' . (count($cache_chatters) - 3) . ' other(s) are typing';}else{$chatters .= ' are typing';}
        }
        
    }
    $response['chatters'] = $chatters;
    echo json_encode($response);
    ob_end_flush();
    break;

case 'init':
    $users = chat_get_users($chatuser->chatid, $chatuser->groupid, $cm->groupingid);
    $users = chat_format_userlist($users, $course);
    $response['users'] = $users;
    echo json_encode($response);
    break;

case 'chat':
    \core\session\manager::write_close();
    chat_delete_old_users();
    $chat_message = clean_text($chat_message, FORMAT_MOODLE);

    if (!empty($beep_id)) {
        $chat_message = 'beep '.$beep_id;
    }

    if (!empty($chat_message)) {

        chat_send_chatmessage($chatuser, $chat_message, 0, $cm);

        $chatuser->lastmessageping = time() - 2;
        $DB->update_record('chat_users', $chatuser);

        // Response OK message.
        echo json_encode(true);
        ob_end_flush();
    }
    break;

case 'update':
    if ((time() - $chat_lasttime) > $CFG->chat_old_ping) {
        chat_delete_old_users();
    }

    if ($latest_message = chat_get_latest_message($chatuser->chatid, $chatuser->groupid)) {
        $chat_newlasttime = $latest_message->timestamp;
    } else {
        $chat_newlasttime = 0;
    }

    if ($chat_lasttime == 0) {
        $chat_lasttime = time() - $CFG->chat_old_ping;
    }

    $params = array('groupid'=>$chatuser->groupid, 'chatid'=>$chatuser->chatid, 'lasttime'=>$chat_lasttime);

    $groupselect = $chatuser->groupid ? " AND (groupid=".$chatuser->groupid." OR groupid=0) " : "";

    $messages = $DB->get_records_select('chat_messages_current',
        'chatid = :chatid AND timestamp > :lasttime '.$groupselect, $params,
        'timestamp ASC');

    if (!empty($messages)) {
        $num = count($messages);
    } else {
        $num = 0;
    }
    $chat_newrow = ($chat_lastrow + $num) % 2;
    $send_user_list = false;
    if ($messages && ($chat_lasttime != $chat_newlasttime)) {
        foreach ($messages as $n => &$message) {
            $tmp = new stdClass();
            // when somebody enter room, user list will be updated
            if (!empty($message->system)){
                $send_user_list = true;
            }
            if ($html = chat_format_message_theme($message, $chatuser, $USER, $cm->groupingid, $theme)) {
                $message->mymessage = ($USER->id == $message->userid);
                $message->message  = $html->html;
                if (!empty($html->type)) {
                    $message->type = $html->type;
                }
            } else {
                unset($messages[$n]);
            }
        }
    }

    if($send_user_list){
        // return users when system message coming
        $users = chat_format_userlist(chat_get_users($chatuser->chatid, $chatuser->groupid, $cm->groupingid), $course);
        $response['users'] = $users;
    }

    $DB->set_field('chat_users', 'lastping', time(), array('id'=>$chatuser->id));

    $response['lasttime'] = $chat_newlasttime;
    $response['lastrow']  = $chat_newrow;
    if($messages){
        $response['msgs'] = $messages;
    }

    echo json_encode($response);
    header('Content-Length: ' . ob_get_length() );

    ob_end_flush();
    break;

default:
    break;
}
