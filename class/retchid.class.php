<?php

    /**
    *
    * Core retchid class that handles all main functions of the framework.
    *
    *
    * @category   Retchid
    * @package    RetchidFramework
    * @copyright  Copyright (c) 2017 Scorched Wire Media Group
    * @license    https://www.apache.org/licenses/LICENSE-2.0   Apache 2.0 License
    * @version    Release: @package_version@
    * @link       https://github.com/sourkrautconnoisseur/retchid
    * @since      Class and file available since Release 0.1
    *
    */

	/*
	*
	* todo:
	* 1. Rewrite IterateSQL() to detect when a single value will be used to bind to various parameters in various queries.
	* 2. Turn erroneous if()elseif()else() into ternary operators (keep commented now, try not to break working things)
	* 3. Define Default Private Policies, and update createNewUsers to reflect.
	* 4. Define Remote User Information to be used for debugging, code-tracking, etc.
	* 5. Design User Relationships Hierarchy and Mesh
	* 6. Develop Upvote, Downvote, View Systems and Rating Systems.
	* 7. Implement Captcha and ReCaptcha
	* 8. Create SEO for hybrid pages (static but dynamic)
	* 9. Create File Management System
	* 10. Create Internal Messaging System
	* 11. Create Posting System
	* 12. Implement PGP and GPG keys into Messaging System
	* 13. Implement PKI for internal messaging system
	* 14. One Time Login Mechanisms (Two-Factor + Recovery)
	* 15. Database Maintenance Mechanisms and Optimization Mechanisms as well as post content screening system
	*
	*/

require_once("../include/Constants.php");
class Retchid{

	private $DebugLine = 0;
	private $DebugHistory = array();
	private $SQLExecutionErrorCount = 0;
	private $SQLConnectionErrorCount = 0;
	private $SQLLastExecutionError = null;
	private $SQLLastConnectionError = null;
	public $DatabaseConnection = null;

	private $CurrentTime = null;
	private $RemoteAddress = null;

	public function __construct(){
		foreach(get_included_files() as $IncludedFile){
			if(preg_match('~Constants.php~', $IncludedFile)){
				$ConstantsLoaded = true;
				$this->CurrentTime = date("Y-m-d H:i:s");
				$this->RemoteAddress = getenv('HTTP_CLIENT_IP')?:
				getenv('HTTP_X_FORWARDED_FOR')?:
				getenv('HTTP_X_FORWARDED')?:
				getenv('HTTP_FORWARDED_FOR')?:
				getenv('HTTP_FORWARDED')?:
				getenv('REMOTE_ADDR');
			}
		}
		if(!isset($ConstantsLoaded)){
			print("Warning: constant file not loaded. Execution stopped.\n");
		}
	}

	private function StackTrace($DebugInformation){
		if(is_array($DebugInformation)){
			$this->OpenDatabaseConnection();
			$StackTraceQueryArray = array(
				0 => "INSERT INTO DebugInformation (TITLE,DESCRIPTION,ERCODE,CLIENT,ERID) VALUES (:TITLE1,:DESC1,:ERCODE1,:CLIENT1,:ERID"
				);
			$StackTrackValueArray = array(
				"TITLE" => $DebugInformation["TITLE"],
				"DESCRIPTION" => $DebugInformation["DESCRIPTION"],
				"ERCODE" => $DebugInformation["ERCODE"],
				"CLIENT" => $this->RemoteAddress
				);
			$this->IterateSQL($StackTraceQueryArray, $StackTrackValueArray);
			return true;
		}
		return fasle;
	}

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

	public function IterateSQL($SQLQuery,$SQLValues){
		if(!is_array($SQLValues)){
			$this->OpenDebugStream("Could not perform IterateSQL(), SQLValues must be array.");
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
							
				$ExecutionType = preg_match('~(INSERT)|(REPLACE)|(UPDATE)~', $QueryString)?1:
								 preg_match('~(SELECT)|(DELETE)~', $QueryString)?2;
				
				/*switch (preg_match('~(INSERT)|(REPLACE)|(UPDATE)~', $QueryString)) {
					case true:
						$ExecutionType = 1;
						break;
					
					default:
						$ExecutionType = 2;
						break;
				}*/
				preg_match_all('/([[:alpha:]]+,)|([[:alpha:]]+\))/',$QueryString,$ColName);
				$CorrectedColumnNames = preg_replace('/,|\)/', '', $ColName[0]);
				preg_match_all('~:[[:alnum:]]+~', $QueryString, $Parameters);
				try{
					$PreparedQuery = $this->DatabaseConnection->prepare($QueryString);
					for($Integer = 0; $Integer < count($Parameters[0]); $Integer++){
						$PreparedQuery->bindParam($Parameters[0][$Integer],$SQLValues[$ValueKey][$CorrectedColumnNames[$Integer]]);
					}
					$PreparedQuery->execute();
					$Return[$ValueKey]["ROWCOUNT"] = $PreparedQuery->rowCount();
					if($ExecutionType == 2){
						$OperationResults = $PreparedQuery->fetchAll(PDO::FETCH_ASSOC);
						if(isset($OperationResults[0])){
							$Return[$ValueKey]["RESULTS"] = $OperationResults[0];
						}
					}
				}catch(Exception $SQLOperationError){
					$this->OpenDebugStream($SQLOperationError);
					return false;
				}
				// $ValueKey = count($SQLValues)>1?$ValueKey++;
				if(count($SQLValues > 1)){
					$ValueKey++;
				}
			}
			return $Return;
		}
	}

	// Password Related Methods.

	public function ConvertPassword($PlainTextPassword, $Salt){
		if(empty($Salt) || $Salt == ""){
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

	public function CheckPassword ( $PlainTextPassword, $Username ){
		if(preg_match('/(@[[:alnum:]]+.+[[:alpha:]]+)/',$Username)){
			$Method = "EMAIL";
		}else{ $Method = "USERNAME"; }
		$SQLQueryArray =  array(
			0 => "SELECT * FROM Users WHERE ($Method) = (:VALUE1)"
			);
		$SQLValueArray = array(
			array( $Method => $Username )
			);
		$SQLOperationResults = $this->IterateSQL($SQLQueryArray,$SQLValueArray);
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
			$this->IterateSQL($UpdateIncorrectLoginCountQuery, $UpdateIncorrectLoginCountValues);
			return false;
		}
	}


	// User Creation and Deletion Methods 

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
		$SQLOperationResults = $this->IterateSQL($SQLQueryArray,$SQLQueryValues);
		if(isset($SQLOperationResults[0]) &&  $SQLOperationResults[0]["ROWCOUNT"] > 0){
			$this->OpenDebugStream("This user does in fact, exist.");
			return true;
		}
		return false;
	}

	public function CreateNewUser($UserCreationArray){
		if($this->CheckUserExistence((string)$UserCreationArray["EMAIL"])){
			return false;
		}elseif($this->CheckUserExistence((string)$UserCreationArray["USERNAME"])){
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
		$ExecuteCreation = $this->IterateSQL($UserCreationQueries,$PassThroughArray);
		if(isset($ExecuteCreation[0])){
			return true;
		}
		return false;

		// Use session and cookies to pass username, use unique ID.
		public function PermanentlyDeleteUser($Username,$PlainTextPassword){
			if($this->CheckPassword($PlainTextPassword,$Username)){
					$DeleteQuery = array(
						0 => "DELETE FROM Users WHERE (UNIQUEID) = :UNIQUEID1",
						1 => "DELETE FROM UsersInformation WHERE (UNIQUEID) = :UNIQUEID1"
						);
					$DeleteValues = array(
						array("UNIQUEID" => $Username),
						array("UNIQUEID" => $Username)
						);
			}else{
				// username and password don't match
				return false;
			}
		}
	}

	// User Information Modification Methods

	// User Informtion Grabbing Methods

	// User Profile Information 

	// Friends and Relationship Management Methods

	// Posts and Post Comments Methods

	// Sorting Algorithms 

	// File Management Methods

	// Bot Detection Mechanisms

	// Search Engine Optimization for Dy-Static Pages

	// Message System

	// GPG and PGP Extension for Message System

	// Message and Post PKI Extension..........let's see how this goes.

	// One-Time Login Extension (SMS and Email Codes)





}

$Object = new Retchid;



?>