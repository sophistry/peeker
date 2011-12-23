<?php

/**
*
* Makes email body parts into objects
* so they can be acted on by other classes
* access data, execute detectors, etc...
* class vars are mapped to the parts
*
*/

class peeker_file{
	
	var $filename;
	var $string;
	var $encoding;
	var $part_no;
	var $cid;
	var $disposition;
	var $bytes;
	var $type;
	var $subtype;
	
	/**
	* Constructor
	* 
	*/
	function peeker_file($assoc_array)
	{
		// set the properties based on the incoming array
		foreach ($assoc_array as $key => $value)
		{
			$this->$key = $value;
		}
	}
	
	/**
	* access the filename
	* 
	*/
	function get_filename()
	{
		return $this->filename;
	}
	
	/**
	* access the data string
	* 
	*/
	function get_string()
	{
		return $this->string;
	}
	
	/**
	* access
	* 
	*/
	function get_encoding()
	{
		return $this->encoding;
	}
	
	/**
	* access
	* 
	*/
	function get_part_no()
	{
		return $this->part_no;
	}
	
	/**
	* access
	* 
	*/
	function get_cid()
	{
		return $this->cid;
	}
	
	/**
	* access
	* 
	*/
	function get_disposition()
	{
		return $this->disposition;
	}
	
	/**
	* access
	* 
	*/
	function get_bytes()
	{
		return $this->bytes;
	}
	
	/**
	* access
	* 
	*/
	function get_type()
	{
		return $this->type;
	}
	
	/**
	* access
	* standardize on lowercase, shortest names
	* eg. jpg not jpeg
	* could standardize on input...
	* 
	*/
	function get_subtype()
	{
		$st = strtolower($this->subtype);
		// reformat to standardize
		switch ($st)
		{
			case 'jpeg':
				$st = 'jpg';
				break;
		}
		return $st;
	}
	
	// ------- detectors - return boolean ------- //
	/**
	* return true if file has a cid
	* which implies that it is used 
	* in HTML inline
	* 
	*/
	function has_cid()
	{
		return (bool)$this->get_cid();
	}

}

// EOF