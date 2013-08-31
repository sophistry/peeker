<?php

/**
*
* Get information about email by looking at the header data
* Using only header data access functions in this class
* makes it so that we are just "peeking" at the data
* and the POP3 server doesn't mark it as "read"
* even gmail does not process the POP handling rule
* to archive or delete or mark as read
*
*/

include_once('peeker_connect.php');
// this class uses "layered methods" architecture 
// something like the Decorator pattern...
// or Dependency Injection (type 3, interface)
// http://martinfowler.com/articles/injection.html
// it's a little bit like mixins...
// or like PHP6 "Traits" to allow "plugin" methods
// outside of inheritance tree
// see https://wiki.php.net/rfc/horizontalreuse

class peeker extends peeker_connect{

	// set TRUE to automatically expunge 
	// deleted messages on close()
	public $expunge_on_close 		= FALSE; 

	public $message_object_array 	= array();
	public $start_id 				= 0; // requested or derived start id
	public $end_id 					= 0; // requested or derived end id
	public $current_id 				= 0; // incremented in the acquire loop
	
	// the message_class property
	// allows the message class to be subclassed
	// defaults to peeker_parts, slowest, and
	// "intrusive" on POP3 accounts, but most complete
	
	// use a "header" class if you want to peek
	// without disturbing the state of POP messages
	// because it just gets header fields
	// OR
	// create a new class and use it for adding functions
	// to be used by detectors and callbacks (see examples)
	public $message_class 			= 'peeker_parts';
	
	// turn on and off detector circuits
	// "global" switch for controlling all detectors
	// default to off, create_detector() turns on
	public $detectors_on 			= FALSE;
	public $detector_set;
	
	// layers empty array by default, so no errors
	public $layer_object_array 	= array();
	
	// directory for all the attachments to get stored
	// if they are saved to the filesystem
	// files shouldn't be handled in this class
	// there should be an optional filesystem handler
	// "plugin" because you can use this class without
	// ever handling an attachment or dealing with files
	// but, it may be simpler to keep it this way
	public $attachment_dir 		= '';
	
	// hold the valid encodings 
	// that mb functions can utilize
	// in mime decoding technique
	// these should be in utility, but keep them
	// here for simplicity
	public $valid_mb_encoding_array;
	
	// handle search parameters and 
	// the returned id list array
	public $search;
	public $id_list_from_search;
	public $message_count_from_search = 0; // cf $message_count in parent class
	
	/**
	* Constructor
	* 
	*/
	public function __construct($init_array = NULL)
	{
		// build the valid encodings once for mime decoding
		// could cache this even further up the chain
		$this->valid_mb_encoding_array = array_flip(array_change_key_case(array_flip(mb_list_encodings())));
		// call the parent constructor
		parent::__construct($init_array);	
	}
	
	/**
	* Wrapper to make assigning message classes
	* as easy as possible. Set the message class
	* to the peek_mail_parts class and get 
	* everything using the detector method parts()
	* in the peek_mail_parts class
	*/
	public function get_message($start, $end=NULL)
	{
		// set the message class to the parts class
		// that just gets everything, this disturbs
		// the read state on gmail POP accounts
		//$this->set_message_class('peeker_parts');
		// set up a detector that calls 
		// the email acquistion method
		// remove the detector_set so it is recreated new each time
		// in case get_message is called in a loop and detector 
		// just stacks up get_parts calls
		unset($this->detector_set);
		$this->make_detector_set();
		// the most complete email "pulling" method
		// but, only works with peek_mail_parts class
		$this->detector_set->detect_phase('get_parts');
		// run the acquisition loop
		return $this->message($start, $end);

	}
	
	/**
	* Loop over the messages
	* calling to the mail server each time.
	* Sets up the internal array
	* with ids as keys that the message() 
	* function can use to get the right message
	* Also calls any detectors set up for messages
	* $start required, $end is not
	*/
	public function message($start, $end=NULL)
	{
		// protect the command from 
		// using empty string like NULL or 0
		// because messages start at MsgNo 1, not 0
		// TODO: add exception, better error handling
		if ($start===0) $this->log_state('ERROR: MsgNo cannot be 0. Mail server MsgNo starts at 1.');
		// force it to message 1
		// should change this to error
		$start = ($start==='') ? 1 : $start;
		// load the class specified in the property
		// there must be a better way to do this
		// default to the peeker_header class
		include_once($this->message_class.'.php');
		
		// set start_id, end_id, and current_id
		$this->_set_start_and_end_ids($start, $end);
		
		// defaults to handling a continuous sequence of messages
		// if multiple message requested, stores the messages 
		// as objects in an array - could be memory intensive
		// get all the emails requested, run detectors
		while ($this->current_id++ < $this->end_id)
		{						
			$this->log_state('Fetching headers for email #' . $this->current_id);
			
			// the imap_fetchheader() string/array overlaps
			// the imap_headerinfo() object data
			// because both functions get some of the same data but
			// imap_fetchheader() gets all of the raw header data and 
			// imap_headerinfo() gets some other data like Msgno, Size, etc...
			// get the basic header data object
			// supress errors so we can track them with the message object
			$imap_h_obj = @imap_headerinfo($this->resource, $this->current_id);
			
			// calling imap_errors() clears all errors in the stack
			// stuff the errors into the message object so they get
			// stored (keyed to function) per message with the object
			$err = imap_errors();
			if (!empty($err))
			{
				$imap_h_obj->error_array['imap_headerinfo'] = $err;
			}
		
			// get the header using imap_fetchheader() 
			// to acquire additional header fields
			$header_string = @imap_fetchheader($this->resource, $this->current_id);
			$err = imap_errors();
			if (!empty($err))
			{
				$imap_h_obj->error_array['imap_fetchheader'] = $err;
			}			
			// tuck the header string in the object and
			// tuck the array in the object, there is some overlap
			$imap_h_obj->header_string = $header_string;
			$imap_h_obj->header_array = $this->_extract_headers_to_array($header_string);
			
			//pe($imap_h_obj);
			
			// create an email header object for each message
			// this needs to be able to be created using a sub-class
			// to allow devs to customize the detector rules
			// send $this to the spawned object to link it to the parent class
			$em_message_obj = new $this->message_class($this, $imap_h_obj);
			
			// load any layers registered onto this message object
			// this expects an empty, initialized array (see var declaration)
			// if there isn't a layer added already
			foreach ($this->layer_object_array as $layer)
			{
				$em_message_obj->layer_methods($layer);
			}
			
			// check 'global' detector state for each message
			// detectors can change the message object
			if ($this->detectors_on)
			{
				$this->detector_set->run($em_message_obj);
				// pull the detector log into this email object log
				$this->log_state($this->detector_set->get_log_array());
				// reset the detector log
				$this->detector_set->set_log_array(array());
				$this->log_state('finished detectors for '. $this->current_id );
			}
			
			// make the message number (same as Msgno) the key
			$this->message_object_array[$this->current_id] = $em_message_obj;
			$this->log_state('Message end, email #' . $this->current_id);
			
		}
		// return one object if one message requested (only $start sent)
		// otherwise return the whole array of objects ($start and $end)
		// modified this so that it returns what it is sent
		// one parameter in = one email out
		// multiple parameters in = array out
		//if ($this->start_id === $this->end_id)
		if ( empty ($end) )
		{
			return $this->message_object_array[$this->start_id];
		}
		else
		{
			return $this->message_object_array;
		}
	}
	
	
	/**
	* figure out which messages are being requested
	* and make sure they are not out of bounds
	*
	*/
	private function _set_start_and_end_ids($start=NULL,$end=NULL)
	{
		$this->log_state("Requested _set_start_and_end_ids($start,$end)");
		
		$msg_count = $this->get_message_count();
		
		if ($start === NULL) 
		{
			$this->start_id = 1;
			$this->current_id = 0;
			$this->end_id = $msg_count;
		}
		else
		{
			// if only one parameter sent
			// set end = start to get one msg
			// but make sure it doesn't ask
			// for a message it doesn't have
			// current_id immediately increments 
			// in the loop
			if ($end === NULL) 
			{
				// make sure start_id is not too high
				$this->end_id = min($start, $msg_count);
				$this->current_id = $this->end_id-1;
				$this->start_id = $this->end_id;
			}
			else
			{
				//p('two params');
				// no negatives on start
				$this->start_id = max(1,$start);
				// check if start_id is too high
				$this->start_id = min($this->start_id, $msg_count);
				$this->end_id = min($end, $msg_count);
				$this->current_id = $this->start_id-1;
			}
		}
		$this->log_state("Getting _set_start_and_end_ids($this->start_id,$this->end_id)");
	}
	
	
	// ------- IMAP Search ------- //
	// search returns an array of msgids that match
	// handle them internally
	
	/**
	*
	* set the IMAP search string
	*
	*
	*/
	public function set_search($search='')
	{
		$this->search = $search;
		$this->log_state('Setting search of (' . $this->mailbox . ') - to query: '.$this->search);
	}
	
	
	/**
	* Get the number of emails at server based on search params
	* Calling this function updates msg_count var
	* and if there is a message found
	*/
	public function search_and_count_messages($search='')
	{		
		$this->log_state('Searching current mailbox (' . $this->mailbox . ') - query: '.$this->search);

		// the search returns array of message ids
		$this->id_list_from_search = imap_search($this->resource,$this->search);
		//p($this->id_list_from_search);
		if (is_array($this->id_list_from_search))
		{
			$this->message_count_from_search = count($this->id_list_from_search);
			$this->log_state('Search found: '.$this->message_count_from_search . ' messages.');	
			return $this->message_count_from_search;
		}
		else
		{
			$this->log_state('Search found 0 messages.');			
			return 0;
		}
	}
	
	/**
	* return id array or id
	* from the search
	*
	*/
	public function get_ids_from_search($index='')
	{
		return ($index==='') ? $this->id_list_from_search : $this->id_list_from_search[$index];
	}
	
	
	/**
	* Extract an array listing from the header
	* Get all the possible headers (multi-line, domain keys, 
	* repeated headers , etc...) tucked away nicely into 
	* a multi-level nested array
	* Note: this does not decode any headers.
	* If you need to decode, pull out the data and decode 
	* using the function decode_mime()
	*
	*/ 
	private function _extract_headers_to_array($header)
	{
		$header_array = explode("\n", rtrim($header));
		// drop off any empty, null or FALSE values
		$header_array = array_filter($header_array);
		
		$new_header_array = array();
		foreach ($header_array as $key => $line)
		{
			// check if this line starts with a header name
			// if it does, build the new header item
			// if it doesn't, build the string out
			if (preg_match('/^([^:\s]+):\s(.+)/',$line,$m))
			{
				// force all header keys to have ucfirst()
				$current_header = ucfirst($m[1]);
				// remove the extra newline
				$current_data = trim($m[2]);
				// if there is no header by this name yet
				// set the data, otherwise, append it as array item
				if (!isset($new_header_array[$current_header])) 
				{
					// this is the normal branch, new header, one line of data
					$new_header_array[$current_header] = $current_data;
				}
				else
				{
					// if it is not an array, it is a string and we need
					// to convert the existing data to an array, and add the new
					if (!is_array($new_header_array[$current_header]))
					{
						// this runs when a header name is repeated 
						// (like Received often is)
						// runs the 1st time it is repeated 
						// (second occurance of the header)
						// converts the existing string and the 
						// incoming string to a 2-item sub-array
						$new_header_array[$current_header] = array($new_header_array[$current_header],$current_data);	
					}
					else
					// if it is already an array then append an array item
					{
						// this runs when a header name is repeated 
						// (like Received often is)
						// runs 3rd and subsequent times
						$new_header_array[$current_header][] = $current_data;
					}
				}
			}
			else 
			{
				// if it is already an array then append 
				// the string to the last sub-array item
				// because we assume the lines with no header names
				// are part of the most recently added sub-array item
				if (is_array($new_header_array[$current_header]))
				{
					// this runs if there has already been a header 
					// of the same header name
					$new_header_array[$current_header][count($new_header_array[$current_header])-1] .= $line;
				}
				else
				// if it is not an array, it is still just a string 
				// and we need to build the string out
				{
					// this runs if the line is part of the first 
					// header encountered
					// but is part of a long multiline string 
					// (like Received header)
					$new_header_array[$current_header] .= $line;
				}
			}
		}
		return $new_header_array;
	}
	
	/**
	* Decode a string, return string
	* decoded to a specified charset. if the charset
	* isn't supported by mb_convert_encoding(), 
	* def_charset will be used to decode it.
	* send it a MIME encoded header string
	*/
	public function decode_mime($mime_str_in, $in_charset='utf-8', $target_charset='utf-8', $def_charset='iso-8859-1') 
	{
		// valid encodings in lowercase array
		$in_charset = strtolower($in_charset);
		$target_charset = strtolower($target_charset);
		$def_charset = strtolower($def_charset);
		
		$decoded_str = '';
		$mime_strs = imap_mime_header_decode($mime_str_in);
		$charset_match = ($in_charset === $target_charset);
		foreach ($mime_strs as $mime_str)
		{
			$mime_str->charset = strtolower($mime_str->charset);
			if ( ( $mime_str->charset === 'default' AND $charset_match ) OR 
				 ( $mime_str->charset === $target_charset ) )
			{
				$decoded_str .= $mime_str->text;
			}
			else
			{	
				$charset = ( in_array( $mime_str->charset, $this->valid_mb_encoding_array ) ) ? $mime_str->charset : $def_charset;
				// TODO: this should also handle base64 encoded stings as well as quoted_printable
				$decoded_str .= mb_convert_encoding(quoted_printable_decode( $mime_str->text ), $target_charset, $charset );
			}
		} 
		return $decoded_str;
	}
	
	
	/**
	* Change which class the messages() loop
	* uses to instance email objects
	* so it can be subclassed and 
	* functions added for detectors
	*
	* This is critical for adapting the
	* peek_mail classes to do various
	* things with email messages
	*
	*
	*/
	public function set_message_class($class_name)
	{
		$this->message_class = $class_name;
	}
	
	/**
	* return one or all the array of message objects
	* acquired in messages()
	*
	*/
	public function get_message_object($key='')
	{
		if ($key!=='') 
		{
			return $this->message_object_array[$key];
		}
		else
		{
			return $this->message_object_array;
		}
	}
	
	/**
	* flag or unflag the message
	* wraps the imap_setflag_full() and 
	* the imap_clearflag_full() functions
	* TODO: error checking on input
	*/
	public function flag_mail($id_or_range, $flag_string, $set_flag = TRUE)
	{
		if ( $set_flag )
		{
			$bool = imap_setflag_full($this->resource,$id_or_range,$flag_string);
			$this->log_state('Flagged message: ' . $id_or_range . ' as ' . $flag_string);
		}
		else
		{
			$bool = imap_clearflag_full($this->resource,$id_or_range,$flag_string);
			$this->log_state('Unflagged message: ' . $id_or_range . ' as ' . $flag_string);
		}
	}

	
	/**
	* move the message to another mailbox
	* wraps imap_mail_move() function
	* This is only applicable to IMAP connections
	*/
	public function move_mail($id_or_range, $mailbox_name)
	{
		$bool = imap_mail_move($this->resource,$id_or_range,$mailbox_name);
		$this->log_state('Moved message: ' . $id_or_range . ' to mailbox ' . $mailbox_name);		
	}
	
	/**
	* Target one or more messages to be immediately
	* marked for deletion and then expunged
	*
	*
	* Normal POP3 does not mark messages for later deletion
	* must delete them and expunge them in same connection
	* TRUE on success, FALSE on failure
	* BUT... if you use any body calls Google's gmail 
	* does mark as read and then does not serve them to POP3 again even though 
	* they may still be in the INBOX. They are doing some extra 
	* thing to the message to make it invisible to POP3 once it has been
	* picked up by ANY POP3 request that grabs the email body data (headers ok)
	* So, gmail does not seem to be affected by imap_delete() imap_expunge(),
	* it only cares about its own settings with regard to how 
	* to handle message storage after a POP3 connection (see gmail settings tab)
	*/
	public function delete_and_expunge($start_id_or_range, $end_id = '')
	{
		// make sure we've got a message there of that id
		// and it's not a bogus id like 0 or -1
		// also allow ranges to be sent to this function
		// format 1:5 or 1,3,5,7 so if a colon or comma is sent, we assume
		// it is properly formatted - not the best idea
		// should check the formatting with this kind of 
		// regexp '/[0-9]+:[*0-9]+/'
		if (strpos($start_id_or_range,':') OR 
			strpos($start_id_or_range,',')) 
		{
			// use the string as is, 
			// TODO: check it is properly formatted
			$imap_range_string = $start_id_or_range;
		}
		// these must be numbers, not required by imap range specifiers
		// but it makes this function simpler to explain
		elseif (is_numeric($start_id_or_range) && is_numeric($end_id))
		{
			// concatenate the end_id using the colon (the imap range specifier)
			$imap_range_string = $start_id_or_range .':'. $end_id;		
		}
		elseif (is_numeric($start_id_or_range) && ($start_id_or_range > 0) && ($end_id==''))
		{
			// use the number only
			$imap_range_string = $start_id_or_range;		
		}
		else
		{
			$this->log_state('Could not delete and expunge message id_or_range: ' . $start_id_or_range . ' ' . $end_id . '. Invalid message range specified.');
			return FALSE;
		}

		$this->delete($imap_range_string);
		$this->expunge();
		return TRUE;
	}
	
	
	/**
	* call imap_delete() to mark messages
	* for deletion, must call expunge to remove them
	*
	*/
	public function delete($imap_range_string)
	{
		if (is_resource($this->resource))
		{	
			// TODO: capture error here when the message doesn't exist
			imap_delete($this->resource, $imap_range_string);
			$this->log_state('Marked Deleted message range: '. $imap_range_string);
		}
		else
		{
			$this->log_state('Messages could not be marked deleted. No resource.');
		}
	}
	
	/**
	* call imap_expunge() to remove messages
	* marked for deletion
	*
	*/
	public function expunge()
	{
		if (is_resource($this->resource))
		{	
			imap_expunge($this->resource);
			$this->log_state('Expunged OK.');
		}
		else
		{
			$this->log_state('Messages could not be expunged. No resource.');
		}
	}
	
	
	/**
	* Wrapper to close the IMAP connection
	* Returns TRUE if closed with no errors
	* FALSE if imap_close() fails or if
	* there is no resource
	* Overrides the parent class close() method
	* to implement expunge facility
	*/ 
	public function close($expunge_override=FALSE)
	{
		if (is_resource($this->resource))
		{
			// allows cleaner application code, 
			// this replicates the functionality 
			// of the imap lib CL_EXPUNGE flag
			if ($this->expunge_on_close OR $expunge_override) $this->expunge();

			$closed = imap_close($this->resource);
			if ($closed)
			{
				$this->log_state('Connection closed OK.');
			}
			else
			{
				$this->log_state('Mail Resource OK but, connection did not close OK.');				
			}
		}
		else
		{
			$closed = FALSE;
			$this->log_state('Connection could not be closed. No Mail resource.');
		}
		$this->connected = FALSE;
		//p(imap_alerts());

		return $closed;
	}
	
	//------UTILITY methods------//
	
	/**
	* Get an array of emails waiting 
	* returns empty array if no messages
	* SLOW: about 5 messages per second
	*/
	public function fetch_overview($start=NULL, $end=NULL)
	{
		// set start_id, end_id, and current_id
		$this->_set_start_and_end_ids($start, $end);
		
		if(is_resource($this->resource))
		{
			$a = imap_fetch_overview($this->resource,$this->start_id.':'.$this->end_id);
		}
		else
		{
			$a = array();
			$this->log_state('No mailserver Connection. Cannot fetch overview.');
		}
		return $a;
	}
	
	
	/**
	* set the directory where attachments will be stored
	* set error flag if not writeable
	*
	*/
	public function set_attachment_dir($dir)
	{
		// isdir() causes blank page error
		//echo var_dump((isdir($dir)));
		
		if (is_writable($dir))
		{
			$this->log_state('Attachment directory set: ' . $dir);		
			$this->attachment_dir = $dir;
			return TRUE;
		}
		else
		{
			$this->log_state('Attachment directory not writeable: ' . $dir);		
			return FALSE;
		}
	}
	
	/**
	* get the path to directory 
	* where attachments will be stored
	* individual messages handle their
	* own sub-dirs inside this main dir
	* return FALSE if not set yet
	*
	*/
	public function get_attachment_dir()
	{
		// get default dir name
		$class_var_defaults = get_class_vars(get_class($this));
		$dir = $class_var_defaults['attachment_dir'];
		// has the dir been changed from default?
		if ($this->attachment_dir === $dir)
		{
			$this->log_state('Attachment directory still at default setting - not set yet.');
			return FALSE;
		}
		else
		{
			return $this->attachment_dir;
		}
	}
	

	/**
	* load up the layers which provide more methods
	* on the email object and useable by detector circuits
	* TODO: loop and load all the files requested
	*/
	public function layer($layer)
	{
		// tell the object we are using layers
		//$this->layers = TRUE;
		$layer_name = 'peeker_'.$layer.'_methods';
		$this->log_state('Adding layer: '.$layer_name);
		// load file, could do this by autoload putting file
		// in plugin/layer folder
		include_once($layer_name.'.php');
		// make an object we will use to send as a layer
		$obj = new $layer_name;
		// add to array that will be iterated 
		// over on every message object
		$this->layer_object_array[] = $obj;
	}
	
	/**
	* load up the set which allows us to load
	* detectors
	*
	*/
	public function make_detector_set()
	{
		include_once('peeker_detector_set.php');
		// make a new one if we don't have it already
		if (!isset($this->detector_set)) $this->detector_set = new peeker_detector_set();
		$this->detectors_on = TRUE;
		return $this->detector_set;
	}

	/**
	* wrapper to abort the detector loop
	* creates an interface to abort 
	* through the peek_mail parent class
	*/
	public function detectors_abort($state)
	{
		$this->detector_set->detectors_abort($state);
	}
	
	/**
	* 
	* returns FALSE if id is > msg_count
	* otherwise TRUE
	*	
	*/
	private function _check_msg_id($msg_id)
	{
		// make sure it is less than message count
		// and greater than zero
		$id_ok = (bool)( ($this->message_count >= $msg_id) && ($msg_id > 0) );
		if (!$id_ok) 
		{
			$this->log_state('Not a valid message id: ' . $msg_id);
		}
		return $id_ok;
	}

}
// EOF
