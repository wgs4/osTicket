<?php 
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$report_email = $_POST['email'];
$from_name 	= "osTicket Reports";
$from_email      = $report->get_default('admin_email','core');
$reply_to 	= $from_email;
$subject 	= $report->get_human_rtype()." - ".$report->get_human_range();
$path           = ROOT_DIR.'scp/'.$report->get_default('output_directory');
$csv_filename 	= 'report.csv';
$csv_attachment	= $path.'/'.$csv_filename;
$pdf_filename 	= 'report.pdf';
$pdf_attachment	= $path.'/'.$pdf_filename;
// $report->report_log("Sending attachment $pdf_attachment");

$mail = new PHPMailer(true);                              // Passing `true` enables exceptions
try {

    //Recipients
    $mail->setFrom($from_email, 'osTicket Reports');

    // Add Recipients
    $addresses = explode(",",$report_email);
    foreach($addresses as $address)
         $mail->addAddress($address);

    //Attachments
    if(isset($_POST['generate_pdf']))
    $mail->addAttachment($pdf_attachment); 
    if(isset($_POST['generate_csv']))
    $mail->addAttachment($csv_attachment);        

    //Content
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $email_html;
    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

    $mail->send();
    $report->report_log("Sent '$subject' manually run report to '$report_email'");
} catch (Exception $e) {
    $report->report_log("ERROR: ".$mail->ErrorInfo);
}
