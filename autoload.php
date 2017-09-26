<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-type: text/html; charset=utf-8');


$RootDirectory = dirname(__FILE__);
$SearchDirectories = array(
0 => "include",
1 => "class"
);

foreach($SearchDirectories as $DirectoryToInclude){
	$ScannedDirectory = scandir($RootDirectory/$DirectoryToInclude");
	foreach($ScannedDirectory as $FileKey => $FileName){
		if($FileKey > 1){
			require_once("$RootDirectory/$DirectoryToInclude/$FileName");
		}
	}
}

    
?>
