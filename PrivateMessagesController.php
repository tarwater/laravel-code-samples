<?php

namespace App\Http\Controllers;

use App\Blocks;
use App\FeedbackTrigger;
use App\Jobs\SendPushNotification;
use App\PrivateMessages;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;


class PrivateMessagesController extends Controller
{
    /*
     * Create private message
     */
    public function createMessage(Request $request, $id) {

        $user = $request->wantsJson() ?  Auth::guard('api')->user() :  Auth::user();
        $from_user = $user->id;
        $users_id = $id;

        if(empty($request->input('message'))) {
            return response (['errors' => 'Message and Users Id are required'], 400);
        }


        $message = $request->input('message');
        $privateMessage = new PrivateMessages;
        $privateMessage->to_users_id = $users_id;
        $privateMessage->from_users_id = $from_user;
        $privateMessage->message = $message;
        $privateMessage->save();


        $messages = PrivateMessages::where(function($query) use ($users_id, $from_user) {
            $query->where('to_users_id', '=', $users_id, 'and')->where('from_users_id', '=', $from_user)->whereNull('deleted_by_sender');
        })->orWhere(function($query) use ($users_id, $from_user) {
            $query->where('to_users_id', '=', $from_user, 'and')->where('from_users_id', '=', $users_id)->whereNull('deleted_by_recipient');
        })->orderBy('created_at', 'asc')->get();

        $data['to_id'] = $users_id;
        $data['to_username'] = User::find($users_id)->username;
        $data['from_id'] = $from_user;
        $data['messages'] = $messages;


        $to_user = User::find($id);

        $this->dispatch(new SendPushNotification($to_user->id, $user->username . ' sent you a new message.', $from_user));

        if($request->wantsJson())
            return response()->json($privateMessage,200);
        else
            return view('private_message.chat', $data);

    }

    public function displayChat(Request $request, $users_id) {
        $from_user = $request->wantsJson() ?  Auth::guard('api')->user()->id :  Auth::user()->id;
        $to_user = $users_id;

        $messages = PrivateMessages::where(function($query) use ($to_user, $from_user) {
            $query->where('to_users_id', '=', $to_user, 'and')->where('from_users_id', '=', $from_user)->whereNull('deleted_by_sender');
        })->orWhere(function($query) use ($to_user, $from_user) {
            $query->where('to_users_id', '=', $from_user, 'and')->where('from_users_id', '=', $to_user)->whereNull('deleted_by_recipient');
        })->orderBy('created_at', 'asc')->get();

        foreach($messages as $message) {
            if($message->to_users_id == $from_user) {
                $message->read = true;
                $message->save();
            }
        }

        $data['to_username'] = User::find($to_user)->username;
        $data['to_id'] = $to_user;
        $data['from_id'] = $from_user;
        $data['messages'] = $messages;

        $path = substr(URL::previous(),-8);

        if(strpos($path, 'topic') !== false){
            $trigger = FeedbackTrigger::find(11);
            session(['feedback' => array('id' => $trigger->id, 'description' => $trigger->description)]);
        }

        if($request->wantsJson())
            return response()->json($data,200);
        else
            return view('private_message.chat', $data);
    }

    public function index() {
        $user = Auth::user();
        $users = User::where('id', '<>', $user->id)->where(function ($query){
            $query->whereNull('is_admin')->orWhere('is_admin', '=', false);
        })->orderBy('id', 'asc')->get();
        $last_message_array = array();

        foreach($users as $key => $u) {

            if ($u->isBlocked()) {
                $users->forget($key);
                continue;
            }

                $last_message_array[$u->id] = PrivateMessages::where(function ($query) use ($u, $user) {
                    $query->where('to_users_id', '=', $u->id, 'and')->where('from_users_id', '=', $user->id)->whereNull('deleted_by_sender');
                })->orWhere(function ($query) use ($u, $user) {
                    $query->where('to_users_id', '=', $user->id, 'and')->where('from_users_id', '=', $u->id)->whereNull('deleted_by_recipient');
                })->orderBy('created_at', 'desc')->first();

        }

        $data['users'] = $users;
        $data['last_message'] = $last_message_array;

        return view('private_message.index', $data);
    }

    public function getNew(Request $request) {
        $last_message_id = $request->input('last_message_id');
        $from = $request->input('from_user_id');
        $to = $request->input('to_user_id');

        if($last_message_id != 0) {
            $new_messages = PrivateMessages::where('from_users_id', '=', $to, 'and')->where('to_users_id', '=', $from, 'and')->where('id', '>', $last_message_id)->get();
            foreach($new_messages as $message) {
                $message->read = true;
                $message->save();
            }
        }
        else
            $new_messages = PrivateMessages::where('from_users_id', '=', $to, 'and')->where('to_users_id', '=', $from)->get();

        if($new_messages->count() == 0) {
            return response('', 204);
        } else {


            $data['messages'] = $new_messages;
            $data['to_id'] = $to;

            $return_data['page'] = view('private_message.message', $data)->render();
            $return_data['id'] = $new_messages->last()->id;

            if($request->wantsJson())
                return response()->json($data,200);
            else
                return response($return_data, 200);
        }
    }

    public function getForm(Request $request){

        $data['id'] = $request->id;

        return view('private_message.message_new', $data);
    }

    public function clearAll(Request $request){

        $user = $request->wantsJson() ?  Auth::guard('api')->user()->id :  Auth::user()->id;
        $otherUser = $request->id;

        $messages = PrivateMessages::where(function($query) use ($user, $otherUser) {
            $query->where('to_users_id', '=', $user, 'and')->where('from_users_id', '=', $otherUser);
        })->orWhere(function($query) use ($user, $otherUser) {
            $query->where('to_users_id', '=', $otherUser, 'and')->where('from_users_id', '=', $user);
        })->orderBy('created_at', 'asc')->get();

        foreach ($messages as $msg){

            if($msg->to_users_id == $user){
                $msg->deleted_by_recipient = true;
                $msg->save();
            } else {
                $msg->deleted_by_sender = true;
                $msg->save();

            }

        }

        if($request->wantsJson())
            return response('',204);
        else
            return 'success';
    }

    public function clearMsg(Request $request){

        $user = $request->wantsJson() ?  Auth::guard('api')->user()->id :  Auth::user()->id;

        $msg = PrivateMessages::find($request->id);

        if($msg->to_users_id == $user){
            $msg->deleted_by_recipient = true;
            $msg->save();
        } else {
            $msg->deleted_by_sender = true;
            $msg->save();
        }

        if($request->wantsJson())
            return response('',204);
        else
            return 'success';
    }

    public function getUnreadCount(Request $request){

        $user = $request->wantsJson() ?  Auth::guard('api')->user() :  Auth::user();

        $messages = $user->unreadMessages();

        foreach ($messages as $msg){
            $msg->date = $msg->created_at->format('M d\, Y \a\t h:i A');
        }

        return $messages->toJson();

    }

    public function getConversations(){

        $user = Auth::guard('api')->user();

        $friends = array(); // people chatted with

        $messages = PrivateMessages::where('to_users_id', $user->id)->orWhere('from_users_id', $user->id)->orderBy('created_at', 'desc')->get();

        foreach ($messages as $msg){

            if($msg->to_users_id == $user->id && !in_array($msg->from_users_id, $friends) && $msg->deleted_by_recipient != true){
                array_push($friends, $msg->from_users_id);
            } else if($msg->from_users_id == $user->id && !in_array($msg->to_users_id, $friends) && $msg->deleted_by_sender != true){
                array_push($friends, $msg->to_users_id);
            }
        }

        // now $friends = all user IDs this user has chatted with

        //removing blocked users
        $blocks = Blocks::where('blocked_by_users_id', $user->id)->orderBy('created_at', 'desc')->get();
        foreach ($blocks as $block) {
            foreach ($friends as $key => $val){
                if($block->blocked_users_id == $val)
                unset($friends[$key]);
            }
        }
       // print_r($friends);

        $data = array();

        foreach ($friends as $friend){
            $friendArray = array();

            $friendArray['user'] = User::find($friend);
            $lastMessage = PrivateMessages::where([
                ['to_users_id', '=', $friend],
                ['from_users_id', '=', $user->id]
            ])->orWhere([
                ['to_users_id', '=', $user->id],
                ['from_users_id', '=', $friend]
            ])->orderBy('created_at', 'desc')->first();

            $lastMessageReceived = PrivateMessages::where('to_users_id', $user->id)->where('from_users_id', $friend)->whereNull('deleted_by_recipient')->orderBy('created_at', 'desc')->first();

            $friendArray['last_message'] = $lastMessage;
            $friendArray['read'] = $lastMessageReceived ? $lastMessageReceived->read : true;

            array_push($data, $friendArray);
        }

        return response()->json($data,200);
    }

    public function getPeople(){
        //get a list a users
        $user = Auth::guard('api')->user();

        $users = User::where('id', '!=', $user->id)->whereNull('is_admin')->orWhere('is_admin', false)->orderBy('username', 'asc')->get();

        foreach ($users as $key => $user){
            if($user->isBlocked()){
                unset($users[$key]);
            }
        }

        return response()->json($users,200);
    }
}
