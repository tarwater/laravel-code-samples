<?php

namespace App\Http\Controllers;


use App\AbuseReports;
use App\AnsweredTriviaQuestion;
use App\Blocks;
use App\DistressAnswer;
use App\DistressQuestion;
use App\DistressQuestionCategory;
use App\DistressSurvey;
use App\Session;
use App\Terms;
use App\TriviaAnswer;
use App\User;
use App\UserStressGauge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PanelController extends Controller
{
    public function index(Request $request)
    {

        $tenMinutesAgo = strtotime('-10 minutes');
        //The session table maintains a record of active sessions
        $onlineUsers = Session::where('last_activity', '>=', $tenMinutesAgo)->get()->count();
        $data['onlineUsers'] = $onlineUsers;

        $reportedMessages = AbuseReports::whereNull('deleted_at')->get()->count();
        $data['reportedMessages'] = $reportedMessages;

        $blockedUserCount = Blocks::all()->groupBy('blocked_users_id')->count();
        $data['blockedUsers'] = $blockedUserCount;

        $users = User::all();

        foreach ($users as $key => $user) {
            if ($user->lastStressValue() == null || $user->lastStressValue() < 5) {
                $users->forget($key);
            }
        }

        $data['stressedUsers'] = $users->count();

        $terms = Terms::all()->last();
        $data['terms'] = $terms != null ? $terms->terms : 'No terms have been added yet';

        return view('admin.admin', $data);
    }

    public function abuseReports()
    {

        $reports = AbuseReports::whereNull('deleted_at')->orderBy('created_at', 'desc')->get();

        $data['reports'] = $reports;

        return view('admin.reports', $data);
    }

    public function blocks()
    {

        $blockedUsers = Blocks::select('blocked_users_id')->get()->groupBy('blocked_users_id');

        foreach ($blockedUsers as $block) {
            $block['blockingUsers'] = array();
        }

        $data['blockedUsers'] = $blockedUsers;

        return view('admin.blocks', $data);
    }

    public function results()
    {

        $users = collect();

        $answers = DistressAnswer::orderBy('created_at', 'desc')->get()->unique('users_id');

        foreach ($answers as $a) {
            $user = User::find($a->users_id);
            $users->push($user);
        }

        $users = $users->sortBy('username');

        $data['users'] = $users;
        return view('admin.results', $data);

    }

    public function surveys(Request $request)
    {

        $user = User::find($request->id);

        $answers = DistressAnswer::where('users_id', $user->id)->orderBy('distress_questions_id', 'asc')->orderBy('created_at', 'desc')->get();

        $numSurveys = $answers->count() / 40;

        $dates = array();

        for ($i = 0; $i < $numSurveys; $i++) {

            array_push($dates, $answers[$i]->created_at);
        }

        $data['dates'] = $dates;
        $data['user'] = $user;

        return view('admin.surveys', $data);

    }

    public function surveyReport(Request $request)
    {

        $user = User::find($request->id);
        $answers = DistressAnswer::where('users_id', $user->id)->orderBy('distress_questions_id', 'asc')->orderBy('created_at', 'desc')->get();
        $numSurveys = $answers->count() / 40;

        $nth = $request->nth;

        $questions = array();

        for ($i = 0; $i < $answers->count(); $i = $i + $numSurveys) {
            array_push($questions, $answers[$i + $nth]);
        }

        $categories = DistressQuestionCategory::all();

        $answersArr = array();

        foreach ($categories as $cat) {
            $answersArr[$cat->name] = array();
        }

        foreach ($questions as $q) {
            $catName = $q->distressQuestion->distressQuestionCategory->name;
            array_push($answersArr[$catName], $q);
        }

        $data['results'] = $answersArr;
        $data['user'] = $user;
        $data['date'] = $questions[0]->created_at;

        return view('admin.survey_report', $data);

    }

    public function stressResults(){

        $users = UserStressGauge::orderBy('created_at', 'desc')->get()->unique('users_id');

        foreach ($users as $user){
            $user->username = $user->user->username;
        }

        $users = $users->sortBy('username');

        $data['users'] = $users;

        return view('admin.stress_results', $data);

    }

    public function stressReport(Request $request){

        $results = UserStressGauge::where('users_id', $request->id)->orderBy('created_at', 'desc')->get();

        $data['user'] = User::find($request->id);
        $data['results'] = $results;

        return view('admin.stress_report', $data);

    }

    public function stressLevels(Request $request){

        $values = UserStressGauge::all()->groupBy('value')->map(function ($val) {
            return $val->count();
        });

        $data['values'] = $values;

        return view('admin.stress_values', $data);
    }

    public function stressLevelsDetails(Request $request){

        $value = $request->id;

        $records = UserStressGauge::where('value', $value)->orderBy('created_at', 'desc')->get();

        $data['value'] = $value;
        $data['records'] = $records;

        return view('admin.stress_level_details', $data);


    }

    public function highStressUsers(){

        $users = User::all();

        foreach ($users as $key => $user) {
            if ($user->lastStressValue() == null || $user->lastStressValue() < 5) {
                $users->forget($key);
            }
        }

        foreach ($users as $user){
            $user['time'] = UserStressGauge::where('users_id', '=', $user->id)->latest()->first()->created_at;
        }

        $users = $users->sortBy('time');

        $data['users'] = $users;

        return view('admin.high_stress_users', $data);

    }

    public function onlineUsers(){

        $tenMinutesAgo = strtotime('-10 minutes');

        $users= Session::where('last_activity', '>=', $tenMinutesAgo)->get();
        $data['users'] = $users;

        return view('admin.online_users', $data);
    }

    public function resultsByAnswer(){

        $questions = DistressQuestion::all();

        foreach ($questions as $q){
            $positive = DistressAnswer::where('distress_questions_id', $q->id)->where('boolean_answer', true)->get()->count();
            $negative = DistressAnswer::where('distress_questions_id', $q->id)->where('boolean_answer', false)->get()->count();

            if($q->distress_question_types_id == 2){
                $positive = DistressAnswer::where('distress_questions_id', $q->id)->where('other_answer', '!=', '')->get()->count();
                $negative = DistressAnswer::where('distress_questions_id', $q->id)->where('other_answer', '')->get()->count();

            }

            $q['positiveCount'] = $positive;
            $q['negativeCount'] = $negative;
        }

        $data['questions'] = $questions;

        return view('admin.results_by_answer', $data);

    }

    public function quizUsers(){

        $users = User::all();


        foreach ($users as $key => $user){

            $user->correct = AnsweredTriviaQuestion::where('users_id', $user->id)->where('point_value', 1)->get()->count();
            $user->incorrect = AnsweredTriviaQuestion::where('users_id', $user->id)->where('point_value', 0)->get()->count();

            if($user->correct == 0 && $user->incorrect == 0){
                $users->forget($key);
            }
        }

        $users = $users->sortByDesc('correct');

        $data['users'] = $users;

        return view('admin.quiz_users', $data);
    }

    public function terms(){

        $terms = Terms::all()->last();

        $data['terms'] = $terms ? $terms->terms : '';
        return view('admin.terms', $data);
    }

    public function getStatuses(){

        $users = User::where('is_support', false)->where('is_admin', false)->orderBy('username', 'asc')->get();

        $data['users'] = $users;

        return view('admin.statuses', $data);
    }

    public function changeStatus(Request $request){

        $user = User::find($request->id);

        if($user->is_active){
            $user->is_active = false;
            $user->api_token = str_random(60);
            $user->save();
        } else {
            $user->is_active = true;
            $user->api_token = str_random(60);
            $user->save();
        }
        return response()->json($user->is_active, 200);
    }

    public function resetPassword(Request $request){

        $user = User::find($request->id);
        $user->reset_code = str_random(32);
        $date = date('Y-m-d H:i:s', strtotime("+15 minutes"));
        $user->reset_code_expiration = $date;
        $user->password = bcrypt(str_random(32)); // set a random password (wont be used by anyone)
        $user->api_token = str_random(60); // set new token to log them out
        $user->save();

        return response()->json('success', 200);
    }

    public function activateAll(){

        $users = User::where('is_support', false)->where('is_admin', false)->get();

        foreach ($users as $user){
            $user->is_active = true;
            $user->api_token = str_random(60);
            $user->save();
        }

        return response()->json('success', 200);
    }

    public function deactivateAll(){

        $users = User::where('is_support', false)->where('is_admin', false)->get();

        foreach ($users as $user){
            $user->is_active = false;
            $user->api_token = str_random(60);
            $user->save();
        }

        return response()->json('success', 200);
    }

    public function resetAll(){
        $users = User::where('is_support', false)->where('is_admin', false)->get();

        foreach ($users as $user){
            $user->reset_code = str_random(32);
            $date = date('Y-m-d H:i:s', strtotime("+15 minutes"));
            $user->reset_code_expiration = $date;
            $user->password = bcrypt(str_random(32)); // set a random password (wont be used by anyone)
            $user->api_token = str_random(60);  // set new token to log them out
            $user->save();
        }

        return response()->json('success', 200);
    }
}