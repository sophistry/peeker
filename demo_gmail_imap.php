<?php
// change these 3 lines
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