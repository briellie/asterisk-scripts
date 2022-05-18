#!/usr/bin/php7.4
<?php

/*

Asterisk Voicemail Transcribe Wrapper
Version: 0.6.1
Date: 5/18/2022
License:  This work is licensed under CC BY-SA 4.0
URL: https://git.sosdg.org/brielle/asterisk-scripts
Requires:  PHP 5 or 7, Mail and Mail_Mime libraries from Pear
Tested With: PHP 7.4
Written by:  Brie Bruns <bruns@2mbit.com>

Uses IBM's Watson Speech to Text API interface.  Not pretty with
minimal error checking, but it works.  Feel free to send me patches
to improve.


*/


$apiKey="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx";
$apiURL="https://api.us-south.speech-to-text.watson.cloud.ibm.com/instances/xxxxxxxxxxxxxxxxxxxxxxxxx";
$apiURLRecognize="/v1/recognize?model=en-US_Telephony";

$emailRaw = stream_get_contents(STDIN);


require_once ("Mail.php");
require_once ("Mail/mime.php");

$mail = mailparse_msg_create();
mailparse_msg_parse($mail, $emailRaw);

$mailData=mailparse_msg_get_part_data($mail);
$textPart = mailparse_msg_get_part($mail, "1.1");
$mimePart = mailparse_msg_get_part($mail, "1.2");
$mimePartHeader = mailparse_msg_get_part_data($mimePart); 

$wavFile = mailparse_msg_extract_part($mimePart, $emailRaw, null);
$textMsg = mailparse_msg_extract_part($textPart, $emailRaw, null);

$textMsg .= "\n\nSpeech To Text (May be inaccurate!):\n\n";

if (isset($wavFile)) {
    $submitWatsonSTT=curl_init();
    curl_setopt_array($submitWatsonSTT, array(
        CURLOPT_CONNECTTIMEOUT => '15',
        CURLOPT_TIMEOUT => '120',
        CURLOPT_URL => $apiURL.$apiURLRecognize,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_USERPWD => 'apikey:'.$apiKey,
        CURLOPT_HTTPHEADER => array( 'Content-Type: audio/wav' ),
        CURLOPT_POSTFIELDS => $wavFile
        )
    );
    $curlReturnResults=curl_exec($submitWatsonSTT);

    $transcriptResults = json_decode($curlReturnResults, true);

    if (isset($transcriptResults['results'][0]['alternatives'][0]['transcript'])) {
        foreach($transcriptResults['results'] as $key => $value) {
            $textMsg .= $transcriptResults['results'][$key]['alternatives'][0]['transcript']." ... ";
        }
        $textMsg .="\n\n";
    }
}

$headers = array(
    "Date"                  => $mailData['headers']['date'],
    "From"                  => $mailData['headers']['from'],
    "To"                    => $mailData['headers']['to'],
    "Subject"               => $mailData['headers']['subject'],
    "Message-ID"            => $mailData['headers']['message-id'],
    "X-Asterisk-CallerID"   => $mailData['headers']['x-asterisk-callerid'],
    "X-Asterisk-CallerIDName"   =>      $mailData['headers']['x-asterisk-calleridname'],
);

if (isset($mailData['headers']['cc'])) {
    $headers['Cc'] = $mailData['headers']['cc'];
}

if (isset($mailData['headers']['bcc'])) {
    $headers['Bcc'] = $mailData['headers']['bcc'];
}

if (isset($mailData['headers']['reply-to'])) {
    $headers['Reply-To'] = $mailData['headers']['reply-to'];
}

if (isset($mailData['headers']['user-agent'])) {
    $headers['User-Agent'] = $mailData['headers']['user-agent'];
}

if (isset($mailData['headers']['content-language'])) {
    $headers['Content-Language'] = $mailData['headers']['content-language'];
}

$sendMimeMail = new Mail_mime();
$sendMimeMail->setTXTBody($textMsg);
$sendMimeMail->addAttachment(
    $wavFile,
    $mimePartHeader['content-type'],
    $mimePartHeader['content-name'],
    false,
    'base64',
    'attachment',
    null,
    null,
    null,
    null,
    null,
    $mimePartHeader['content-description'],
    null
);
$mailBody = $sendMimeMail->get();
$mailHeaders = $sendMimeMail->headers($headers);
$sendMail = Mail::factory("sendmail");
$sendMail->send($mailData['headers']['to'], $mailHeaders, $mailBody);


mailparse_msg_free($mail);


?>