<?php

class peeker_connect {

	// POP3 OR IMAP
	public $server; // host and port combined into domain.com:port format
	public $host; // fully-qualified domain name
	public $port; // imap 143,993(secure) or pop3 110,995(secure) port
	
	public $login;
	public $pass;
	
	public $service_flags;
	public $mailbox;
	public $server_spec_string; // holds full connect spec, once it's all together
	
	public $resource;
	public $state_array = array();
	
	public $connected = FALSE;
	public $message_count = NULL;
	public $mailboxes;
	
	// message_waiting is TRUE or FALSE 
	// because some POP servers return a 
	// "Mailbox is empty" error on initialize() 
	// this tells us there are zero messages, 
	// but if there are messages, it doesn't 
	// tell how many. so we just store the 
	// message_waiting boolean as the fastest 
	// way to know if there are any messages 
	public $message_waiting = FALSE;
	
	/**
	* Constructor
	* if no init parameter sent nothing happens in constructor
	* user then has to call initialize() and pass the parameters
	* 
	*/
	public function __construct($init_array = NULL)
	{
		if ( ! is_null($init_array) ) $this->initialize($init_array);	
	}
	
	/**
	* Set the server, login, pass, service_flags, and mailbox
	* 
	*/
	public function initialize($init_array)
	{	
		// connect to the specified account
		// these array items need to be the 
		// same names as the config items OR
		// the db table fields that store the account info
		$this->host = $init_array['host'];
		$this->port = $init_array['port'];
		// get the port and server combined
		$this->server = $this->host .':'. $this->port;
		
		$this->login = $init_array['login'];
		$this->pass = $init_array['pass'];
		
		$this->service_flags = $init_array['service_flags'];
		// default to INBOX mailbox since POP3 doesn't require it
		// and IMAP always has an INBOX
		$this->mailbox = (isset($init_array['mailbox'])) ? $init_array['mailbox'] : 'INBOX';
	
		// grab the resource returned by imap_open()
		// concatenate the IMAP connect spec string
		// expects server, flags, and mailbox to be set already
		$this->server_spec_string = $this->_generate_server_spec_string();
		// suppress warning with @ so we can handle it internally
		// which is the way that imap_errors() works
		$this->resource = @imap_open($this->server_spec_string, $this->login, $this->pass);
		
		// check for errors in the connection
		// calling imap_errors() clears all errors in the stack
		$err = imap_errors();
		
		// clear the message count in case this is a re-initialization
		$this->message_count = NULL;
		
		if($this->resource)
		{
			$this->log_state('Connected to: '.$this->server_spec_string);
			// when connection is good but the mailbox is empty, 
			// the php imap c-libs report POP server empty mailbox as
			// "Mailbox is empty" (as a PHP error in the imap_errors() stack)
			// in case we are using IMAP, we also check get_message_count()
			if($err[0] === 'Mailbox is empty' OR $this->get_message_count() === 0)
			{
				// we now know there are zero messages
				$this->message_waiting = FALSE;
				$this->log_state('Mailbox is empty.');
			}
			else
			{
				// there is at least one message
				$this->message_waiting = TRUE;
				$this->log_state('At least one message available.');
			}
			
			$this->connected = TRUE;
		}
		else 
		{
			// determine the specific reason for rejection/no connection
			$this->_handle_rejection($err);
			$this->log_state('Not connected. No email resource at: '. $this->server_spec_string);
			$this->connected = FALSE;
		}
		
	}
	
	/**
	* Concatenate the IMAP connect spec string
	* Expects server, flags, and mailbox to be set already
	*
	*/
	private function _generate_server_spec_string()
	{
		return '{'. $this->server . $this->service_flags.'}'.$this->mailbox;
	}
	
	/**
	* Get count of messages returned by latest
	* call to the imap_num_msg() function that
	* was stored in the property 
	* it does not call imap_num_msg()
	* method unless needed and available
	* use $force_recount = TRUE to recount
	*
	* Call this with $force_recount = TRUE to get most 
	* current message_count and store it in the 
	* message_count property
	*
	* This is necessary to deal with different
	* ways that each mailserver reports the
	* presence of messages waiting 
	* (i.e., some don't so you have to count them)
	*/
	public function get_message_count($force_recount=FALSE)
	{
		if (is_null($this->message_count) OR $force_recount)
		{
			if(is_resource($this->resource))
			{
				$this->message_count = imap_num_msg($this->resource);
			}
			else
			{
				$this->log_state('Not connected. Cannot get message count.');		
			}
		}
		return $this->message_count;
	}
	
	/**
	*
	* Get an array of mailboxes - IMAP
	* returns array of server specification 
	* strings for the mailboxes
	*/
	public function get_mailboxes()
	{
		// star pattern says get all mailboxes
		// imap_list() gets array of full mailbox names
		// strip the mailbox off of the server_spec_string
		$sss_no_mb = '{'.$this->server . $this->service_flags.'}';
		$this->mailboxes = imap_list($this->resource,$sss_no_mb,'*');
		return $this->mailboxes;
	}
	
	/**
	* Reopens the IMAP connection pointing at a different mailbox
	* Accepts name or full server spec string 
	* (like the one that is returned by imap_list())
	*
	*/
	public function change_to_mailbox($mailbox_name_or_full_server_spec_string)
	{
		// should check if it is a valid mailbox
		// but for now, just see if it starts with curly bracket
		if (strncmp('{',$mailbox_name_or_full_server_spec_string,1)===0)
		{
			// it is (probably) a spec string
			// but, maybe mailbox names can start with {?
			// set the local mailbox property by splitting on curly brackets
			// use strtok twice to get the end of the string because it is surper fast
			strtok($mailbox_name_or_full_server_spec_string,'}');
			$this->mailbox = strtok($mailbox_name_or_full_server_spec_string);
			// reopen the connection using the new server spec string
			$bool = imap_reopen($this->resource, $mailbox_name_or_full_server_spec_string);			
		}
		else
		{
			$this->mailbox = $mailbox_name_or_full_server_spec_string;
			$new_sss_with_mb = $this->_generate_server_spec_string();
			$bool = imap_reopen($this->resource, $new_sss_with_mb);
		}
	}
	
	
	/**
	* Handle the various reasons for rejection
	* bad username, password, host, POP not enabled
	* NOTE: this is pretty brittle because it does a lot
	* of string matching and those could easily change
	* on new imap lib or PHP version or mail server change
	* It is also far from complete.
	* NOTE:
	* the underlying pop lib allows 3 login attempts, in PHP >= 5.2.0 it can be changed
	* but, that will change some of the rejection handler code.
	*/
	private function _handle_rejection($err)
	{
		$reject_reason = 'Unknown reason for rejection.';
		if (isset($err[0])) 
		{
			// handle the server responses, wrong hostname
			if ($err[0] === 'No such host as ' . $this->host) 
			{
				$reject_reason = $err[0];
			}
			// is it a username or a password wrong?
			elseif ($err[0] === '[AUTH] Username and password not accepted.') 
			{
				$reject_reason = 'Username seems OK. Password not accepted.';
			}
			elseif ($err[0] === 'Password failed for ' . $this->login) 
			{	
				$reject_reason = 'Username and/or Password not accepted.';
			}
			elseif ($err[0] === '[SYS/PERM] Your account is not enabled for POP access. Please visit your Gmail settings page and enable your account for POP access.') 
			{
				$reject_reason = 'Username and Password OK. No POP access on this account: '. $this->login;
			}
			elseif ($err[0] === "Can't open mailbox ". $this->server_spec_string . ": invalid remote specification") 
			{
				$reject_reason = "Can't open mailbox ". $this->server_spec_string;
			}
			elseif (strpos('Certificate failure', $err[0]) !== FALSE) 
			{
				$reject_reason = 'Using Gmail or other SSL login? Try adding novalidate-cert to the service flags.';
			}
			elseif ($err[0] === "login allowed only every 15 minutes") 
			{
				$reject_reason = 'Using Live.com or Hotmail? One POP login each 15 mins. Login after: ' . date('H:i:s',time()+(15*60));
			}
			else // if we don't have a slot for it, just stuff the error in the log
			{
				$reject_reason = $err[0];
			}
			
		}
		// special case where gmail doesn't recognize the username
		if (isset($err[1])) 
		{
			if ($err[1] === 'POP3 connection broken in response') 
			{
				$reject_reason = 'Username not accepted: '. $this->login;
			}
		}
		
		$this->log_state($reject_reason);
	}
	
	
	/**
	* Log the states the connection has gone through
	* 
	*/
	public function log_state($str)
	{
		$this->state_array[] = $str;
	}
	
	
	/**
	* Get the array of states the connection has gone through
	* 
	*/
	public function trace()
	{
		return $this->state_array;
	}
	
	/**
	* Get the login name
	* 
	*/
	public function get_login()
	{
		return $this->login;
	}
	
	
	/**
	* See if the class connected to the server
	* 
	*/
	public function is_connected()
	{
		return $this->connected;
	}
	
	
	/**
	* See if there are messages waiting
	* 
	*/
	public function message_waiting()
	{
		return $this->message_waiting;
	}
	
	
	/**
	* Wrapper to close the IMAP connection
	* Returns TRUE if closed with no errors
	* FALSE if imap_close() fails or if
	* there is no resource
	*
	*/ 
	public function close()
	{
		if (is_resource($this->resource))
		{
			$closed = imap_close($this->resource);
			if ($closed)
			{
				$this->log_state('Connection closed OK.');
			}
			else
			{
				$this->log_state('Mail resource OK, but connection could not be closed.');				
			}
		}
		else
		{
			$closed = FALSE;
			$this->log_state('Connection could not be closed. No POP resource.');
		}
		$this->connected = FALSE;
		return $closed;
	}

}
// EOF