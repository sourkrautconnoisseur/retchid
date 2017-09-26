<?php

$GetIncludedFiles = get_included_files();
if(!preg_match("~Constants.php~",$GetIncludedFiles)){
	// THROW ERROR
}


class retchid{
	
	public function __construct{
	}
	

}

?>
