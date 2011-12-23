<?php


// Base class for any objects that you want 
// to be able to handle additional
// methods and properties added at runtime
// This is like PHP6 Traits
// https://wiki.php.net/rfc/horizontalreuse

class peeker_layers
{
    private $layered_methods = array();

    public function layer_methods($layer_methods_object)
    {
    	// call and register each object
        $layer_methods_object->register($this);

        // list the new methods to layer
        $methods   = get_class_methods(get_class($layer_methods_object));

		// overwrite any previous methods
		// bring in objects by reference
        foreach($methods as $method_name)
        {
            $this->layered_methods[$method_name] = &$layer_methods_object;
        }
        // return TRUE so it can be used in a detector circuit
        return TRUE;
    }

    public function __call($method, $args)
    {
        // make sure the function exists
        if(array_key_exists($method, $this->layered_methods))
        {
            return call_user_func_array(array($this->layered_methods[$method], $method), $args);
        }
        throw new Exception ('Call to undefined method/class function: ' . $method);
    }
}

// NOTE:
/*
* layered method classes that implement this method layering
* must have the register() function and $that property that holds
* the object where methods will be added
* To access the object use $this->that->some_method_or_property
*/

/* 
protected $that = null;

public function register($that)
{
	$this->that = $that;
}
*/

// EOF peeker_layers.php