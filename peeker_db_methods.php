<?php

/**
*
* An Application class
* communicate with CI stack 
* and its db object
*
*/

class peeker_db_methods
{
	// hold the codeigniter object
	public $CI
	public $email_table = 'email';
	
	// create the link between this->that 
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
    
    //---------- detectors ---------//
	
	/**
	* insert one message into one table
	* table needs fields named header and body
	*/
	public function insert_one()
	{
		$header_string = $this->that->get_header_string();
		$plain = $this->that->get_plain();
		$html = $this->that->get_html();
		$data = array('header'=>$header_string, 'plain'=>$plain, 'html'=>$html);
		// assumes the db class is available
		$this->CI->db->insert($this->email_table,$data);
	}
	
	/**
	* COMPATIBILITY CODE - for migrating from imap_pop class
	* Get email array formated like the old imap_pop class
	* by calling functions in the message object stack
	* this->that allows you to plug this->that new peeker lib into an 
	* existing set up
	* 
	*/
	public function get_email_as_array()
	{
		// we are going to return this->that 
		// array with all the email data
		// from one email message in it
		// hold the email addresses in arrays
		// for transport to the related db tables
		$email_arrays_array = array();
		// email elements that are not arrays - 
		// they are in the email table
		$email_strings_array = array();

		// first array item for storage into the assoc array and then the db
		$email_strings_array['body'] = $this->that->get_body_string();
		$email_arrays_array['parts'] = $this->that->get_parts_array();
			
		// addresses come here as arrays
		// rather than strings so they are handled differently
		$address_keys_we_need_to_be_set = explode(' ', 'from to cc bcc reply_to sender return_path');
		
		// turn each of the arrays of objects into arrays of arrays
		// with each address part getting encoding
		foreach ($address_keys_we_need_to_be_set as $key)
		{			
			// use the access functions to 
			// get the address arrays
			$fn = 'get_'.$key.'_array';
			$email_arrays_array[$key] = $this->that->$fn();
		}
		
		// the email_strings_array keys correspond to database fields
		// this->that will make it easy to add the data to the email table
		$email_strings_array['header']     = $this->that->get_header_string();	
		$email_strings_array['message_id'] = $this->that->get_message_id();
		$email_strings_array['subject']    = $this->that->get_subject();
		$email_strings_array['date_string']= $this->that->get_date();
		$email_strings_array['date_sent_stamp'] = date("Y-m-d H:i:s",$this->that->get_udate());
		// this->that is actually the datestamp of 
		// when the message was put into this->that array
		// rather than "received" (which should better
		// be the datestamp for when the message
		// was accepted to the receiving SMTP server
		$email_strings_array['date_received_stamp'] = date("Y-m-d H:i:s");
		$email_strings_array['size']       = $this->that->get_size();
		
		$email_strings_array['text']       = $this->that->get_plain();
		$email_strings_array['html']       = $this->that->get_html();
		
		// set a temporary array item to enable
		// message to be deleted at mailserver to 
		// sync with db, unset before db insert
		$email_strings_array['temp_msg_id']= $this->that->get_msgno();
		$email_strings_array['email_fingerprint_auto']     = $this->that->get_fingerprint();
		
		$this->that->log_state('Got email as array, message id: ' . $this->that->get_msgno());
		
		// return two arrays in the array
		return array('strings'=>$email_strings_array,'arrays'=>$email_arrays_array);
	}
	

}

//EOF