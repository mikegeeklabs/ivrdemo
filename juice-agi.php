#!/usr/bin/php -q
<?php {
    // updated for Juice 4.x February 2015
    // WARNING: This is an Asterisk AGI not a web thing, much of this code is peculiar to AGI programming.
    // WARNING: 'php_beautifier' and some editors that should grok PHP do not grok this code. 
    // WARNING: It uses "goto" and some other decidedly bad ideas, on purpose. 
    // This is -almost- production code, it will need to be refined for an actual system / deployment
    // You will want to turn off the script creation log. 
    // Code by Mike Harrison
    GLOBAL $stdin, $stdout, $stdlog, $result, $parm_debug_on, $parm_test_mode, $db, $callerid, $portal, $rc, $account, $serialnumber, $language;
    $portal = 'unknown'; # HARD SET FOR THIS IVR SYSTEM - MUST FIND PORTAL FROM INFO - ACCOUNT NUMBERS MUST BE UNIQUE IN SYSTEM
    $language = 'en';
    $lang = 'en';
    include ("juice-agi.inc");
    ob_implicit_flush(false);
    set_time_limit(30);
    error_reporting(0);
    $stdin = fopen('php://stdin', 'r');
    $stdout = fopen('php://stdout', 'w');
    // ASTERISK * Sends in a bunch of variables, This accepts them and puts them in an array
    // There is an elegant way to do this and I forgot it. 
    while (!feof($stdin)) {
        $temp = fgets($stdin);
        // Strip off any new-line characters
        $temp = str_replace("\n", "", $temp);
        $s = explode(":", $temp);
        $agivar[$s[0]] = trim($s[1]);
        if (($temp == "") || ($temp == "\n")) {
            break;
        }
    }
    // There are two ways to contact a phone, by its channel or by its local
    // extension number.  This next session will extract both sides
    // split the Channel  SIP/11-3ef4  or Zap/4-1 into is components
    // I don't use these vars in this example code. 
    $channel = $agivar[agi_channel];
    if (preg_match('.^([a-zA-Z]+)/([0-9]+)([0-9a-zA-Z-]*).', $channel, $match)) {
        $sta = trim($match[2]);
        $chan = trim($match[1]);
    }
    // Go Split the Caller ID into its components
    $callerid = $agivar[agi_callerid];
    logit("CallerID: $callerid");
    // First look for the Extension in <####>
    $origcallerid = $callerid;
    $callerid = dt($callerid);
    logit("---Start---");
    $rc = execute_agi("ANSWER");
    sleep(1); // Wait for the channel to get created and RTP packets to be sent
    // On my system the welcome you would only hear 'elcome'  So I paused for 1 second
    #if they don't have caller ID, hang up.
    if ($callerid == '0') {
    };
    #----WELCOME
    sas('Welcome', 'welcometothetest');
    sleep(.1);
    #----MULTILANG
    if (in_array('MULTILANG', $logic)) {
        $chosen = sagi('For English, press 1, Para Espanol, Marca Dos', 'chooselang', '1', '3', true);
        if ($chosen == 2) {
            $language = 'es';
            $lang = 'es';
        } else {
            $language = 'en';
            $lang = 'en';
        };
        sleep(.1);
    };
    #----MOTD
        $d = date("Ymd");

    if (strlen($motd) > 4) {
        sas("$motd", "1motd$d");
        sleep(.1);
    };

#YES THIS IS THE USE OF GOTO's in PHP. IT WORKS VERY WELL FOR AGI/IVR PROGRAMMING

#----FIND ACCOUNT
findaccount:
    $account = '';
    while ($account == '') {
        $possaccount = sagi('Enter your account or meter number, press pound when finished', 'accountormeter', '11', '3', false);
        logit("possaccount: $possaccount");
        $reply = getjuicejson("mode=balance&serialnumber=$possaccount&account=$possaccount");
        logit(print_r($reply, 1));
        if (empty($reply->account)) {
            sas('Account not found', 'accountnotfound');
            $account = '';
            $name = '';
            $address1 = '';
        } else {
            $account = $reply->account;
            $name = $reply->name;
            $address1 = $reply->address1;
#            sas("<prosody rate='slow'>Is your billing address </prosody> <prosody pitch='+10%'>$address1</prosody>", "accountloc3$account$d");
            sas("Is your billing address? $address1 ", "accountloc$account$d");
        };
    };
    $chosen = sagi('if correct press 1, if wrong press 2', 'iscorrect', '1', '2', true);
    if ($chosen != 1) {
        goto findaccount;
    };
    sleep(.1);


#----ACCOUNT INFO
accountinfo:
    #     sas("$name","accountname$account") ;
    $owed = 0;
    $balance = 0;
    if ($reply->arrearsbalance < 0) {
        sas("You currently owe", 'youcurrentlyowe');
        $owed = -$reply->arrearsbalance;
        $rc = execute_agi("SAY number $owed \"\" ");
        sas("$currency", 'currency');
    }
    if ($reply->balance > 0) {
        sas("you have a balance of", 'yourcreditbalance') ;
        $balance = $reply->balance;
        $rc = execute_agi("SAY number $balance \"\" ");
        sas("$currency", 'currency');
    }

#-----
paymentoptions:
    $chosen = '';
    $chosen = sagi('Please select one of the following payment options', 'paymentoptions2', '1', '1', true);
    if (empty($chosen) and in_array('JC', $logic)) {
        $chosen = sagi('for scratch card, press 1', 'forjc2', '1', '1', true);
    };
    if (empty($chosen) and in_array('CC', $logic)) {
        $chosen = sagi('for credit card, press 2', 'forcc2', '1', '1', true);
    };
#    if (empty($chosen) and in_array('ACH', $logic)) {
#        $chosen = sagi('bank account, press 3', 'forach', '1', '1', true);
#    };
#    if (empty($chosen) and in_array('GIMME5', $logic) and $owed <= 0.01) {
#        $chosen = sagi('For a 5 kilowatt advance (gimme 5), press 5', 'forgimme', '1', '1', true);
#    };
    logit("chosen: $chosen");
    if (empty($chosen) or $chosen > 5) {
        goto paymentoptions ;
    };
    if ($chosen == 0) {
        goto accountinfo ;
    };
    #        if($chosen == 1) { goto payscratchcard ; } ;
    if ($chosen == 1) {
        goto payjuicecard ;
    };
    if ($chosen == 2) {
        goto paycreditcard ;
    };
    #        if($chosen == 3) { goto paybankaccount ; } ;
    #        if($chosen == 4) { goto paymentoptions ; } ;
    if ($chosen == 5) {
        goto gimme5 ;
    } ;

    if(empty($chosen) or $chosen < 1 or $chosen > 5) { #should be redundant.. but...
        goto paymentoptions ; 
    } ; 
    
    

#------ Catch all for under dev. Should also never just fall into this. 
underdev:
            sas("This function is currently unavailable", 'currentunavail');
            goto accountinfo;

#------ GIMME 5 PROGRAM
gimme5:
        if ($owed > 0.01) {
            sas("we can not advance you more", 'wecannotadvance');
            goto accountinfo;
        };
        if ($owed < 0.01) {
            sas("Function Under development", 'dev');
            goto accountinfo;
        };
#------ PAY CREDIT CARD
paycreditcard:
        $amount = chooseamount($owed);
        if ($amount < 1) {
            goto accountinfo;
        };
        sas('You entered', 'youentered');
        $rc = execute_agi("SAY number $amount \"\" ");
        sas("$currency", 'currency');
        sleep(.2);
        $chosen = sagi('if correct, press 1', 'ifcorrectpress1', '1', '2', true);
        if ($chosen != '1') {
            goto paycreditcard;
        };
    ccnum:
        $ccnum = sagi('Enter your 16 digit credit card number', 'enter16cc', '16', '3', true);
        sleep(.2);
        if (!ccluhn($ccnum)) {
            sas('Invalid credit card number', 'invalidccnum');
            #goto paymentoptions;
            goto ccnum ; 
        };
        sas('Thank You', 'thankyou');
        sleep(.2);
    ccexpmth:
        $ccexpmth = sagi('Enter 2 digit expiration month', 'enterexpmth', '2', '3', true);
        $ccexpmth = str_pad($ccexpmth, 2, "0", STR_PAD_LEFT);
        if($ccexpmth < 1 or $ccexpmth > 12) { goto ccexpmth ; } ; 
        sleep(.1);
        sas('Thank You', 'thankyou');
        sleep(.2);
    ccexpyr:
        $ccexpyr = sagi('Enter 2 digit expiration year', 'enterexpyr', '2', '3', true);
        $y = date("y") ; 
        if($ccexpyr < $y  or $ccexpyr > $y + 20) { goto ccexpyr ; } ; 
        sleep(.10);
        sas('Thank You', 'thankyou');
        sleep(.10);
        $cccvv = sagi('Enter your 3 digit number on the back of the card', 'enterccv', '2', '3', true);
        sleep(.10);
        $cccvv = str_pad($cccvv, 3, "0", STR_PAD_LEFT);
        sas('Thank You', 'thankyou');
        sleep(.2);
        sas("You are about to use the credit card ending in", 'abouttousecc');
        $last4 = substr($ccnum, -4, 4);
        $rc = execute_agi("SAY digits $last4 \"\" ");
        sas("to pay", 'topay');
        $rc = execute_agi("SAY number $amount \"\" ");
        sas("$currency", 'currency');
        $chosen = sagi('if correct press 1, if wrong press 2', 'iscorrect', '1', '2', true);
        if ($chosen != 1) {
            goto accountinfo;
        };
        #now we attempt to make a payment
        logit("ccpay: $possaccount $account $amount");
        $reply = getjuicejson("mode=CC&account=$possaccount&amount=$amount&ccnum=$ccnum&ccexpmth=$ccexpmth&ccexpyr=$ccexpyr&cccvv=$cccvv");
        logit(print_r($reply, 1));
        if ($reply->status == 'ERROR') {
            $rc = execute_agi("STREAM FILE descending-2tone \"\" ");
            sas('ERROR', 'error');
            sas($reply->message, '');
            goto accountinfo;
        };
        if ($reply->status == 'CCAPPROVED') {
            $rc = execute_agi("STREAM FILE ascending-2tone \"\" ");
            sas('APPROVED for', 'approvedfor');
            $rc = execute_agi("SAY number $reply->amount \"\" ");
            sas("$currency", 'currency');
            sleep(.2);
            $reply = getjuicejson("mode=balance&account=$possaccount");
            logit(print_r($reply, 1));
            sas('Thank You', 'thankyou');
            if (!empty($reply->ststoken)) {
                sayststoken($reply->ststoken);
            };
            sleep(.25);
            goto accountinfo;
        };

    goto accountinfo; #just in case something falls through. 

#------ PAY JUICE/SCRATCH CARD
payjuicecard:
        $jcnum = sagi('Enter your 16 digit juice card number', 'enter16jc', '16', '3', true);
        if(strlen($jcnum) != 16) { goto payjuicecard ; } ; 
        sleep(.10);
        sas('Thank You', 'thankyou');
        #now we check the juice card
        logit("ccpay: $possaccount $account $amount");
        $reply = getjuicejson("mode=vendjc&account=$possaccount&juicecard=$jcnum");
        logit(print_r($reply, 1));
        if ($reply->status == 'ERROR') {
            $rc = execute_agi("STREAM FILE descending-2tone \"\" ");
            sas('ERROR', 'error');
            sas($reply->message, '');
            goto accountinfo;
        };

        if ($reply->status == 'OK' and $reply->amount > 0 ) {
            $rc = execute_agi("STREAM FILE ascending-2tone \"\" ");
            sas('Redeemed for', 'redeemedfor');
            $rc = execute_agi("SAY number $reply->amount \"\" ");
            sas("$currency", 'currency');
            sleep(.2);
            $reply = getjuicejson("mode=balance&account=$possaccount");
            logit(print_r($reply, 1));
            sas('Thank You', 'thankyou');
            if (!empty($reply->ststoken)) {
                sayststoken($reply->ststoken);
            };
            sleep(.25);
            goto accountinfo;
        } else { 
            $rc = execute_agi("STREAM FILE descending-2tone \"\" ");
            sas('That card was not valid, please try again', 'invalidjuicecard');
            $reply = getjuicejson("mode=balance&account=$possaccount");
            goto accountinfo;
        } ; 

    goto accountinfo; #just in case something falls through. 

    }; #MAIN PROGRAM END

#THIS IS THE END OF THE GOTO FLOW. 

    function chooseamount($owed) {
        if ($owed > 0) {
            sas("To pay the amount owed of", 'theamountowedof');
            $rc = execute_agi("SAY number $owed \"\" ");
            $chosen = sagi('Press 1 followed by the pound key.  Or enter any amount followed by the pound key.', 'choose1oramount', '5', '3', true);
            if ($chosen == 1) {
                $chosen = $owed;
            };
        } else {
            $chosen = sagi('Please enter an amount followed by the pound key', 'chooseanamount', '4', '3', true);
        };
        return $chosen;
    };
    function hangup() {
        logit('Hang Up');
        sas('Good Bye', 'goodbye');
        $rc = execute_agi("HANGUP");
        sleep(.5); #Sometimes Asterisk takes a bit to do this, do not exit until it does. 
        exit;
    };
    function logit($whattolog) {
        GLOBAL $stdlog, $callerid, $language;
        $stdlog = fopen("/usr/share/asterisk/agi-bin/ivr.log", 'a');
        $d = date(DATE_RFC822);
        fputs($stdlog, "$d | $callerid | $whattolog\n");
        fclose($stdlog);
    };
    function script($whattolog,$file) {
        GLOBAL $stdlog, $callerid, $language;
        $stdlog = fopen("/usr/share/asterisk/agi-bin/script.log", 'a');
        fputs($stdlog, sprintf("%-80s",$whattolog) . '>' . "$file\n") ;
        fclose($stdlog);
    };
    function execute_agi($command) {
        GLOBAL $stdin, $stdout, $stdlog, $parm_debug_on, $language;
        fputs($stdout, $command . "\n");
        fflush($stdout);
        if ($parm_debug_on) fputs($stdlog, $command . "\n");
        $resp = fgets($stdin, 4096);
        if ($parm_debug_on) fputs($stdlog, $resp);
        if (preg_match("/^([0-9]{1,3}) (.*)/", $resp, $matches)) {
            if (preg_match('/result=([-0-9a-zA-Z]*)(.*)/', $matches[2], $match)) {
                $arr['code'] = $matches[1];
                $arr['result'] = $match[1];
                if (isset($match[3]) && $match[3]) $arr['data'] = $match[3];
                return $arr;
            } else {
                if ($parm_debug_on) fputs($stdlog, "Couldn't figure out returned string, Returning code=$matches[1] result=0\n");
                $arr['code'] = $matches[1];
                $arr['result'] = 0;
                return $arr;
            }
        } else {
            if ($parm_debug_on) fputs($stdlog, "Could not process string, Returning -1\n");
            $arr['code'] = -1;
            $arr['result'] = -1;
            return $arr;
        }
    }
    function dt($string) {
        global $language;
        if (empty($string)) {
            return '';
        } else {
            $string = trim($string);
            $strip = array('/^ /', '/\s+$/', '/\$/', '/\n/', '/\r/', '/\n/', '/\,/', '/\:/', '/\@/', '/\%/', '/0x/');
            $string = preg_replace($strip, '', $string);
            $strip = array('/\'/');
            $string = preg_replace($strip, '&amp;', $string);
            $string = mysql_escape_string($string);
            return $string;
        };
    };
    function dtemail($string) {
        global $language;
        if (empty($string)) {
            return '';
        } else {
            $string = trim($string);
            $strip = array('/^ /', '/\s+$/', '/\$/', '/\n/', '/\r/', '/\n/', '/\,/', '/\:/', '/\%/', '/0x/');
            $string = preg_replace($strip, '', $string);
            $strip = array('/\'/');
            $string = preg_replace($strip, '&amp;', $string);
            $string = mysql_escape_string($string);
            return $string;
        };
    };
    function dtless($string) {
        global $language;
        if (empty($string)) {
            return '';
        } else {
            $string = trim($string);
            $strip = array('/\'/');
            $string = preg_replace($strip, '&amp;', $string);
            $string = mysql_escape_string($string);
            return $string;
        };
    };
    function dtamt($string) {
        global $language;
        $string = trim($string);
        if ($language == 'pt' or $language == 'fr') {
            $string = preg_replace("/[.]/", "", $string);
            $string = preg_replace("/[,]/", ".", $string);
        }
        $string = preg_replace("/[^0-9.]/", "", $string);
        return $string;
    };
    function dtnum($string) {
        global $language;
        $string = preg_replace('/\D/', '', $string);
        return $string;
    };
    function sas($whattosay, $file) {
        GLOBAL $stdin, $stdout, $stdlog, $parm_debug_on, $result, $db, $callerid, $portal, $voice, $rc, $language;
        #Speak and say = SAS = If the file does not exist, create it.
        $voice = 'Allison-8kHz';
        //SCRIPT CREATION
        script("$whattosay","human-juice-$language-$file.wav") ; 
        if (@fopen("/usr/share/asterisk/sounds/en/human-juice-$language-$file.wav", "r")) {
            $rc = execute_agi("STREAM FILE human-juice-$language-$file \"\" ");
            logit("human-juice-$language-$file.wav streamed");
        } else {
            if (@fopen("/usr/share/asterisk/sounds/en/juice-$language-$file.wav", "r")) {
                logit("juice-$language-$file.wav exists");
            } else {
                system("/usr/local/bin/swift -n $voice \"$whattosay\" -o /usr/share/asterisk/sounds/en/juice-$language-$file.wav");
                logit("juice-$language-$file.wav created");
            };
            $rc = execute_agi("STREAM FILE juice-$language-$file \"\" ");
            logit("juice-$language-$file.wav streamed");
        };
    };
    function sag($whattosay, $file, $choices, $looplimit) {
        GLOBAL $stdin, $stdout, $stdlog, $parm_debug_on, $result, $db, $callerid, $portal, $voice, $rc, $language;
        #Speak and say = SAS = If the file does not exist, create it.
        $voice = 'Allison-8kHz';
        $loop = 0;
        //SCRIPT CREATION
        script("$whattosay","human-juice-$language-$file.wav") ; 
        while ($loop < $looplimit) {
            if (@fopen("/usr/share/asterisk/sounds/en/human-juice-$language-$file.wav", "r")) {
                $rc = execute_agi("STREAM FILE human-juice-$language-$file \"$choices\" ");
                logit("human-juice-$language-$file.wav streamed");
            } else {
                if (@fopen("/usr/share/asterisk/sounds/en/juice-$language-$file.wav", "r")) {
                    logit("juice-$language-$file.wav exists");
                } else {
                    system("/usr/local/bin/swift -n $voice \"$whattosay\" -o /usr/share/asterisk/sounds/en/juice-$language-$file.wav");
                    logit("juice-$language-$file.wav created");
                };
                $rc = execute_agi("STREAM FILE juice-$language-$file \"$choices\" ");
                logit("juice-$language-$file.wav streamed");
            };
            if ($rc[result] > 0) {
                if ($rc[result] >= 48 and $rc[result] < 59) {
                    $chosen = $rc[result]-48;
                    return $chosen;
                } else {
                    sas("Invalid option", "invalidoption");
                    return 0;
                };
            };
            sleep(1);
            $loop++;
        };
        sas("No user selection", "noselection");
        hangup();
    };
    function sagi($whattosay, $file, $chars, $looplimit, $enforce) {
        GLOBAL $stdin, $stdout, $stdlog, $parm_debug_on, $result, $db, $callerid, $portal, $voice, $rc, $language;
        #Speak and say = SAS = If the file does not exist, create it.
        $voice = 'Allison-8kHz';
        $loop = 0;
        $wait = 1200*$chars;
        if ($wait < 2000) {
            $wait = 2000;
        };
        //SCRIPT CREATION
        script("$whattosay","human-juice-$language-$file.wav") ; 
        while ($loop < $looplimit) {
          ##sagiagain: 
            if (@fopen("/usr/share/asterisk/sounds/en/human-juice-$language-$file.wav", "r")) {
                $rc = execute_agi("GET DATA human-juice-$language-$file $wait $chars");
                logit("human-juice-$language-$file.wav streamed");
            } else {
                if (@fopen("/usr/share/asterisk/sounds/en/juice-$language-$file.wav", "r")) {
                    logit("juice-$language-$file.wav exists");
                } else {
                    system("/usr/local/bin/swift -n $voice \"$whattosay\" -o /usr/share/asterisk/sounds/en/juice-$language-$file.wav");
                    logit("juice-$language-$file.wav created");
                };
                $rc = execute_agi("GET DATA juice-$language-$file $wait $chars");
                logit("juice-$language-$file.wav streamed");
            };
            if (!empty($rc[result]) and $rc[result] > -1) {
                $entered = dt($rc[result]);
                return $entered;
            };
            sleep(.1);
            $loop++;
          #  if(($enforce) and strlen(dt($rc[result])) < $chars) { goto sagiagain ; } ; 
        };
        
        if ($loop > 1) {
            sas("No user selection", "noselection");
            ##  hangup() ;
            
        };
    };
    function stsluhn($input) {
        #slight mods to check for STS meter serial number validity
        $sum = 0;
        $odd = strlen($input) %2;
        if (!is_numeric($input)) {
            return false;
        }
        for ($i = 0;$i < strlen($input);$i++) {
            $sum+= $odd ? $input[$i] : (($input[$i]*2 > 9) ? $input[$i]*2-9 : $input[$i]*2);
            $odd = !$odd;
        }
        return ($sum%10 == 0) ? true : false;
    }
    function ccluhn($input) {
        #Checking for valid CC Numbers.
        $sum = 0;
        $odd = strlen($input) %2;
        if (!is_numeric($input)) {
            return false;
        }
        for ($i = 0;$i < strlen($input);$i++) {
            $sum+= $odd ? $input[$i] : (($input[$i]*2 > 9) ? $input[$i]*2-9 : $input[$i]*2);
            $odd = !$odd;
        }
        $allowed = array('4', '5'); #visa 4 and mastercard 5 - This logic may need to be customer specific
        if (in_array(substr($input, 0, 1), $allowed) and strlen($input) == 16) {
            return ($sum%10 == 0) ? true : false;
        } else {
            return false;
        };
    }
    #True test
    #echo (luhn("07026904305")) ? "true" : "false";
    #echo (ccluhn("4111111111111111")) ? "true" : "false";
    #echo (ccluhn("1111111111111117")) ? "true" : "false";
    #echo "\n" ;

    function sayststoken($token) {
        GLOBAL $stdin, $stdout, $stdlog, $parm_debug_on, $result, $db, $callerid, $portal, $voice, $rc, $language;
        $token = dtnum($token);
        $tl = 1;
        while ($tl < 4) {
            sas('your token is', 'yourtokenis');
            sleep(1);
            $t = substr($token, 0, 4);
            $rc = execute_agi("SAY digits $t \"\" ");
            sleep(1);
            $t = substr($token, 4, 4);
            $rc = execute_agi("SAY digits $t \"\" ");
            sleep(1);
            $t = substr($token, 8, 4);
            $rc = execute_agi("SAY digits $t \"\" ");
            sleep(1);
            $t = substr($token, 12, 4);
            $rc = execute_agi("SAY digits $t \"\" ");
            sleep(1);
            $t = substr($token, 16, 4);
            $rc = execute_agi("SAY digits $t \"\" ");
            sleep(1);
            $tl++;
        };
    };
    function getjuicejson($getstring) {
        #This is what does the work, calls Juice API, returns JSON formatted data.
        global $lang;
        include ("juice-agi.inc");
        $ch = curl_init();
        $lang = 'en';
        $curlstring = "$getstring&return=json&lang=$lang";
        logit("$apijclogin:$apijcpassword $apiurl$curlstring");
        curl_setopt($ch, CURLOPT_URL, $apiurl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); #Set to 0 to use self-signed certs.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); #Set to 0 to use self-signed certs with IP's. 
        curl_setopt($ch, CURLOPT_POSTFIELDS, "$curlstring");
        curl_setopt($ch, CURLOPT_HTTPAUTH, "CURLAUTH_BASIC"); #  Juice requires basic auth
        curl_setopt($ch, CURLOPT_USERPWD, "$apijclogin:$apijcpassword"); # The JC user login and password
        $response = curl_exec($ch);
        logit(print_r($response, 1));
        if (curl_errno($ch)) {
            logit("getjuicejson: ERROR (1): $curlstring " . curl_error($ch) . print_r($response, 1));
            sas("An error has occured, please contact customer service", 'baderror');
            hangup();
            curl_close($ch);
        } else {
            curl_close($ch);
        }
        $reply = json_decode($response);
        if ($reply == '') {
            logit("getjuicejson: ERROR (2): " . curl_error($ch));
            sas("An error has occured, please contact customer service", 'baderror');
            hangup();
            die;
        };
        return $reply;
    };
?>
