<?php

require_once "Mail.php";

$emailHost = "ssl://smtp.fastmail.com";
$emailUser = "jon@jonbell.net";
$emailCC = "Jonathan Bell <jon+swe622@jonbell.net>";
$emailPass = file_get_contents("emailpass");
$emailFrom = "Jonathan Bell <jon@jonbell.net>";
$GHACCESSTOKEN = file_get_contents("ghtoken");

$dueDates = array(
    "homework-1" => "February 8, 2017 4:00pm EST",
    "homework-2" => "February 22, 2017 4:00pm EST",
    "homework-3" => "March 22, 2017 4:00pm EST"
);
$students = array_map('str_getcsv', file('students.csv'));

ini_set("date.timezone","UTC");
$payload = json_decode($_POST['payload']);
$uid = $payload->release->author->login;
$link = $payload->release->zipball_url;
$tag = $payload->release->tag_name;
$target = $payload->release->target_commitish;
$date = $payload->release->published_at;
//    $date = "February 8, 2017 4:10pm EST";
$localTime = strtotime($date);

$repoName = $payload->repository->name;
$logStr = $uid."-".$repoName."-".$localTime.": ";

foreach($students as $student)
    if($student[0] == $uid)
        break;
if($student[0] != $uid)
{
    file_put_contents("event.log","ERROR: ".$logStr."Invalid user: $uid not found in students.csv\n",FILE_APPEND);
}
else
{
    $email=$student[3];
    $lastName=$student[1];
    $firstName=$student[2];


    $dueDateStr = null;
    foreach($dueDates as $assignment => $d)
    {
        if(strstr($repoName,$assignment))
        {
            $dueDateStr = $d;
            break;
        }
    }
    $publishedTime = new DateTime($date,new DateTimeZone("UTC"));
    $publishedTimeWithGrace = new DateTime($date,new DateTimeZone("UTC"));
    $publishedTimeWithGrace->sub(date_interval_create_from_date_string("10 minutes"));

    $publishedTime->setTimezone(new DateTimeZone("America/New_York"));
    $dueDate = new DateTime($dueDateStr, new DateTimeZone(("America/New_York")));
    $hardStop = new DateTime($dueDateStr, new DateTimeZone(("America/New_York")));
    $hardStop = $hardStop->add(date_interval_create_from_date_string('24 hours'));
    $tillLate = $publishedTime->diff($dueDate);
    $tillHardStop = $publishedTime->diff($hardStop);
    $isLate = $publishedTimeWithGrace > $dueDate;
    $isTooLate = $publishedTimeWithGrace > $hardStop;

    $message ='Dear '.$firstName.' '.$lastName.',
This message confirms that your SWE 622 '.$assignment.' release, tagged "'.$tag.'" and released from repository '.$repoName.'@'.$target.' was successfully received.

Your release was dated '.$publishedTime->format("M-d-Y h:i:s a").' and the due date for this assignment ' .($isLate? "was":"is") .
        ' '.$dueDate->format("M-d-Y h:i:s a").'.
        
';
    if($isTooLate)
    {
        $message .= "Note that you missed the deadline by > 24 hours (by ".$tillHardStop->format("%a days %h hours %i minutes")."), and hence, this submission will not be graded.";
    }
    else if($isLate)
    {
        $message .= "Note that you missed the deadline by < 24 hours (by ".$tillLate->format("%h hours %i minutes").") and hence your grade for this assignment will be reduced by 10%. ";
        $message .= "You may continue to resubmit until " . $hardStop->format("M-d-Y h:i:s a") . " at no additional point loss.
        
If you realize now that this submission was an error, and would like for Prof Bell to grade a PREVIOUS (not late) submission instead please email him IMMEDIATELY.";
    }
    else
    {
        $message .= "Note that you can continue to resubmit by creating a new release until ".$dueDate->format("M-d-Y h:i:s a"). " at no penalty. Past that date, you may resubmit until " . $hardStop->format("M-d-Y h:i:s a") . " for a penalty of a 10% reduction on this assignment grade.";
    }

    $message .= '

PLEASE double check that your released code includes all files, and the correct version of all files. This is purely your responsibility. If the released code does not build or run but you produce some other code post-deadline that does, it will still be only your released code that is evaluated. Download the released zip file from GitHub (that\'ll be exactly what is graded!) and try to build and run it in the VM (that\'ll be how we run it!). If it doesn\'t work, fix it! 

Happy coding,
Prof Bell\'s Auto-Responding Release-Downloading Robot
';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_USERAGENT, "jon-bell");

    curl_setopt($ch,CURLOPT_URL,$link."?access_token=$GHACCESSTOKEN");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $release = curl_exec($ch);

    if(!is_dir($assignment))
        mkdir($assignment);
    if(!is_dir($assignment."/".$lastName."-".$firstName))
        mkdir($assignment."/$lastName-$firstName");
    $filename = $assignment."/$lastName-$firstName/".($isTooLate?"LATELATE":($isLate? "LATE" :"")).$publishedTime->format('Y-m-d-H-i-s')."-".$uid.".zip";
    file_put_contents($filename, $release);

    print "File: $filename\n";
    $headers = array("From" => $emailFrom,
        "To"=>$email,
        "Cc"=>$emailFrom,
        "Subject"=>"[SWE622-ReleaseBot] Release received for ".$assignment. " from ".$uid);
    $smtp = Mail::factory("smtp",array("host"=>$emailHost,
        "port"=>465,"auth"=>true,"username"=>$emailUser,"password"=>$emailPass));
    $mail = $smtp->send($email.", ".$emailCC,$headers,$message);

    if (PEAR::isError($mail)) {
        file_put_contents("event.log","ERROR: $logStr unable to send mail ".$mail->getMessage()."\n",FILE_APPEND);
    }
    else{
        file_put_contents("event.log","OK: $logStr $filename\n",FILE_APPEND);
    }


}

//print_r($payload->release);
//print_r($student);
?>