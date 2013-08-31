<?php

/**
*
* Makes email body parts into objects
* so they can be acted on by other classes
* access data, execute detectors, etc...
* class vars are mapped to the body parts
* HTML and PLAIN parts get their own properties
* but, the other parts get stored in the parts
* and file classes
* extends peek_mail_header so that this class
* has access to all the header data
* can manipulate messages on the mail server
* using the $this->peek_parent->resource property (resource)
*  
*
*/

include_once('peeker_header.php');

class peeker_body extends peeker_header{

	// define the class that body will use for parts
	public $parts_class = 'peeker_parts';
	
	// the whole raw body string
	public $body_string;
	
	// store the plain and/or html body
	// if not a multipart message
	// if this class is extended
	// by the parts class, these
	// properties could have been filled by
	// data from a multipart message
	// should figure better way for this
	public $PLAIN='';
	public $HTML='';
	
	// the UNIX timestamp when the message 
	// came into this class from mail server
	public $timestamp_pulled;
	
	/**
	* Constructor, connect to parent class
	* 
	*/
	public function __construct(&$peek_parent, $imap_h_obj)
	{
		// pass the resource on to the header class
		parent::__construct($peek_parent, $imap_h_obj);
		$this->log_state('LOADING body class');		
	}
	
	
	/**
	* get all the email parts in the body
	* this causes gmail POP server to archive or delete
	* (if account is set to do archive or delete on POP access)
	*
	*/
	public function get_body()
	{
		// headers are retrieved first so body() is decoupled
		// and messages() in peek class might have deleted this message
		// but it is still in the object tree, check if it has been marked
		if ($this->get_mark_delete())
		{
			return FALSE;
		}
		else
		{
			// NOTE: calling this function removes message from 
			// gmail's POP3 INBOX - not by deleting it, but making 
			// it effectively invisible (depending on gmail account's POP3 settings)
			$this->log_state('Fetching structure for email #'.$this->Msgno);			
			$structure = @imap_fetchstructure($this->peek_parent->resource, $this->Msgno);
			
			// make sure $structure is not null - can happen if passed MsgNo 0
			// log state
			// TODO: build error handling, exception
			if ($structure===NULL) $this->log_state('get_body() method $structure is NULL. MsgNo is: '.(int)$this->MsgNo);
			
			// check for mail server errors here to clear the 
			// error stack and prevent it from posting
			// PHP errors about badly formatted emails
			// should probably store the errors with the email in a db
			$this->_check_imap_errors('imap_fetchstructure');
			
			// pull out the raw email body here for 
			// storage/export potential eg allowing mbox export
			$this->log_state('Getting body for email #'.$this->Msgno);			
			$this->body_string = @imap_body($this->peek_parent->resource,$this->Msgno);
			$this->_check_imap_errors('imap_body');

			// see if it is a multipart messsage
			// fill $this->parts_array with the parts
			// could handle both these cases in the extract_parts function
			if (isset($structure->parts) && count($structure->parts))
			{
				// extract every part of the email into the parts_array var
				// this is a custom array with objects to help unify what we need from the parts
				// extract this part of email, stores the data in properties
				// recurses if necessary to get all the parts into the array
				// this is a little weird here since the method is in a sub class
				// and you have to make sure the class is loaded before extracting
				if (class_exists($this->parts_class))
				{
					$this->extract_parts($structure->parts);
				}
				else
				{
					$this->log_state(
					'No parts class defined in body. imap_fetchstructure() parsing ( method extract_parts() ) failed.');
				}
			} 
			else 
			{ 
				// not a multipart message
				// get the body of message
				// decode if quoted-printable or base64
				if ($structure->encoding==3) 
				{
					$body=base64_decode($this->body_string);
				}
				elseif ($structure->encoding==4)
				{
					$body=quoted_printable_decode($this->body_string);
				}
				else
				{
					$body = $this->body_string;
				}
				// if this is a PLAIN or HTML part it will
				// be written to the respective property
				// create a var for $this->PLAIN or $this->HTML
				$sub_type = strtoupper($structure->subtype);
				if ($sub_type === 'PLAIN')
				{
					$this->PLAIN = $body;
					// see comment below in the HTML part
					// uncomment this to convert all PLAIN parts to utf8
					if (0) $this->PLAIN = $this->peek_parent->decode_mime($this->PLAIN);
				}
				
				if ($sub_type === 'HTML')
				{
					$this->HTML = $body;
					// DEC 20101210 turn off this line until needed
					// deals with encoded HTML iso-8859-1 that needs 
					// to get inserted as UTF-8 into db but insert fails
					// this should fix it, insert only inserts HTML 
					// up to encoded char and then silently drops the rest
					// uncomment this to convert all HTML parts to utf8
					if (0) $this->HTML = $this->peek_parent->decode_mime($this->HTML);
				}			
			}
			// parts_array filled by peek_mail_parts class
			
			// represent internal date as UNIX timestamp
			// this is actually the timestamp of 
			// when the message was put into this class
			// rather than "received" (which should better
			// be the datestamp for when the message
			// was accepted to the receiving SMTP server)
			$this->timestamp_pulled = time();
			
			// return TRUE to allow this to function as a 
			// kind of default detector if needed
			return TRUE;
		}
		
	}
	
	
	/**
	* get the body part (raw text undecoded)
	*
	*/
	public function get_body_string() 
	{ 
		return $this->body_string; 
	}
	
	/**
	* get the PLAIN part (text-only)
	*
	*/
	public function get_plain() 
	{ 
		return $this->PLAIN; 
	}
	
	/**
	* get the HTML part
	* or if there is a rewritten part, send that
	*
	*/
	public function get_html() 
	{
		return $this->HTML; 
	}
	
	/**
	* get the HTML part
	* or if there is a rewritten part, send that
	*
	*/
	public function get_html_filtered()
	{
		$html = (isset($this->HTML_rewritten))?$this->HTML_rewritten:$this->HTML;
		return $html;
	}
	
	/**
	* get the date pulled timestamp
	*
	*/
	public function get_timestamp_pulled() 
	{ 
		return $this->timestamp_pulled; 
	}
	
	/**
	* get the date pulled stamp
	* converts internal timestamp 
	* to Y-m-d H:i:s mysql datetime string
	*
	*/
	public function get_date_pulled() 
	{ 
		return date('Y-m-d H:i:s', $this->timestamp_pulled); 
	}

	// ------- detectors - return boolean ------- //
	
	/**
	* true if pattern matches the PLAIN part (text-only)
	*
	*
	*/
	public function preg_match_PLAIN($pattern)
	{
		return (bool)preg_match($pattern,$this->PLAIN);
	}
	
	/**
	* true if pattern matches the HTML part
	*
	*
	*/
	public function preg_match_HTML($pattern)
	{
		return (bool)preg_match($pattern,$this->HTML);
	}
	
	/**
	* true if PLAIN part but not HTML
	*
	*
	*/
	public function has_PLAIN_not_HTML()
	{
		return $this->PLAIN != '' && $this->HTML == '';
	}
	
	
	/**
	* true if string is in from address
	* and other conditions regarding 
	* PLAIN and HTML message parts fit
	* test if we need to fix stupid people's email that they try to send as HTML
	* but without the proper MIME types and boundaries specified
	* bascially, a brute force check on the text to see if it "starts"
	* with the tag <html> (which is how a rudimentary html doc can start)
	* and also make sure that we don't overwrite an existing HTML property
	* if it exists
	* NOTE: some HTML comes in through the body 
	* with =3D style MIME encoded equals chars, etc...
	* need to look into fixing those too
	*
	*/
	public function fix_MIME_from_sender($from_str)
	{
		// use a detector that is in the parent class
		if ($this->in_from($from_str))
		{
			if ( $this->PLAIN !== '' )
			{
				if (strpos($this->PLAIN,'<html>')<25)
				{
					if ($this->HTML ==='' )
					{
						return TRUE;
					}
				}
			}
		}
		return FALSE;
	}
	
	//------- callbacks -------//
	
	/**
	* takes the PLAIN property
	* and stuffs the data into the
	* HTML property - 
	* can be used to fix badly written 
	* emails and also force all emails 
	* to be HTML
	*/
	public function put_PLAIN_into_HTML()
	{
		// see loreal email for example
		// this should be handled in a
		// better way, perhaps known defects in email
		// could be tracked in a table and fixes
		// applied before the data is stored.
		// also force quoted printable decoding
		// NOTE: shouldn't have to do this decoding...
		// is it vestigal behavior?
		// in case the message is encoded
		$this->HTML = quoted_printable_decode($this->PLAIN);
	}

	/**
	* wrap html and body tags 
	* around HTML part
	* this lets other functions
	* deal with tagged html
	*
	*/
	public function wrap_HTML_with_HTML_tags()
	{
		$this->HTML = '<html><body>'.$this->HTML.'</body></html>';
	}
}
// EOF
