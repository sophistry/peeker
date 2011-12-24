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
	
	public $filename;
	public $string;
	public $encoding;
	public $part_no;
	public $cid;
	public $disposition;
	public $bytes;
	public $type;
	public $subtype;
	
	/**
	* Constructor
	* 
	*/
	public function __construct($assoc_array)
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
	public function get_filename()
	{
		return $this->filename;
	}
	
	/**
	* access the data string
	* 
	*/
	public function get_string()
	{
		return $this->string;
	}
	
	/**
	* access
	* 
	*/
	public function get_encoding()
	{
		return $this->encoding;
	}
	
	/**
	* access
	* 
	*/
	public function get_part_no()
	{
		return $this->part_no;
	}
	
	/**
	* access
	* 
	*/
	public function get_cid()
	{
		return $this->cid;
	}
	
	/**
	* access
	* 
	*/
	public function get_disposition()
	{
		return $this->disposition;
	}
	
	/**
	* access
	* 
	*/
	public function get_bytes()
	{
		return $this->bytes;
	}
	
	/**
	* access
	* 
	*/
	public function get_type()
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
	public function get_subtype()
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
	public function has_cid()
	{
		return (bool)$this->get_cid();
	}

}

// EOF