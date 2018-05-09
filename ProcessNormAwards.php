<?php

namespace App\Console\Commands;

use App\NormAward;
use Com\Tecnick\Barcode\Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


class ProcessNormAwards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'norm:awards {email} {path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add Norm awards to database';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

    }

private $expected = 'AWARD NO,PROPOSAL NO,PROPOSAL TYPE,PRIME ACCOUNT,AWARD TITLE,AWARD AMOUNT,AWARD PROJECT PI PID,AWARD PROJECT PI,AWARD PI,SPONSOR,SPONSOR AWARD NO,SPONSOR TYPE,SPONSOR CODE,ARRA FUNDING,OTHER PERSONNEL,AWARD ADMIN DEPT NO,AWARD ADMIN DEPT,AWARD ADMIN SCHOOL,AWARD PROJECT PI DEPT NO,AWARD PROJECT PI DEPT,AWARD PI HOME DEPT NO,AWARD PI HOME DEPT,AWARD PI HOME SCHOOL,OFFICIAL REPORT DATE,REPORT MONTH,FISCAL YEAR,PROJECT BEGIN DATE,PROJECT END DATE,AWARD MECHANISM,AWARD TYPE,F&A ACTIVITY TYPE,CFDA NO,DFAFS NO,CHESS CODE,PRIME SPONSOR CODE,PRIME SPONSOR,LEVEL1,LEVEL2,LEVEL3,LEVEL4,PAYMENT BASIS,PAYMENT METHOD,INVOICE FREQUENCY,PASS THRU AGENCY,PRE AUDIT ID,TYPE OF ACTIVITY,PROGRAM DESIGNATION,FINAL REPORT DUE DATE,TECHNICAL REPORT DUE DATE';
private $mismatchedColumns = array();
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try{
        $log = new Logger('normAwards');
        $path = getcwd() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' .
            DIRECTORY_SEPARATOR . 'process_norm_awards.log';
        $log->pushHandler(new StreamHandler($path, Logger::WARNING));
        $log->debug("Starting Process Norm Awards Job ");
        $path = $this->argument('path');
        $email = $this->argument('email');
        $contents = file($path);
        $firstLine = $contents[0];

        array_shift($contents); //Remove column header line
        array_pop($contents); //Remove the last line, which simply sums up the award amounts

        if($this->verifyColumnHeadersMatch($firstLine)){

            $subject = "NORM Awards Upload";
            $body = "Your file is currently being processed. An email will be sent when it is complete.";
            $this->sendEmail($email, $subject, $body);

            try {

                foreach ($contents as $line) {

                    $line = utf8_encode($line);
                    $line = str_getcsv($line);

                    $line[1] = preg_replace("/[^A-Za-z0-9 -]/", '', $line[1]);; //Strip out that weird character in this column...

                    $award = NormAward::firstOrCreate(array('award_no' => $line[0]));

                    $award->proposal_no = self::getProposalNo($line[1]);
                    $award->proposal_type = $line[2];
                    $award->prime_account = $line[3];
                    $award->award_title = $line[4];
                    $award->award_amount = $line[5];
                    $award->award_project_pi_pid = $line[6];
                    $award->award_project_pi = $line[7];
                    $award->award_pi = $line[8];
                    $award->sponsor = $line[9];
                    $award->sponsor_award_no = $line[10];
                    $award->sponsor_type = $line[11];
                    $award->sponsor_code = $line[12];
                    $award->arra_funding = $line[13];
                    $award->other_personel = $line[14];
                    $award->award_admin_dept_no = $line[15];
                    $award->award_admin_dept = $line[16];
                    $award->award_admin_school = $line[17];
                    $award->award_project_pi_dept_no = $line[18];
                    $award->award_project_pi_dept = $line[19];
                    $award->award_pi_home_dept_no = $line[20];
                    $award->award_pi_home_dept = $line[21];
                    $award->award_pi_home_school = $line[22];
                    $award->official_report_date = date('Y-m-d', strtotime($line[23]));
                    $award->report_month = $line[24];
                    $award->fiscal_year = $line[25];
                    $award->project_begin_date = date('Y-m-d', strtotime($line[26]));
                    $award->project_end_date = date('Y-m-d', strtotime($line[27]));
                    $award->award_mechanism = $line[28];
                    $award->award_type = $line[29];
                    $award->activity_type = $line[30];
                    $award->cfda_no = $line[31];
                    $award->dfafs_no = $line[32];
                    $award->chess_code = $line[33];
                    $award->prime_sponsor_code = $line[34];
                    $award->prime_sponsor = $line[35];
                    $award->level_1 = $line[36];
                    $award->level_2 = $line[37];
                    $award->level_3 = $line[38];
                    $award->level_4 = $line[39];
                    $award->payment_basis = $line[40];
                    $award->payment_method = $line[41];
                    $award->invoice_frequency = $line[42];
                    $award->pass_thru_agency = $line[43];
                    $award->pre_audit_id = $line[44];
                    $award->type_of_activity = $line[45];

                    if(strcmp($line[47], '') == 0){ //Columns 46 and 47 are usually blank, and we don't want the default timestamp going into those
                        $award->final_report_due_date = null;
                    } else {
                        $award->final_report_due_date = date('Y-m-d', strtotime($line[47]));
                    }

                    if(strcmp($line[48], '') == 0){
                        $award->technical_report_due_date = null;
                    } else {
                        $award->technical_report_due_date = date('Y-m-d', strtotime($line[48]));
                    }

                    $award->save();

                }
            } catch (Exception $e) {
                $log->error("NORM Awards Upload Error");
                $log->error("There was an error while processing your file.");
                $log->error($e->getMessage());
                $subject = "NORM Awards Upload Error";
                $body = "There was an error while processing your file.";
                $this->sendEmail($email, $subject, $body);

            }
            $subject = "Norm Awards Upload Finished";
            $body = "Processing has finished successfully.";
            $this->sendEmail($email, $subject, $body);


        } else {

            $subject = "Norm Awards Upload Error";
            $body = "Error: The column headers of the NORM awards .CSV file do not match their expected values:  <br>";

            foreach ($this->mismatchedColumns as $m){
                $body .= 'column ' . $m[0] . ' should be: ' . $m[1] . '<br>';
            }
            $this->sendEmail($email, $subject, $body);
        }
        }
        catch (Exception $e) {
            $log->error($e->getMessage());
        } finally {
            $log->debug("Ending Process NORM Awards Job");
        }
    }

    public function verifyColumnHeadersMatch($arr){
        //This verifies that the column headers in the file match the expected values

        $expected = $this->expected;
        $expected = explode(',', $expected);
        $input = explode(',', trim($arr));

        $match = true;

        for ($i = 0; $i < count($expected); $i++) {

            if(strcmp($expected[$i], $input[$i]) != 0){

                array_push($this->mismatchedColumns, array($input[$i], $expected[$i]));
                $match = false;
            }
        }
        return $match;
    }

    public function sendEmail($email, $subject, $body){

        Mail::send('email.layouts.email', ['user' => $email, 'subject' => $subject, 'body' => $body ],
            function($message) use ($email, $subject, $body) {
                $from_name = SystemProperty::where('name', '=', 'Email From Name')->first();
                if($from_name != null) {
                    $message->from(config('mail.from.address'), $from_name->value);
                }
                $message->to($email);
                $message->subject($subject);
            });
    }

    public static function getProposalNo($field){ //Sometimes there are two numbers in the Proposal No column, this function returns the second one if it exists
        $proposalNumbers = explode(" ", trim($field));
        if(count($proposalNumbers) > 1){
            return $proposalNumbers[1];
        } else {
            return $proposalNumbers[0];
        }
    }
}
