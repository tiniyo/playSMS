<?php
defined('_SECURE_') or die('Forbidden');

// hook_sendsms
// called by main sms sender
// return true for success delivery
// $smsc : smsc
// $sms_sender : sender mobile number
// $sms_footer : sender sms footer or sms sender ID
// $sms_to : destination sms number
// $sms_msg : sms message tobe delivered
// $gpid : group phonebook id (optional)
// $uid : sender User ID
// $smslog_id : sms ID
function tiniyo_hook_sendsms($smsc, $sms_sender, $sms_footer, $sms_to, $sms_msg, $uid = '', $gpid = 0, $smslog_id = 0, $sms_type = 'text', $unicode = 0) {
	global $plugin_config;
	
	_log("enter smsc:" . $smsc . " smslog_id:" . $smslog_id . " uid:" . $uid . " to:" . $sms_to, 2, "tiniyo_hook_sendsms");
	
	// override plugin gateway configuration by smsc configuration
	$plugin_config = gateway_apply_smsc_config($smsc, $plugin_config);
	
	$sms_sender = stripslashes($sms_sender);
	if ($plugin_config['tiniyo']['module_sender']) {
		$sms_sender = $plugin_config['tiniyo']['module_sender'];
	}
	
	$sms_footer = stripslashes($sms_footer);
	$sms_msg = stripslashes($sms_msg);
	$ok = false;
	
	_log("sendsms start", 2, "tiniyo_hook_sendsms");
	
	if ($sms_footer) {
		$sms_msg = $sms_msg . $sms_footer;
	}
	
	if ($sms_sender && $sms_to && $sms_msg) {
		$url = $plugin_config['tiniyo']['url'] . '/v1/Account/' . $plugin_config['tiniyo']['account_sid'] . '/Message';
		$data = array(
			'dst' => $sms_to,
			'src' => $sms_sender,
			'text' => $sms_msg 
		);
		if (trim($plugin_config['tiniyo']['callback_url'])) {
			$data['url'] = trim($plugin_config['tiniyo']['callback_url']);
		}

		$data_string = json_encode($data);                                                                                   

		_log("sendsms json data :[" . $data_string . "]",2,"tiniyo_hook_sendsms");

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_USERPWD, $plugin_config['tiniyo']['account_sid'] . ':' . $plugin_config['tiniyo']['auth_token']);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);                                                                  
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(                                                                          
				'Content-Type: application/json',                                                                                
				'Content-Length: ' . strlen($data_string))                                                                       
			);
			$returns = curl_exec($ch);
			curl_close($ch);
			_log("sendsms url:[" . $url . "] callback:[" . $plugin_config['tiniyo']['callback_url'], "] smsc:[" . $smsc . "]", 2, "tiniyo_hook_sendsms");
			$resp = json_decode($returns);
			if ($resp->status) {
				$c_status = $resp->status;
				$c_message_id = $resp->msgid;
				$c_error_text = $c_status . '|' . $resp->code . '|' . $resp->message;
				_log("sent smslog_id:" . $smslog_id . " message_id:" . $c_message_id . " status:" . $c_status . " error:" . $c_error_text . " smsc:[" . $smsc . "]", 2, "tiniyo_hook_sendsms");
				$db_query = "
					INSERT INTO " . _DB_PREF_ . "_gatewayTiniyo (local_smslog_id,remote_smslog_id,status,error_text)
					VALUES ('$smslog_id','$c_message_id','$c_status','$c_error_text')";
				$id = @dba_insert_id($db_query);
				if ($id && ($c_status == 'success')) {
					$ok = true;
					$p_status = 0;
				} else {
					$p_status = 2;
				}
				dlr($smslog_id, $uid, $p_status);
			} else {
				// even when the response is not what we expected we still print it out for debug purposes
				$resp = str_replace("\n", " ", $resp);
				$resp = str_replace("\r", " ", $resp);
				_log("failed smslog_id:" . $smslog_id . " resp:" . $resp . " smsc:[" . $smsc . "]", 2, "tiniyo_hook_sendsms");
			}
		} else {
			_log("fail to sendsms due to missing PHP curl functions", 3, "tiniyo_hook_sendsms");
		}
	}
	if (!$ok) {
		$p_status = 2;
		dlr($smslog_id, $uid, $p_status);
	}
	
	_log("sendsms end", 3, "tiniyo_hook_sendsms");
	
	return $ok;
}
