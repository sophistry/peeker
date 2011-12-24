<?php

/**
*
* An Application class
* has detectors and callbacks
* for implementing a resender/listserv
*
*/
class peeker_listserv_methods
{
	public $list_address;
	public $resend_to_array = array(); // simple email address array
	public $resend_cc_array = array(); // for using in the headers to show who else was CC'd
	public $approved_array = array(); // these email addresses are approved
	
	// default NULL is required here 
	// because the code stores this
	// in case there are multiple calls
	// to the approved_sender check
	public $approved_sender = NULL;
	
	// create the link between this 
	// class and the base layer
	// the class var $that has to be present in every
	// set of methods used to layer
	// and the register function needs to be
	// there too
	protected $that = null;

    public function register($that)
    {
        $this->that = $that;
    }
    	
	//---------- access ----------//
	
	/**
	* set the email address for this list resender
	*
	*/
	public function set_list_address($add)
	{
		$this->list_address = $add;
		return TRUE;
	}
	
	/**
	* return the list address
	*
	*/
	public function get_list_address()
	{
		return $this->list_address;
	}
	
	/**
	* set the array
	*
	*/
	public function set_resend_to($arr)
	{
		$this->resend_to_array = $arr;
		return TRUE;
	}
	
	/**
	* return the array of to addresses
	* this class will resend to
	*
	*/
	public function get_resend_to()
	{
		return $this->resend_to_array;
	}
	
	/**
	* set the array
	*
	*/
	public function set_resend_cc($arr)
	{
		$this->resend_cc_array = $arr;
		return TRUE;
	}
	
	/**
	* set the cc array to the same as 
	* the one in the message object
	*
	*/
	public function carryover_cc()
	{
		//p($this->cc);
		$cc_array = $this->that->get_cc_array();
		$this->set_resend_cc($cc_array);
		$this->log_state('CC carryover: '. var_export($cc_array,TRUE));

		return TRUE;
	}
	
	/**
	* return the array of cc addresses
	* this class will resend to
	*
	*/
	public function get_resend_cc()
	{
		return $this->resend_cc_array;
	}
	
	/**
	* append an address to the resent_to_array
	*
	*/
	public function append_to_resend_to($address)
	{
		$this->resend_to_array[] = $address;
	}
	
	
	/*
	* remove an address from the resend_to_array
	*
	*/
	public function remove_from_resend_to($address)
	{
		$stripped_resend_to_array = array();
		$rsta = $this->get_resend_to();
		foreach($rsta as $email)
		{
			if ($address != $email) 
			{
				$stripped_resend_to_array[] = $email;
			}
			else
			{
				$this->log_state('Resender class. Stripped address from resend_to_array: '.$email);
			}
		}
		$this->set_resend_to($stripped_resend_to_array);
	}
	
	/**
	* set the array
	*
	*/
	public function set_approved($arr)
	{
		$this->approved_array = $arr;
		return TRUE;
	}
	
	/**
	* return the array of to addresses
	* this class will resend to
	*
	*/
	public function get_approved()
	{
		return $this->approved_array;
	}
	
	/**
	* append an address to the approved_array
	*
	*/
	public function append_to_approved($address)
	{
		$this->approved_array[] = $address;
	}
	
	
	//---------- detectors ---------//
	/**
	* return TRUE on approved sender
	* test just address, not personal
	* also just checks first address
	* NB. has a cache (checks is_null())
	* that avoids the in_array() check
	* and the log_state() call
	*/
	public function approved_sender()
	{
		//pe($this->that);
		if (is_null($this->approved_sender))
		{
			$add = strtolower($this->get_address_from());
			$approved_arr = $this->get_approved();
			foreach($approved_arr as $check_add)
			{
				if ($add == strtolower($check_add))
				{
					$this->approved_sender = TRUE;
					break;
				};
			}
			$this->that->log_state('Sender '. $add .' approved? : ' . var_export($this->approved_sender,TRUE));
		}
		return $this->approved_sender;
	}
	
	
	public function not_approved_sender_and_is_bounce_message()
	{
		return (! $this->approved_sender() && $this->is_bounce_message());
	}
	
	/**
	* test various header params against bounced message
	* patterns to figure out if this is a bounce
	* relies on preg_match_subject() detector in header
	* class
	* This is not complete, needs lots of work to be a full
	* bounce detector
	*
	*/
	public function is_bounce_message()
	{
		// check Return-Path header line
		// for "no return path" indicator <>
		// but, Return-Path is changed
		// if message is Redirected... hmmmn
		// also should check the Delivery-Status part
		// if there is one to determine if it is a bounce
		$rp = $this->preg_match_header_array_key(array('Return-Path','<>'));
		//pe($this->header_string);
		// order subject matches in order of likelihood
		$subject_patterns = array('/Returned mail:/i');
		foreach ($subject_patterns as $p)
		{
			// return on the first match
			if ($this->preg_match_subject($p))
			{
				return TRUE;
			}
		}
		return FALSE;
	}

		
	/**
	* determine if the text to be appended to the
	* resent email has already been appended
	* to PLAIN or HTML properties
	*/
	
	public function already_appended($pattern)
	{
		return (bool)($this->that->preg_match_PLAIN($pattern) OR $this->that->preg_match_HTML($pattern)); 
	}
	
	//---------- callbacks ---------//
	/**
	* resend the email to the array of addresses
	* just grabs the raw email from string to use
	* but if a rewritten version of the HTML or PLAIN is
	* available, then use that
	* should make this have accessor fns and set bits to say
	* also, should follow the order of the email precedence
	* meaning, usually HTML supersedes PLAIN, but it might 
	* be different, and this would override that order
	*
	*/
	public function resend_email()
	{	
		// figure out how to compose the body
		if ($this->that->HTML!='')
		{
			$body = (isset($this->that->HTML_rewritten)) ? $this->that->HTML_rewritten : $this->that->HTML;
		}
		else
		{
			$body = (isset($this->that->PLAIN_rewritten)) ? $this->that->PLAIN_rewritten : $this->that->PLAIN;
		}

		$is_not_html = $this->that->has_PLAIN_not_HTML();

		// get the attachment filenames
		$file_array = $this->that->get_file_name_array();
		
		// this function is in the email_send_helper
		// and requires a modified email library
		// settings for using google as the smtp machine are
		// embedded in the helper
		// you can replace this with a simpler email sender
		send_email_by_google($this->get_list_address(),
			$this->get_resend_to(),
			$this->get_resend_cc(), 
			$this->that->get_address_from(),
			$this->that->get_personal_from(),
			$this->that->get_subject(),
			$body, $is_not_html, $file_array);
	}
	
	/*
	* if an address in the CC field
	* is also in the recipient list for this
	* resend_to_array then strip it
	* so the list member doesn't get 2 copies
	* of the message - relies on the CC
	* for the message to get through
	*
	*/
	public function strip_resend_to_email_if_also_in_cc()
	{
		// NOTE: only deals with first CC!
		$add = $this->that->get_address_cc();
		$this->remove_from_resend_to($add);
	}
	
	// wrapper
	public function get_address_from()
	{
		$arr = $this->that->get_from_array('mailbox@host');
		return $arr[0];
	}
	
	// wrapper
	public function get_personal_from()
	{
		$arr = $this->that->get_from_array('personal');
		return $arr[0];
	}
	
	/**
	* specialized function to act on string
	* in subject and add a recipient if the 
	* string is there
	* this should be run before the resend_
	* method itself so that both the subject
	* and the recipient list are complete
	* before the email gets sent out
	*
	*/
	public function strip_subject_add_recipient($arr)
	{
		$this->subject = str_replace($arr['strip'], '',$this->get_subject());
		//p($arr['to']);pe($this->subject);	
		// should make sure it is a 'valid' email address
		$this->append_to_resend_to($arr['resend_to']);
		//p($this->get_resend_to_array());pe($this->PLAIN);
	}
	
	
	
}

//EOF