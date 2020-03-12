<?php 
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$from_name 	= "osTicket Reports";
$from_mail      = $scheduled_report->get_default('admin_email','core');
$reply_to 	= $from_email;
$subject 	= $scheduled_report->get_human_rtype()." - ".$scheduled_report->get_human_range();
$path           = ROOT_DIR.'scp/'.$scheduled_report->get_default('output_directory');
$filename 	= 'report.pdf';
$attachment	= $path.'/'.$filename;
$scheduled_report->report_log("Sending attachment $attachment");

$mail = new PHPMailer(true);                              // Passing `true` enables exceptions
try {

    //Recipients
    $mail->setFrom($from_mail, 'osTicket Reports');
    $mail->addAddress('scott.m.rowley@gmail.com', 'Scott Rowley');     // Add a recipient

    //Attachments
    $mail->addAttachment($attachment);         // Add attachments

    //Content
    $mail->isHTML(true);                                  // Set email format to HTML
    $mail->Subject = $subject;
    $mail->Body    = 'This is the HTML message body <b>in bold!</b>';
    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->send();
    file_put_contents($path.'/report.log',"Message Sent!".PHP_EOL,FILE_APPEND);
} catch (Exception $e) {
    file_put_contents($path.'/report.log',"Message Failed!: ".$mail->ErrorInfo.PHP_EOL,FILE_APPEND);
}
