Peeker - A framework for building automated email applications.
================
Please read the documentation here http://sophistry.github.com/peeker and in the **docs** directory

Peeker is a wrapper around the IMAP/POP3 extension available in PHP. It helps you avoid learning all the strange things about talking to an email server and concentrate on getting email as objects and properly-decoded attachments onto your filesystem.

Start with the *Quick Start* in peeker_quickstart.html.

Basic usage: (gmail IMAP, make sure IMAP is enabled in your gmail account)
	
	// class files in peeker directory
	// change these lines
	// the path to the peeker.php class file
	include('path/to/peeker.php');
	// this can also be a Google Apps email account
	$config['login']='your_gmail_address@gmail.com';
	$config['pass']='your_gmail_password';

	// do not change these lines
	// this should not change unless you are having problems
	$config['host']='imap.gmail.com';
	$config['port']='993';
	$config['service_flags'] = '/imap/ssl/novalidate-cert';

	// you can definitely change these lines!
	// because your application code goes here
	$peeker = new peeker($config);
	$cnt = $peeker->get_message_count();
	echo $cnt.' message waiting';

	// EOF

Advanced PHP developers only: Peeker also has a declarative Event programming architecture (Detector-Callback circuit) and "Traits-like" method layering (a simple Dependency Injection - just drop in a custom class and request the new methods be added to the email objects).