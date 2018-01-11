<?php
require_once __DIR__ .'/init.php';

define('IMAP_USER','editorial@epgs.com');
define('IMAP_PASS','epgsystems');

// We'll load all IMAP channels into an array we can loop on for each new email
$channels = Channel::getImapChannels();

// Loads email inbox
$imap = Imap::getInstance("{imap.gmail.com:993/imap/ssl}", IMAP_USER, IMAP_PASS);
$mails = $imap->search("SINCE 01-Jan-2012");
//print_r($mails);echo "<pre>";

if(is_array($mails)) {
	//Fetch All channel filters together to save on sql queries for each channel seperately
	$arr_all_channels_filters = Channel::getAllChannelsFilters($channels);
	foreach ($mails as $uid) {
		$email=$imap->getMail($uid);
		$date=date("Y-m-d H:i",$email->getDate());
		$from = $email->getFrom();
		// set dummy sender name if from email id is missing in email address - Fabian
		if(empty($from)){
			$from = "<no-sender@epgsales.com>";
		}



		$to = $email->getTo();
		$to = array_merge($to, $email->getCc());
		$to_arr = array();
		$direct_channels = array();
		foreach($to as $person) {
			$person_email = (string)$person->getEmail();
			if(strpos($person_email,'epgsystems.com')!==false || strpos($person_email,'epgs.com')!==false) {
				$to = $person;
				$to_arr[] = trim($person_email);
			}
			if(preg_match('#editorial\+(\d+)@epgs.com#ims', $person_email, $matched)){
				$direct_channels[] = $matched[1];
			}
		}

		if(!empty($to_arr)){
			$to_values = implode(", ", $to_arr);
		}
		else{
			$to_values = "Unknown";
		}

		$subject = $email->getSubject();
		$filter="";
		if($to instanceof IMAPAddress) {
			if(strpos($to->getMailbox(), "+")!==false) {
				$tmp=explode("+", $to->getMailbox());
				//print_r($tmp);
				$filter=$tmp[1];
			}
		}else {
			$filter="";
		}
		$content=$email->getPlain();
		$html=$email->getHTML();
		$att=$email->hasAttachments();
		$emailOBJ = Email::newEmail($date, $from, $to_values, $subject, $filter, $content, $html,$att, 0);

			// store the received files such that we can see what we receive.

		if($email->hasAttachments()) {
			$S3Client = EmailAttachment::getAWSClient();
			$save_dir = sprintf("s3://%s/%d/", __AWS_BUCKET_NAME, $emailOBJ->getId());;
			if(!file_exists($save_dir)) {
				mkdir($save_dir, 0777, true);
				@chmod($save_dir, 0777);
			}
			$count=1;

			foreach($email->getAttachments() as $file) {
				if(strtoupper($file['disposition'])=="ATTACHMENT") {
					//echo $file['disposition']."\n";
					$filename=isset($file['name'])?$file['name']:$file['id'];
					if(empty($filename)) {
						$filename=$count;
					}
					file_put_contents($save_dir . DIRECTORY_SEPARATOR . $filename, $file['data']);
					@chmod($save_dir. DIRECTORY_SEPARATOR .$filename, 0777);
					$emailOBJ->addAttachment($filename, $save_dir . DIRECTORY_SEPARATOR . $filename, 0);
					$count++;
				}
				elseif(strtoupper($file['disposition'])=="INLINE"){
					$filename = isset($file['name']) ? $file['name'] : $file['id'];
					if(empty($filename)) {
						$filename=$count;
					}
					file_put_contents($save_dir . DIRECTORY_SEPARATOR . $filename, $file['data']);
					@chmod($save_dir . DIRECTORY_SEPARATOR . $filename, 0777);
					$emailOBJ->addAttachment($filename, $save_dir . DIRECTORY_SEPARATOR . $filename, 0);
					$count++;
				}
			}
		}

		// Applying channel filters on email to find channels the email is sent to
		foreach($channels as $channel) {
			$runner = $channel->getAllFilterRunner($arr_all_channels_filters);
			if(in_array($channel->getId(), $direct_channels) || $res=$runner->runFilter($emailOBJ)) {
					// Logs this match
				$emailOBJ->logChannelMatch($channel->getId());

				// Puts the parsing in queue for parsing
				$sth = Db::getInstance()->prepare("INSERT INTO `channels_reparse_queue` SET
					`time_queued`=NOW(),
					`user_id`=-1,
					`channel_id`=:channel_id,
					`email_id`=:email_id
				");
				$sth->bindValue('channel_id',$channel->getId());
				$sth->bindValue('email_id',$emailOBJ->getId());
				$sth->execute();
			}
		}
		$imap->moveMail($uid, "parsed");
	}
}

echo "\n\n###\nImapFetch.php done\n";
