<?php

/**
*
* Manage detector-callback circuits
* 
* Any function that returns a boolean
* can be not'ted by prepending a special
* string to the function name - 
* defaults to 'not__'.
* e.g., empty_property() can be called
* by passing not__empty_property as 
* the detector_method argument.
* This class will strip the special string
* and invert the normal function result.
* We don't have to write invert functions.
*
* TODO: expand to handle arrays better
* implement the call_user_func() version
*
*/

class peeker_detector {

	public $detector_method;
	// XOR this property with 
	// the check() method result
	// to invert the result
	public $invert_detector = FALSE; 
	public $detector_method_arguments;
	public $callback_method;
	public $callback_method_arguments;

	public $detector_set_parent; 
	
	public $active = TRUE;
	
	/**
	* Constructor
	* wrapper to add everything at once
	*/
	public function __construct($dm=NULL, $dma=NULL, $cm=NULL, $cma=NULL, &$detector_set_parent)
	{
		//if($detector_set_parent !== NULL) 
		$this->detector_set_parent =& $detector_set_parent;
		//p($this->detector_set_parent);
		
		if($dm !== NULL) $this->set_detector($dm);
		if($dma !== NULL) $this->set_detector_arguments($dma);
		
		if($cm !== NULL) $this->set_callback($cm);
		if($cma !== NULL) $this->set_callback_arguments($cma);
		
		//$this->detector_set_parent->log_array[] = 'loading detector: '.$dm;
	}
	
	/**
	* set the detector method
	* 
	*/
	public function set_detector($method)
	{
		// magic string on detector 
		// method inverts the boolean
		//p($this);
		if (strpos($method, $this->detector_set_parent->invert_detector_method_string)===0) 
		{
			// this check will be inverted
			$this->invert_detector = TRUE; 
			// trim off the string indicator
			$method = substr($method,$this->detector_set_parent->invert_detector_method_string_length);
		}
		$this->detector_method = $method;
	}
	
	/**
	* get the detector method
	* 
	*/
	public function get_detector()
	{
		return ($this->invert_detector) ? $this->detector_set_parent->invert_detector_method_string.$this->detector_method : $this->detector_method;
	}
	
	/**
	* set the detector method arguments
	* 
	*/
	public function set_detector_arguments(&$array)
	{
		$this->detector_method_arguments = $array;
	}
	
	/**
	* get the detector method arguments
	* 
	*/
	public function get_detector_arguments()
	{
		return $this->detector_method_arguments;
	}
	
	/**
	* set the callback method
	* 
	*/
	public function set_callback($method)
	{
		$this->callback_method = $method;	
	}
	
	/**
	* get the callback method
	* 
	*/
	public function get_callback()
	{
		return $this->callback_method;	
	}
	
	/**
	* set the callback method arguments
	* 
	*/
	public function set_callback_arguments(&$array)
	{
		$this->callback_method_arguments = $array;
	}
	
	/**
	* get the callback method arguments
	* 
	*/
	public function get_callback_arguments()
	{
		return $this->callback_method_arguments;
	}
	
	/**
	* call the detector method
	* to get boolean
	* override to send detector call
	* to an object
	*/
	public function check(&$obj)
	{
		if ( ! $this->active) return FALSE;
		// this line can target any function
		$result = call_user_func_array(array(&$obj, $this->detector_method), array(&$this->detector_method_arguments));
		// XOR works like NOT here, bit 2 inverts bit 1 if bit 2 is TRUE
		// 0 XOR 0 = 0 ... normal
		// 1 XOR 0 = 1 ... normal
		// 0 XOR 1 = 1 ... invert
		// 1 XOR 1 = 0 ... invert
		return $result XOR $this->invert_detector;	
	}
	
	/**
	* call the callback method
	* override to send callback
	* to an object
	*/
	public function trigger(&$obj)
	{
		if ( ! $this->active) return FALSE;
		// this line can target any function	
		$result = call_user_func_array(array(&$obj, $this->callback_method), array(&$this->callback_method_arguments));
		return $result;
	}
	
	
	/**
	* turn it on or off
	* 
	*/
	public function set_active($bool)
	{
		$this->active = $bool;
	}
}

//EOF