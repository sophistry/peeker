<?php

/**
*
* An Application class
* communicate with CI stack
*
*/
class peeker_ci_methods
{
	// hold the codeigniter object
	public $CI
	
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
      
    //---------- detectors ---------//
	
	//---------- detector-callback short circuit ---------//

	/**
	* appeal to list users to allow stranger's message in
	* this is usually called right after a check of approved senders
	* this is in CI lib so we can talk to CI db stack
	*/
	public function unknown_sender_appeal($list_name)
	{
		// $list_name should not be the mailing address, but
		
		$this->CI =& get_instance();			
		// insert or update this contact
		// since we are going to send an appeal
		// contact type becomes 'pending' = 3
		$this->CI->load->model('Contacts_model');
		$this->CI->load->model('Lists_model');
		$this->CI->load->model('Contacts_Lists_model');	
		// add the contact as an email type record, 
		// get contact id
		$address_from =$this->that->get_address_from();
		$personal_from =$this->that->get_personal_from();
		$contact_id = $this->CI->Contacts_model->add_contact_email($address_from, $personal_from);
		//pe($contact_id);
		// get list id
		$list_id = $this->CI->Lists_model->get_id_from_name($list_name);
		//pe($list_id);
		// set the join record to pending state
		// returns TRUE if the record exists and 
		// the type was changed by this call 
		// (e.g., from no record to 3 or from 0 to 3)
		$type_changed = $this->CI->Contacts_Lists_model->insert_or_change_type($contact_id, $list_id, '0', '3');
		// if the type changed, that means we haven't seen this sender before
		// send out the appeal
		if ($type_changed)
		{
			// prepare the email to send for the appeal
			$this->CI->load->helper('url');
			// appeal URL should be a parameter or property
			$is_not_html = TRUE; // not HTML
			$file_array = array(); // no attachments
			// listname should not be hardcoded
			$link_back = site_url().'em/appeal/'.urlencode($list_name) .'/'. urlencode($address_from);
			// this should be a view
			$body = "$address_from sent a message to the $list_name list. \n\n They are not an approved sender. \n\n Do you recognize the email address? \n\n Click this link to add this person to the approved sender list. \n\n $link_back \n\n Note: By clicking the link you will let their message (and any future messages they send) through to everyone on the list. But, even though they will be able to send messages to the list membership, they will not receive any messages from $list_name." ;
			//pe($body);
			$this->that->log_state('Sending allow? appeal with link_back.');
			
			/*
			_sendemail($list_name, $this->that->get_resend_to_array(),array(),
			$list_name,
			'Advanced List',
			'[Assist - LISTNAME]: '.$this->that->get_subject(),
			$body, $is_not_html, $file_array);
			*/

		}
	}
	
	//---------- callbacks ---------//
	
	/*
	* remove any address that has a tag
	*
	*/
	public function remove_registered_from_resend_to($tag='')
	{
		// because of the way the resend_to stripping works
		// it was easier just to load up two independent detectors
		// than to try to fix the remove_from_resend_to_array() method
		// should get these from a db table after searching on tag
		$adds = array('d+nopolitics@polysense.com', 'd+remove@polysense.com');
		foreach ($adds as $add)
		{
			// use the layered method in the listserv code
			// this shows that the layers can work together
			$this->that->remove_from_resend_to($add);
		}
	}
	
	
	/**
	* taken/modified from part class: 
	* save the HTML part to the view folder
	* this will overwrite any file
	* returns TRUE if file was created
	*/
	public function save_HTML_as_view_file($view_file='HTML.html')
	{
		// put the file into the views dir
		// make sure the directory exists
		$dir = APPPATH . 'views/emails/' . $this->that->get_fingerprint() . '/';
		$dir = $this->that->_make_dir($dir);
		$fn = $dir . $view_file;
		//pe($fn);
		
		$template = ($this->that->HTML === '') ? $this->that->PLAIN : $this->that->HTML;
		// make sure we don't write a blank file
		if (trim($template) !== '') $this->that->_save_file($fn, $template);
		// call the parent function to do the "real" thing
		// and write the file to the attachments dir
		//parent::save_HTML($file_name);
		// should determine if the file was written
		// and if not, return FALSE
		// this will report the file is there unless
		// the file is deleted after use
		return is_file($fn);
	}
	
	
	
	/**
	* treat the email message as if it were
	* a view file by loading the HTML from
	* the file
	* HTML must have been saved to file
	* for this to work!
	* loads up a bunch of standard vars that
	* email messages can use to get rewritten
	* Also, you can use this->load->vars() to get
	* data into the view here
	*
	*/
	public function load_email_as_view($view_file='HTML.html')
	{
		// connect to the CI stack
		$this->CI =& get_instance();
		$this->CI->load->library('parser');
		
		// construct the whole filename - for view file
		// should store this in class var
		$view_file = 'emails/' . $this->that->get_fingerprint() . '/' .$view_file;
		$data['from'] = $this->that->get_address_from();
		//$data['to'] = $this->that->get_address_to();
		
		// build up an array that the view can understand
		$em_arr = array();
		$arr = $this->that->get_resend_to_array();
		foreach($arr as $em)
		{
			$em_arr[] = array('email'=>$em);
		}
		
		$data['subscribers'] = $em_arr;
		// have to link up the email address with the birthdate
		//$data['my_birthday'] = $this->get_address_from();
		//$this->CI->load->vars($data);
		//$rendered_view = $this->CI->load->view($view_file,$data,TRUE);
		$rendered_view = $this->CI->parser->parse($view_file,$data,TRUE);
		//p('exiting inside load_email_as_view() function...');pe($rendered_view);
	}
	
	

}

//EOF