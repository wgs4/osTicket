<?php 
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$from_name 	= "osTicket Reports";
$from_mail  = $scheduled_report->get_default('admin_email','core');
$reply_to 	= $from_email;
$subject 	= $scheduled_report->get_human_rtype()." - ".$scheduled_report->get_human_range();
$path       = ROOT_DIR.'scp/'.$scheduled_report->get_default('output_directory');
$filename 	= 'report.pdf';
$attachment	= $path.'/'.$filename;
// $scheduled_report->report_log("Sending attachment $attachment");

$mail = new PHPMailer(true);                              // Passing `true` enables exceptions
try {

    $mail->setFrom($from_mail, 'osTicket Reports');
    
    //Recipients
    $addresses = explode(",",$report_email);
    foreach($addresses as $address)
         $mail->addAddress($address);

    //Attachments
    $mail->addAttachment($attachment);         // Add attachments

    //Content
    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->Subject = $subject;
    $out="<html><body>$out</body></html>";
    $mail->Body    = $out;
    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->send();
    $scheduled_report->report_log("Sent '$subject' scheduled report to '$report_email'");

    // Report ran and sent, update lastrun
    $post_array['lastrun']=$now_epoch;
    $json=json_encode($post_array);
    Report::set_config($name,$json,'schedules',true);

} catch (Exception $e) {
    $scheduled_report->report_log("ERROR: ".$mail->ErrorInfo);
}
