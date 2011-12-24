<?php

/**
*
* Makes email headers into objects
* so they can be acted on by other classes
* access data, execute detectors, etc...
* class vars are mapped to the header object vars
* returned by the imap_headerinfo() function
* and header_array var is mapped to the
* imap_fetchheader() output array
* extends peek_mail_connect so that this class
* can manipulate messages on the mail server
*
* with OO design, other methods can be added
* 
*
*/

// the super class, provides method 
// layering if needed for plugins
include_once('peeker_layers.php');

class peeker_header extends peeker_layers{

	// the spawning parent
	public $peek_parent;
	// the resource - used to manipulate messages
	// passed to constructor because the instance is spawned
	// into a collection rather than inherited
	public $res;
	// the message id as it came in (not unique)
	// treat as a temporary id
	public $Msgno; 
	
	// these are all properties of the email message
	// that can be gleaned from the header data
	
	// standardized date string RFC822
	public $date;
	public $subject; // decoded subject
	public $raw_subject; // undecoded subject
	public $message_id; // raw message_id header
	
	// address fields as strings - 1024 character limit
	public $toaddress;
	public $fromaddress;
	public $reply_toaddress;
	public $senderaddress;
	public $ccaddress;
	public $bccaddress;
	public $return_pathaddress;
	
	public $remail; // address for remailing - experimental
	
	// address fields as arrays	
	public $to;
	public $from;
	public $reply_to;
	public $sender;
	public $cc;
	public $bcc;
	public $return_path;

	public $Size; // in bytes
	public $udate; // unix timestamp
	
	public $in_reply_to; // a reference to another email message
	public $followup_to; // a reference to another email message
	public $references; // a reference to another email message
	
	// flags for IMAP - descriptions from http://php.net/imap_headerinfo 
	public $Recent; // R if recent and seen, N if recent and not seen, ' ' if not recent.
	public $Unseen; // U if not seen AND not recent, ' ' if seen OR not seen and recent
	public $Flagged; // F if flagged, ' ' if not flagged
	public $Answered; // A if answered, ' ' if unanswered
	public $Deleted; // D if deleted, ' ' if not deleted
	public $Draft; // X if draft, ' ' if not draft
	
	// the raw header string
	public $header_string;
	// header string converted to nested arrays
	public $header_array;
	
	// fingerprint data point sources
	// use these email strings to generate 
	// a "good enough" fingerprint to id dupes
	// also use this unique fingerprint to 
	// create a directory for saving attachments
	public $fingerprint_sources = array('fromaddress', 'toaddress', 'subject', 'date');
	// md5 hash of sources data points
	public $fingerprint = ''; 
	
	// if marked for delete
	// test this var before calling imap_ fns
	public $mark_delete = FALSE;
	
	// store errors with names of imap functions for keys
	public $error_array;
	
	/**
	* Constructor
	* 
	*/
	public function __construct(&$peek_parent, $imap_h_obj)
	{
		// host the connection to the IMAP server
		// and allow these classes to target functions
		// in the wrapper classes: peek and connect
		$this->peek_parent =& $peek_parent;
		
		// handle the stdClass $imap_h_obj
		// populating builtin vars
		$this->_set_class_vars($imap_h_obj);
		
		// handle special cases, clean up, 
		// or default conversions
		$this->Msgno = trim($this->Msgno);
		// decode the MIME representation
		// keep the undecoded subject for encoding detection
		$this->raw_subject = $this->subject;
		$this->subject = $this->peek_parent->decode_mime($this->subject);
		// create a hash for dupe detections 
		// and other things (e.g., directory name)
		$this->_generate_email_fingerprint();
		
		$this->log_state('LOADING header class');
	}
	
	/*
	* Utility function to make sure all incoming
	* data has a place to go inside this object
	*
	*
	*/
	
	public function _set_class_vars($obj)
    {
        $class = get_class($this);
        $class_vars = get_class_vars($class);
        
        // check that each of the passed parameters 
        // are valid before setting the class variable
        foreach ( $obj as $var => $value )
        {
            if ( array_key_exists( $var, $class_vars ) )
            {        
                $this->$var = $value;
            }
            else
            {
                //log_message('DEBUG','setClassVars: class var "'.$var.'" not in class "' .$class.'"');
            }
        }
    }
    
    /** 
    * do fingerprint
	* so we can use it to check email dupes
	* (among other things)
	* compose from a string with data points
	* that are pulled from the email header
	* NOTE: these are raw headers before 
	* applying any decoding
	* could "salt" this...
	*/
	public function _generate_email_fingerprint()
	{
		foreach ($this->fingerprint_sources as $prop)
		{
			$this->fingerprint .= $this->$prop;
		}
		$this->fingerprint = md5($this->fingerprint);
	}
	
	
	/**
	* run the error handler and store the error array
	* inside this object 
	*
	*/
	public function _check_imap_errors($func)
	{
		$err = imap_errors();
		if ($err !== FALSE) $this->error_array[$func] = $err;
	}

	
	/**
	* Get the fingerprint hash
	*
	*/ 
	public function get_fingerprint()
	{
		return $this->fingerprint;
	}
	
	/**
	* Get the RFC822 date
	* just returns the raw date as sent with the message
	*/ 
	public function get_date()
	{
		return $this->date;
	}
	
	
	/**
	* Get the subject
	* Subject is sometimes encoded
	*/ 
	public function get_subject()
	{
		return $this->subject;
	}
	
	/**
	* Get the temp message id assigned by the mail server
	*
	*/ 
	public function get_msgno()
	{
		return $this->Msgno;
	}
	
	/**
	* Get the message id
	*
	*/ 
	public function get_message_id()
	{
		return $this->message_id;
	}
	
	/**
	* these Accessor functions return the string
	* which may contain multiple addresses in string
	*
	*/
	public function get_to() 			{return $this->_get_address_string('to');}
	public function get_from() 		{return $this->_get_address_string('from');}
	public function get_reply_to() 	{return $this->_get_address_string('reply_to');}
	public function get_sender() 		{return $this->_get_address_string('sender');}
	public function get_cc() 			{return $this->_get_address_string('cc');}
	public function get_bcc() 			{return $this->_get_address_string('bcc');}
	public function get_return_path() 	{return $this->_get_address_string('return_path');}
	
	/**
	* Get the address
	* Send types: to, from, reply_to, sender, cc, bcc, return_path
	* Like subject, this is sometimes encoded
	* returns FALSE if there is no address type
	* This is like the get_address_array() fn
	* but, it returns the raw string (mime decoded)
	*/ 
	public function _get_address_string($in_type)
	{
		$type = strtolower($in_type).'address';
		if (isset($this->$type))
		{
			$data = $this->$type;
		}
		else
		{
			return FALSE;
		}
		// something there, decode it
		$data = $this->peek_parent->decode_mime($data);
		return $data;
	}
	
	/**
	* these Accessor functions return the array
	* which may contain multiple addresses as items
	*
	*/
	public function get_to_array($format=NULL) {return $this->_get_address_array('to',$format);}
	public function get_from_array($format=NULL) {return $this->_get_address_array('from',$format);}
	public function get_reply_to_array($format=NULL) {return $this->_get_address_array('reply_to',$format);}
	public function get_sender_array($format=NULL) {return $this->_get_address_array('sender',$format);}
	public function get_cc_array($format=NULL) {return $this->_get_address_array('cc',$format);}
	public function get_bcc_array($format=NULL) {return $this->_get_address_array('bcc',$format);}
	public function get_return_path_array($format=NULL) {return $this->_get_address_array('return_path',$format);}
	
	/**
	* Get the address array for the message id and type
	* Send types: to, from, reply_to, sender, cc, bcc, return_path
	* Like subject, this is sometimes encoded
	* so we decode each part here
	* returns FALSE if there is no address type
	*/ 
	public function _get_address_array($in_type, $format)
	{
		$address_array = array();
		$type = strtolower($in_type);
		if (isset($this->$type))
		{
			$address_array = $this->$type;
		}
		else
		{
			return $address_array; // empty array
		}
		
		$addr_part_names = array('personal','mailbox','host');
		
		// something's there, decode each array item
		foreach ($address_array as $key => $item)
		{
			// decode each address part - probably overkill
			foreach ($addr_part_names as $addr_part)
			{
				if (isset ($item->$addr_part))
				{
					// stuff it back into the array
					$address_array[$key]->$addr_part = $this->peek_parent->decode_mime($item->$addr_part);
				}
			}
			//p($address_array);
			// replace the format strings with the properties
			if ($format !== NULL)
			{
				$formatted = str_replace($addr_part_names, array('$item->personal', '$item->mailbox', '$item->host'), $format);
				// ugly eval lets us do formatting 
				// trick without double-replacing (ie str_replace in a loop)
				// suppress errors from missing addr_part
				@eval("\$evaled = \"$formatted\";");
				$address_array[$key] = $evaled;
			}
		}
		return $address_array;
	}
	
	/**
	* Get the size of the message
	* converted from Size
	*/ 
	public function get_size()
	{
		return $this->Size;
	}
	
	
	/**
	* Get the unix timestamp on the message
	* converted from udate field
	*/ 
	public function get_udate()
	{
		return $this->udate;
	}
	
	/**
	* alias to get_udate
	*/
	public function get_timestamp() 
	{
		return $this->get_udate();
	}
	
	/**
	* Get the raw header string
	* all kinds of good data in here
	* that is not available in imap_fetchheader()
	*/ 
	public function get_header_string()
	{
		return $this->header_string;
	}
	
	/**
	* Get the array that holds the 
	* converted raw header string
	* all kinds of good data in here
	* that is not available in imap_fetchheader()
	*/ 
	public function get_header_array()
	{
		return $this->header_array;
	}
	
	
	/**
	* A generic header getter.
	* Get one item from the header_array
	* Pass the header name, if not there
	* function returns FALSE
	* Note: sometimes header items will be arrays
	* eg. Received header is usually an array
	*/ 
	public function get_header_item($header_key)
	{
		$h = FALSE;
		if (isset($this->header_array[$header_key])) 
		{
			$h = $this->header_array[$header_key];
		}
		else
		{
			$this->log_state('header_item not set. key not in header array: '.$header_key);
		}
		return $h;
	}
	
	/**
	* Get the array that holds the 
	* error message arrays with keys
	* for the function name that
	* triggered the error
	*/ 
	public function get_error_array()
	{
		return $this->error_array;
	}
	
	/**
	* Return mark_delete
	* 
	*/ 
	public function get_mark_delete()
	{
		return $this->mark_delete;
	}
	
	/**
	* Mark this object as deleted
	* 
	*/ 
	public function set_mark_delete($delete)
	{
		$this->mark_delete = $delete;
		return $this->mark_delete;
	}
	
	
	/*------Wrappers--------*/
	
	/**
	* wrapper to pipe the detectors_abort 
	* message to the peek_parent
	*
	*/
	public function detectors_abort($state=TRUE)
	{
		$this->peek_parent->detectors_abort($state);
	}
	
	/**
	* abort, do action array items if called for
	* right now only delete is possible
	*/
	public function abort($action_array=FALSE)
	{
		$del = FALSE;
		if (isset($action_array['delete']) && $action_array['delete'] === TRUE)
		{
			$del = $this->set_mark_delete(TRUE);
		}
		$this->detectors_abort(TRUE);
		$this->log_state('&raquo; ABORTING detectors #'.$this->Msgno . ' Delete? :' . strtoupper(var_export($del,TRUE)));	
	}
	
	
	/**
	* if most recent detector fired TRUE make all the
	* other detectors abort, pass action_array argument
	*
	*
	*/
	public function abort_if_previous($action_array=FALSE)
	{
		if ($this->peek_parent->_get_previous_detector_state())
		{
			$this->abort($action_array);
		}
	}
	
	
	// ------- detectors - return boolean ------- //
	
	/**
	* return TRUE all the time
	* for testing
	*/
	public function ttrue($arg) 
	{		
		return TRUE;
	}
	
	/**
	* return FALSE all the time
	* for testing
	*/
	public function ffalse($arg) 
	{		
		return FALSE;
	}
	
	/**
	* return whatever was sent in
	* for testing
	*/
	public function reflect($arg) 
	{		
		return $arg;
	}
	
	/**
	* true if Msgno property is equal to arg
	* 
	*/
	public function is_msgno($msgno) 
	{
		return $this->Msgno == $msgno;
	}
	
	/**
	* true if regex $pattern matches the field
	* return TRUE or FALSE, not int like preg_match
	*/
	public function preg_match_field($arr) 
	{
		list($field,$pattern) = $arr;
		return (bool)preg_match($pattern, $this->$field);
	}
	
	/**
	* true if header array key is set
	* the key exists, but could be NULL
	* cf array_key_exists() if you need
	* a key exists test without the NULL check
	*/
	public function isset_header_array_key($key) 
	{
		return isset($this->header_array[$key]);
	}
	
	/**
	* true if regex $pattern matches the header entry
	* return TRUE or FALSE, not int like preg_match
	*/
	public function preg_match_header_array_key($array) 
	{		
		list($key, $pattern) = $array;
		if ($this->isset_header_array_key($key))
		{
			return (bool)preg_match($pattern, implode(' ',(array)$this->header_array[$key]));
		}
		else
		{
			return FALSE;
		}
	}
	
	
	/**
	* true if header property is empty
	* 
	*/
	public function empty_property($property) 
	{
		$e = empty($this->$property);
		if ($e) $this->log_state('EMPTY '.$property .' in msg #'.$this->Msgno);
		return $e;
	}
	
	
	/**
	* true if undecoded fromaddress has a given string in it
	* Case-insensitive.
	*/
	public function in_from($from_str)
	{
		return strpos(strtolower($this->fromaddress),strtolower($from_str))!==FALSE;
	}
	
	/**
	* true if undecoded toaddress has a given string in it
	* Case-insensitive.
	*/
	public function in_to($to_str)
	{
		return strpos(strtolower($this->toaddress),strtolower($to_str))!==FALSE;
	}
	


	//--------- callbacks ----------//
	
	/**
	* mark message for deletion
	* NOTE: in POP, must call imap_expunge()
	* before closing the connection
	* to actually delete the message
	* optional parameter lets detectors abort
	* on this delete as well
	*/
	public function set_delete($abort=FALSE)
	{
		if($this->Msgno > 0)
		{
			$this->log_state('Mark DELETE #'.$this->Msgno);
			$this->set_mark_delete(TRUE);
			imap_delete($this->peek_parent->resource, $this->Msgno);
			// pass the action_array with delete as FALSE
			if ($abort) $this->abort(array('delete'=>FALSE));
		}
		else
		{
			$this->log_state('Cannot DELETE zero or negative #'.$this->Msgno);		
		}
	}
	
	/**
	* unmark message for deletion
	* allows removing messages from
	* the delete state
	*/
	public function undelete()
	{
		$this->set_mark_delete(FALSE);
		imap_undelete($this->peek_parent->resource, $this->Msgno);
		$this->log_state('Undeleted message #'.$this->Msgno);
	}
	
	/**
	* IMAP specific - wraps flag and move functions
	* flag seen, then move message to another mailbox
	* helps us remember to flag, then move
	*/
	public function flag_seen()
	{
		$this->flag_mail('\Seen');
	}
	
	/**
	* move a message to another mailbox
	*
	*/
	public function move_mail($mailbox_name)
	{
		$this->peek_parent->move_mail($this->Msgno, $mailbox_name);
		$this->log_state('Moved message #'.$this->Msgno . ' to '. $mailbox_name);
	}
	
	/**
	* IMAP specific - wraps flag and move functions
	* flag seen, then move message to another mailbox
	* helps us remember to flag, then move
	*/
	public function flag_seen_move_mail($mailbox_name)
	{
		$this->flag_seen();
		$this->move_mail($mailbox_name);
	}
	
	/**
	* flag a message
	* see http://php.net/imap_setflag_full 
	* for which flags you can use:
	* \Seen, \Answered, \Flagged, \Deleted, 
	* and \Draft as defined by RFC2060
	* Default to TRUE flag_state, set FALSE
	* to remove the flag
	*/
	public function flag_mail($flag_string,$flag_state=TRUE)
	{
		$this->peek_parent->flag_mail($this->Msgno, $flag_string, $flag_state);
		$this->log_state('Flagged message #'.$this->Msgno . ' as '. $flag_string . (string)$flag_state);
	}
	
	
	
	
	/**
	* display utility
	* removes peek_parent property
	* 
	*/
	public function _print($d=NULL)
	{
		if ($d===NULL) $d = $this;
		// don't display the peek parent property
		if (isset($d->peek_parent)) unset($d->peek_parent);
		echo '<pre>';print_r($d);echo '</pre>';
	}
	
	/**
	* print the argument
	*
	*/
	public function pr($data)
	{
		$this->_print('PRINT: '.$data.' in message #'.$this->Msgno);
	}
	
	/**
	* print the field_name
	* assumes the property name 
	* has an accessor function
	*/
	public function print_field($fn, $use_html_entities=TRUE)
	{
		$func = 'get_'.$fn;
		$data = ($use_html_entities) ? htmlentities($this->$func()) : $this->$func();
		$this->_print('PRINT field: '.$fn.' in message #'.$this->Msgno . ' : '.$data);
	}
	
	/**
	* print the array
	* and optional nested sub-item
	*
	*/
	public function print_array($arr)
	{
		list($fn,$sub_fn) = $arr;
		$func = 'get_'.$fn;
		$data = $this->$func();
		$data = ($sub_fn!=='') ? $data[$sub_fn]: $data;
		$this->_print('Message #'.$this->Msgno. ' ' .$fn. '...'); echo '<hr>';
		$this->_print($data);

	}
	
	
	/**
	* prepend a string to the subject
	* useful for tagging messages
	* before storing or re-sending
	* only prepend if there is no string
	* already in the subject
	*
	*/
	public function prepend_subject($prepend_string)
	{
		$subj = $this->get_subject();
		if (strpos($subj,$prepend_string)===FALSE)
		{
			$this->subject = $prepend_string . $subj;
			$this->log_state('Subject prepended to message #'.$this->Msgno . ': '. $prepend_string);
		}
		else
		{
			$this->log_state('Subject for message #'.$this->Msgno . ' already prepended: '.$prepend_string);
		}
	}
	
	
	/* ------ Wrappers to talk to peek_parent ------ */
	
	/**
	* wrapper to get the output from 
	* get_message_count() 
	* allows access to the parent
	* from the message class while
	* inside the detector loop
	*
	*/
	public function message_count()
	{
		return $this->peek_parent->get_message_count();
	}
	
	/**
	* wrapper to pipe log state messages
	* up to the parent class, allows it
	* to be used inside a message acquisition
	* loop
	*
	*/
	public function log_state($str)
	{
		$this->peek_parent->log_state($str);
	}
	
	/**
	* wrapper to pipe expunge calls
	* up to the parent class, allows it
	* to be used by an individual email or
	* inside a message acquisition loop
	*
	*/
	public function expunge()
	{
		$this->peek_parent->expunge();
	}
}

// EOF