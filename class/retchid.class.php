<?php

require_once("../include/Constants.php");
class Retchid{

	private $DebugLine = 0;
	private $DebugHistory = array();
	private $SQLExecutionErrorCount = 0;
	private $SQLConnectionErrorCount = 0;
	private $SQLLastExecutionError = null;
	private $SQLLastConnectionError = null;
	public $DatabaseConnection = null;

	public function __construct(){
		foreach(get_included_files() as $IncludedFile){
			if(preg_match('~Constants.php~', $IncludedFile)){
				$ConstantsLoaded = true;
			}
		}
		if(!isset($ConstantsLoaded)){
			print("Warning: constant file not loaded. Execution stopped.\n");
		}
	}


	// Error Handling

	private function LogDebugHistory($DebugInformation){
		if(count($this->DebugHistory) <= 50){
			$this->DebugHistory[] = $DebugInformation;
		}else{
			print("Debug history full, please clear or create higher limit.");
			return false;
		}
	}

	
	public function OpenDebugStream($DebugInformation){
		if(DEBUG_VARIABLES['JS'] && is_string($DebugInformation)){
			echo JSCRIPT['OPEN'] . JSCRIPT['CONS'] . "Line" . $this->DebugLine . ":" . $DebugInformation . JSCRIPT['CFUN'] . JSCRIPT['CLOSE'];
		}
		if(DEBUG_VARIABLES['PHP']){
			if(is_array($DebugInformation)){
				print_r("Line " . $this->DebugLine . ": " . $DebugInformation . "\n");
			}else{
				print("Line " . $this->DebugLine . ": " . $DebugInformation . "\n");
			}
		}
		if(is_string($DebugInformation)){
			$this->LogDebugHistory($DebugInformation); 
		}
		$this->DebugLine++;
		return true;
	}


	// Database Connection Handling

	public function OpenDatabaseConnection(){
		foreach (SQL_CONNECTION_ARRAY as $KeyName => $Data){
			if(empty($Data)){
				$this->OpenDebugStream('$KeyName not set, please check includes/constants.php');
				return false;
			}
		}
		$DSN = "mysql:host=". SQL_CONNECTION_ARRAY['HOST'] .";dbname=" . SQL_CONNECTION_ARRAY['DATABASE'];
		try{
			$this->DatabaseConnection = new PDO($DSN,SQL_CONNECTION_ARRAY['USERNAME'],SQL_CONNECTION_ARRAY['PASSWORD']);
			$this->DatabaseConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->OpenDebugStream('Connected to: ' . SQL_CONNECTION_ARRAY['DATABASE'] . " on " . SQL_CONNECTION_ARRAY['HOST'] . "\n");
			return $this->DatabaseConnection;
		}catch(PDOException $MySQLError){
			$this->OpenDebugStream('PHP Encountered an error connecting.\n');
			$this->SQLConnectionErrorCount++;
			$this->SQLLastConnectionError = $MySQLError;
		}		

	}

	public function CloseDatabaseConnection(){
		$listQuery = 'SHOW PROCESSLIST -- ' . uniqid( 'pdo_mysql_close ', 1);
	   	$threadList  = $this->DatabaseConnection->query( $listQuery )->fetchAll( PDO::FETCH_ASSOC );
	   	foreach( $threadList as $uniqueThread ){
	        if ( $uniqueThread['Info'] === $listQuery ){
	           	$this->DatabaseConnection->query('KILL ' . $uniqueThread['Id'] );
	           	unset( $this->DatabaseConnection );
	           	$this->OpenDebugStream("Sucessfully Disconnected from " . SQL_CONNECTION_ARRAY['DATABASE'] . " on " . SQL_CONNECTION_ARRAY['HOST']);
	       		return true;
	        }
	    }
	    return false;

	}


	public function RecurseSQL($SQLQuery,$SQLValues){
		if(!is_array($SQLValues)){
			$this->OpenDebugStream("Could not perform RecureSQL(), SQLValues must be array.");
			return false;
		}else{
			$SQLQueries = $SQLQuery;
			if(is_string($SQLQuery)){
				$SQLQueries = array(
					0 => $SQLQuery
					);
			}
			$ValueKey = 0;
			foreach($SQLQueries as $QueryKey => $QueryString){
				$this->OpenDatabaseConnection();
				if(preg_match('~(INSERT)|(REPLACE)|(UPDATE)~',$QueryString)){
					$ExecutionType = 1;
				}elseif(preg_match('~(SELECT)|(DELETE)~',$QueryString)){
					$ExecutionType = 2;
				}
				preg_match_all('~:[[:alnum:]]+~', $QueryString, $Parameters);
				print_r($Parameters);
				try{
					$PreparedQuery = $this->DatabaseConnection->prepare($QueryString);
					foreach($Parameters[0] as $BindMe){
						foreach($SQLValues[$ValueKey] as $Key => $Data ){
							$PreparedQuery->bindParam($BindMe,$SQLValues[$ValueKey][$Key]);
						}
					}
					$PreparedQuery->execute();
					$Return[$ValueKey]["ROWCOUNT"] = $PreparedQuery->rowCount();
					if($ExecutionType == 2){
						$OperationResults = $PreparedQuery->fetchAll(PDO::FETCH_ASSOC);
						$Return[$ValueKey]["RESULTS"] = $OperationResults[0];
					}
				}catch(Exception $SQLOperationError){
					$this->OpenDebugStream($SQLOperationError);
					return false;
				}
				// $this->CloseDatabaseConnection();
				// if(count($SQLValues)>1){
					$ValueKey++;
				// }
			}
			return $Return;
		}
	}



	// Password Related Methods.

	public function ConvertPassword($PlainTextPassword, $Salt){
		if(empty($Salt)){
			$costStrength = 10;
			$generatedSalt = strtr(base64_encode(mcrypt_create_iv(16, MCRYPT_DEV_URANDOM)), '+', '.');
			$Salt = sprintf("$2a$%02d$",$costStrength) . $generatedSalt;
		}
		$saltedPassword = crypt($PlainTextPassword,$Salt);
		return array(
			0 => $Salt,
			1 => $saltedPassword
			);
	}


	// for some reason, will not update incorrect login time based of unique id
	public function CheckPassword ( $PlainTextPassword, $Username ){
		if(preg_match('/(@[[:alnum:]]+.+[[:alpha:]]+)/',$Username)){
			$Method = "EMAIL";
		}else{ $Method = "USERNAME"; }
		$SQLQueryArray =  array(
			0 => "SELECT * FROM Users WHERE ($Method) = (:$Method" . "1)"
			);
		$SQLValueArray = array(
			array( $Method => $Username )
			);
		$SQLOperationResults = $this->RecurseSQL($SQLQueryArray,$SQLValueArray);
		$PasswordResults = $this->ConvertPassword($PlainTextPassword,$SQLOperationResults[0]["RESULTS"]["SALT"]);
		print_r($SQLOperationResults);
		$LastIncorrectLoginTime = strtotime($SQLOperationResults[0]["RESULTS"]["LASTINLOGTIME"]);
		$CurrentTime = strtotime(date("Y-m-d H:i:s"));
		$TimeDifference = round(abs($CurrentTime - $LastIncorrectLoginTime) / 60,0);
		print($TimeDifference);
		$IncorrectLoginAttempts = $SQLOperationResults[0]["RESULTS"]["INLOGCOUNT"];
		if($PasswordResults[1] === $SQLOperationResults[0]["RESULTS"]["PASSWORD"]){
			if($IncorrectLoginAttempts > 5 && $TimeDifference < 30){
				$this->OpenDebugStream("User account disabled for 30 minutes (5 consecutive incorrect logins).");
				return false;
			}
			//reset login counter and last incorrect login time
			return true;
		}else{
			$UserUniqueID = $SQLOperationResults[0]["RESULTS"]["UNIQUEID"];
			$UpdateIncorrectLoginCountQuery = 'UPDATE Users SET INLOGCOUNT = INLOGCOUNT + 1 WHERE (UNIQUEID) = (:USERID)';
			$UpdateIncorrectLoginCountValues = array(
					array("UNIQUEID" => (string)$UserUniqueID)
				);
			$this->RecurseSQL($UpdateIncorrectLoginCountQuery, $UpdateIncorrectLoginCountValues);
			return false;
		}
	}


	// User Creation Methods 

	private function generateUserID(){
		$binaryBlob = openssl_random_pseudo_bytes(99);
		$hexBlob = bin2hex($binaryBlob);
		preg_match_all('/[0-9]/',$hexBlob, $blobArray);
		$blobLength = count($blobArray, COUNT_RECURSIVE);
		if($blobLength == 100 || $blobLength > 100){
			$blobString = implode("",$blobArray[0]);
			$fixedLength = str_split($blobString,100);
			if($this->CheckUserExistence($fixedLength[0])){
				$this->generateUserID();
			}
			return $fixedLength[0];
		}
	}

	
	private function CheckUserExistence($UserValidation){
		if(preg_match('/[0-9]+/', $UserValidation)){
			$Method = "UNIQUEID";
		}elseif(preg_match('/(@[[:alnum:]]+.+[[:alpha:]]+)/', $UserValidation)){
			$Method = "EMAIL";
		}else{
			$Method = "USERNAME";
		}
		$SQLQueryArray = array(
			0 => "SELECT * FROM Users WHERE ($Method) = (:USERVALIDATION)"
			);
		$SQLQueryValues = array(
			array( $Method => $UserValidation)
			);
		$SQLOperationResults = $this->RecurseSQL($SQLQueryArray,$SQLQueryValues);
		if(isset($SQLOperationResults[0])){
			//user exists
			$this->OpenDebugStream("This user does in fact, exist.");
			return true;
		}
		return false;
	}
	
	public function CreateNewUser($UserCreationArray){
		if($this->CheckUserExistence((string)$UserCreationArray["EMAIL"])){
			// user already exists with said email, will also be done with jscript on frontend.
			return false;
		}elseif($this->CheckUserExistence((string)$UserCreationArray["USERNAME"])){
			// user already exists with said username, will also be done with jscript on frontend
			return false;
		}
		$UserCreationQueries = array(
			"Users" => "INSERT INTO Users (USERNAME,EMAIL,PASSWORD,SALT,UNIQUEID) VALUES (:UC1,:UC2,:UC3,:UC4,:UC5)",
			"UserInformation" => "INSERT INTO UserInformation (FIRST,LAST,DOB,GENDER,UNIQUEID) VALUES (:UC1,:UC2,:UC3,:UC4,:UC5)"
			);
		$UsersSecurityInformation = $this->ConvertPassword($UserCreationArray["PASSWORD"],"");
		$UserID = $this->generateUserID();
		$PassThroughArray = array(
				0 => array(
				"USERNAME" => $UserCreationArray["USERNAME"],
				"EMAIL" => $UserCreationArray["EMAIL"],
				"PASSWORD" => $UsersSecurityInformation[1],
				"SALT" => $UsersSecurityInformation[0],
				"UNIQUEID" => $UserID
				),
				1 => array(
					"FIRST" => $UserCreationArray["FIRSTNAME"],
					"LAST" => $UserCreationArray["LASTNAME"],
					"DOB" => $UserCreationArray["DOB"],
					"GENDER" => $UserCreationArray["GENDER"],
					"UNIQUEID" => $UserID
				)
			);
		$ExecuteCreation = $this->RecurseSQL($UserCreationQueries,$PassThroughArray);
		if(isset($ExecuteCreation[0])){
			//user created....
			return true;
		}
		//user not created.... probably a server error or something
		return false;
	}

	// User Information Modification Methods






}




?>
