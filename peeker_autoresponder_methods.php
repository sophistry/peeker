<?php

// a basic set of methods that analyze the email object
// and return boolean values for detectors
// to implement an email autoresponder

class peeker_autoresponder_methods
{
	
	// create the link between this class and the base layer
	// the class var $that has to be present in every
	// set of methods used to layer and the register 
	// function needs to be there too
	// the $that parameter is the email object
	protected $that = NULL;

    public function register($that)
    {
        $this->that = $that;
    }
    
    
	// ------- detectors - return boolean ------- //
	
	/*
	* is Return-Path header a bounce address <> ?
	* also, probably need to expand this to deal 
	* with other variations of bounced messages
	*
	*/
	public function is_bounce()
	{
		// Return-Path header has several issues
		// 1) sometimes it is not included in the main properties of the header class
		// 2) there are sometimes 2 Return-Path headers! google seems to like to add one
		// assumes the header is always init capped like it's supposed to be
		//$return_path_string = $this->that->get_header_item('Return-Path');
		// returns the string
		$return_path_string = $this->that->get_return_path();
		//p($this->that);
		//p($return_path_string);
		$bounce = ($return_path_string) === '<>';
		return $bounce;
	
	}
	
	
	/**
	* return true if email has a from address
	* and the address is not the no-return <>
	*/
	public function valid_from_email_for_response()
	{
		$from_address_valid = 0;
		$return_path_address_valid = 0;
		$from_string = $this->that->get_from();
		//p($from_string);
		//p($return_path_string);
		// make sure it is a properly-formatted email address
		// and not a <> string indicating terminated bounce
		$return_path_address_valid = ($from_string !== '<>' AND !($this->is_bounce()) );
		
		return $return_path_address_valid;
	}

	// ---------- callbacks - do something ------------//
	// send an email response
	// determine which email to use if it
	// is not obvious
	public function send_from($send_from_address='')
	{
		$to = $this->that->get_header_item('Return-Path');
		p($to);
		p('About to send mail from: '.$send_from_address . ' to Return-Path: '. pvh($to));
		// actually send the email using basic mail() function
		$subject = 'Thank you - your message was received';
		$message = 'this is an automated reply sent to: '.$to;
		$headers = 'From: '.$send_from_address . "\r\n" .
		'Reply-To: '. $send_from_address . "\r\n" .
		'X-Mailer: Peeker AutoResponder';
		mail($to, $subject, $message, $headers);
	}

}
//EOF